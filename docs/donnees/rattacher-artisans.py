#!/usr/bin/env python3
"""
Rattachement des ecritures du registre aux occupants du parc locatif.

Le registre porte le nom de l'artisan tel qu'il est prononce au comptoir ;
le parc porte le nom officiel de l'occupant. Rapprocher les deux est un
travail de resolution d'entites, et ce script en fixe la regle.

## Ce qui a change par rapport a la premiere version

La premiere table de correspondance rattachait sur un seul mot, et
traitait le meme patronyme de deux facons opposees : « Ngassam Crousti »
etait rattache a NGASSAM Bernadette, « Ngassam Olivier » ne l'etait pas,
au meme score. Elle comptait en outre comme artisans des entites qui n'en
sont pas : « Hall » et « osplame salle innovation » sont des espaces du
village, et Mme Guessong un agent du village qui traite les decharges
pour le compte des artisans. La coordination a confirme les trois.

## La regle

**Trois issues, pas deux.** Un rapprochement automatique qui tranche
toujours produit des faux rattachements silencieux ; un rapprochement qui
n'ose jamais ne sert a rien. La regle distingue donc ce qu'elle etablit,
ce qu'elle refuse, et ce qu'elle ne peut pas decider seule.

**Ce n'est pas le nombre de mots communs qui etablit une identite, c'est
leur pouvoir de designation.** « MBIAKOP » ne partage qu'un mot avec
« Bambou House (MBIAKOP Roland) », et pourtant l'identite ne fait aucun
doute : ce mot ne designe qu'un seul occupant du parc. « Mme Sidonie »
partage aussi un seul mot, mais deux occupantes se prenomment Sidonie :
le meme score recouvre une certitude et une ambiguite. Le critere retenu
est donc l'unicite du mot dans le parc, et non son nombre.

**Un mot supplementaire qui contredit interdit le rattachement.**
« Ngassam Olivier » partage « ngassam » avec NGASSAM Bernadette, et ce
mot est unique au parc — mais l'ecriture porte « olivier », que le nom de
l'occupante ne contient pas. Un prenom different sur un patronyme commun
designe une autre personne bien plus souvent qu'une variante d'ecriture.
Le cas part donc a l'arbitrage, et « Ngassam Crousti » avec lui : le meme
patronyme recoit desormais le meme traitement.

Les trois issues :

- **RATTACHE** — deux mots distinctifs communs, ou une similarite de
  chaine d'au moins 0,85, ou un mot commun unique au parc sans mot
  contradictoire. L'identite est etablie.
- **A ARBITRER** — un mot commun partage par plusieurs occupants, ou
  accompagne d'un mot que l'occupant ne porte pas. Ces cas sont listes
  pour decision humaine, jamais tranches ici.
- **SANS CORRESPONDANCE** — rien de commun. L'ecriture designe un
  deposant non installe, et la vente reste rattachee a son nom.

**Le cout des deux erreurs n'est pas symetrique**, et c'est ce qui fixe
le curseur. Rattacher a tort revient a verser a un artisan les ventes
d'un autre : l'erreur est invisible et se paie en argent. Refuser un
rattachement juste produit un doublon visible, qu'un agent corrige. Le
seuil est donc regle du cote ou l'erreur se voit.

**Les non-personnes sont declarees, pas devinees.** Aucun algorithme ne
peut savoir que « Hall » est un emplacement. La liste est donc explicite,
chaque entree porte son motif, et elle figure dans le fichier produit :
une exclusion qu'on ne peut pas relire est une donnee perdue.

Usage :
    python3 rattacher-artisans.py [registre.csv] [parc-locatif.csv] [sortie.csv]
"""

import csv
import difflib
import re
import sys
import unicodedata
from collections import defaultdict
from pathlib import Path

RACINE = Path(__file__).parent
REGISTRE = Path(sys.argv[1]) if len(sys.argv) > 1 else RACINE / 'registre.csv'
PARC = Path(sys.argv[2]) if len(sys.argv) > 2 else RACINE / 'parc-locatif.csv'
SORTIE = Path(sys.argv[3]) if len(sys.argv) > 3 else RACINE / 'rattachements.csv'

SEUIL_CHAINE = 0.85          # similarite de chaine suffisant a elle seule
LONGUEUR_DISTINCTIVE = 4     # en deca, un mot ne distingue rien

# Mots qui n'identifient personne. Trois familles s'y melent, et la
# derniere est propre a ce registre : la colonne « artisan » y recoit
# parfois un fragment d'observation — « Mme GUESSONG(paye le 01/09/25) »
# — ou le verbe de la decharge deborde sur le nom. Ces mots sont ecartes
# avant tout rapprochement, sans quoi ils se comportent comme des
# patronymes.
MOTS_NEUTRES = {
    # titres et civilites
    'mme', 'mr', 'm', 'monsieur', 'madame', 'sieur',
    # formes juridiques et commerciales
    'ets', 'epse', 'eps', 'ep', 'sarl', 'scoops', 'gic', 'house',
    # liaisons
    'et', 'de', 'du', 'des', 'la', 'le', 'les', 'chez',
    # vocabulaire de la decharge, deborde de la colonne observation
    'paye', 'payee', 'verse', 'versee', 'vendu', 'vendue', 'recu', 'solde',
}

# Ecritures du registre qui ne designent pas un artisan.
# Chaque entree porte le motif qui justifie l'exclusion.
NON_ARTISANS = {
    # Confirmes par la coordination : ce sont des espaces du village.
    # Le registre lui-meme l'atteste pour le premier, dont l'observation
    # porte « Artisan inconnu » : le lieu a ete inscrit faute de savoir
    # qui deposait.
    'hall': "Espace du village, pas une personne",
    'osplame salle innovation': "Espace du village, pas une personne",
    'varbamenda': "Village artisanal de Bamenda : acheteur institutionnel, pas un occupant",
    'var bamenda': "Village artisanal de Bamenda : acheteur institutionnel, pas un occupant",
}

# Agents du village, confirmes par la coordination. Ils ne sont pas des
# artisans : ils traitent les decharges pour le compte de ceux-ci.
#
# **Leur nom n'est pas une exclusion, c'est un parasite.** Il apparait de
# deux facons dans le registre. Seul dans la colonne artisan, il designe
# un agent et la ligne ne peut etre reversee a personne. Accole a un nom
# d'artisan — « Guy Marcel(Guessong) » — il indique seulement qui a remis
# les fonds, et la vente revient bien a l'artisan nomme.
#
# Traiter les deux cas de la meme facon coute une vente a son
# beneficiaire legitime. Le nom de l'agent est donc retire de l'ecriture
# avant tout rapprochement, et ce n'est que s'il ne reste rien qu'on
# conclut a un agent.
AGENTS_DU_VILLAGE = {
    'guessong': "Mme Guessong, agent du village",
}


def sans_accent(chaine):
    return unicodedata.normalize('NFKD', chaine).encode('ascii', 'ignore').decode()


def normaliser(nom):
    """Forme canonique d'un nom. Le contenu des parentheses est conserve :
    c'est souvent la qu'est le patronyme, comme dans « Bambou House (MBIAKOP Roland) »."""
    nom = sans_accent(str(nom or '')).lower()
    nom = re.sub(r'[^a-z0-9 ]', ' ', nom)
    return ' '.join(nom.split())


def mots_distinctifs(nom_normalise):
    return {
        mot for mot in nom_normalise.split()
        if len(mot) >= LONGUEUR_DISTINCTIVE and mot not in MOTS_NEUTRES
    }


def est_non_artisan(nom_normalise):
    for cle, motif in NON_ARTISANS.items():
        if cle in nom_normalise or nom_normalise in cle:
            return motif
    return None


def charger_parc(chemin):
    occupants = []
    with open(chemin, encoding='utf-8-sig') as fichier:
        for ligne in csv.DictReader(fichier, delimiter=';'):
            nom = (ligne.get('occupant') or '').strip()
            if not nom:
                continue
            norme = normaliser(nom)
            occupants.append({
                'espace': ligne['espace'],
                'nom': nom,
                'norme': norme,
                'mots': mots_distinctifs(norme),
            })
    return occupants


def retirer_agents(mots):
    """Retire les noms d'agents du village. Rend (mots restants, agents retires)."""
    agents = {m for m in mots if m in AGENTS_DU_VILLAGE}
    return mots - agents, agents


def rapprocher(ecriture, occupants):
    """Rend (occupant, decision, score, motif) pour une ecriture du registre."""
    norme = normaliser(ecriture)

    motif = est_non_artisan(norme)
    if motif:
        return None, 'NON ARTISAN', 0.0, motif

    mots = mots_distinctifs(norme)
    mots, agents = retirer_agents(mots)

    # L'ecriture ne portait que le nom d'un agent : la vente n'a pas de
    # beneficiaire identifiable dans le registre.
    if agents and not mots:
        qui = ', '.join(AGENTS_DU_VILLAGE[a] for a in sorted(agents))
        return None, 'NON ARTISAN', 0.0, \
            f"{qui} : l'ecriture ne porte aucun nom d'artisan"

    mention_agent = f" (nom d'agent retire : {', '.join(sorted(agents))})" if agents else ''

    if not mots:
        return None, 'SANS CORRESPONDANCE', 0.0, "Aucun mot distinctif dans l'ecriture"

    meilleur, meilleur_score, meilleurs_communs = None, 0.0, set()
    for occupant in occupants:
        communs = mots & occupant['mots']
        chaine = difflib.SequenceMatcher(None, norme, occupant['norme']).ratio()
        score = max(chaine, 0.5 + 0.25 * len(communs))
        if communs or chaine >= SEUIL_CHAINE:
            if score > meilleur_score:
                meilleur, meilleur_score, meilleurs_communs = occupant, score, communs

    if meilleur is None:
        return None, 'SANS CORRESPONDANCE', 0.0, "Aucun occupant ne partage de mot distinctif"

    chaine = difflib.SequenceMatcher(None, norme, meilleur['norme']).ratio()
    communs = ', '.join(sorted(meilleurs_communs))

    if len(meilleurs_communs) >= 2 or chaine >= SEUIL_CHAINE:
        return meilleur, 'RATTACHE', round(chaine, 3), \
            f"Identite etablie : {len(meilleurs_communs)} mot(s) commun(s) ({communs}), similarite {chaine:.2f}{mention_agent}"

    # Un seul mot commun : il faut qu'il designe un occupant et un seul,
    # et que l'ecriture ne porte aucun mot que cet occupant ne porte pas.
    if len(meilleurs_communs) == 1:
        mot = next(iter(meilleurs_communs))
        porteurs = [o for o in occupants if mot in o['mots']]
        contradictoires = mots - meilleur['mots']

        if len(porteurs) > 1:
            noms = ' / '.join(o['nom'][:24] for o in porteurs)
            return meilleur, 'A ARBITRER', round(chaine, 3), \
                f"Le mot « {mot} » designe {len(porteurs)} occupants ({noms}) : il n'identifie personne"

        if contradictoires:
            return meilleur, 'A ARBITRER', round(chaine, 3), \
                f"Mot commun « {mot} » unique au parc, mais l'ecriture porte « {', '.join(sorted(contradictoires))} » " \
                f"que l'occupant ne porte pas : probablement une autre personne"

        return meilleur, 'RATTACHE', round(chaine, 3), \
            f"Identite etablie : le mot « {mot} » ne designe que cet occupant, et rien ne le contredit{mention_agent}"

    return meilleur, 'A ARBITRER', round(chaine, 3), \
        f"Rapprochement trop faible (similarite {chaine:.2f})"


def main():
    for chemin in (REGISTRE, PARC):
        if not chemin.exists():
            sys.exit(f"Fichier introuvable : {chemin}")

    occupants = charger_parc(PARC)

    # Un parc vide n'est pas un parc sans occupant : c'est un fichier pas
    # encore rempli. Sans ce refus, le script rendrait « aucune
    # correspondance » pour la totalite du registre — un resultat qui a
    # l'air d'un resultat et n'en est pas.
    if not occupants:
        sys.exit(
            f"Le parc locatif est vide : {PARC.name} ne contient aucun occupant.\n"
            "Ce fichier se ressaisit depuis le releve de recouvrement du village ;\n"
            "aucun script ne peut le reconstruire, le classeur ne portant pas les\n"
            "redevances. Le rattachement est impossible tant qu'il n'est pas rempli."
        )

    ventes, montants = defaultdict(int), defaultdict(int)
    with open(REGISTRE, encoding='utf-8-sig') as fichier:
        for ligne in csv.DictReader(fichier, delimiter=';'):
            nom = (ligne.get('artisan') or '').strip()
            if not nom:
                continue
            ventes[nom] += 1
            try:
                montants[nom] += int(ligne['montant'] or 0)
            except ValueError:
                pass

    resultats = []
    for nom in sorted(ventes, key=lambda n: -montants[n]):
        occupant, decision, score, motif = rapprocher(nom, occupants)
        resultats.append({
            'ecriture_registre': nom,
            'nb_ventes': ventes[nom],
            'total_fcfa': montants[nom],
            'decision': decision,
            'espace_locatif': occupant['espace'] if occupant else '',
            'occupant_parc': occupant['nom'] if occupant else '',
            'similarite': score,
            'motif': motif,
        })

    with open(SORTIE, 'w', newline='', encoding='utf-8') as fichier:
        redacteur = csv.DictWriter(fichier, fieldnames=list(resultats[0].keys()), delimiter=';')
        redacteur.writeheader()
        redacteur.writerows(resultats)

    total = sum(r['total_fcfa'] for r in resultats)
    print(f"Ecritures distinctes : {len(resultats)}")
    print(f"Chiffre d'affaires   : {total:,} FCFA".replace(',', ' '))
    print()
    for decision in ('RATTACHE', 'A ARBITRER', 'SANS CORRESPONDANCE', 'NON ARTISAN'):
        lot = [r for r in resultats if r['decision'] == decision]
        if not lot:
            continue
        montant = sum(r['total_fcfa'] for r in lot)
        print(f"{decision:20s} {len(lot):3d} ecritures  {montant:>9,} FCFA  ({100*montant/total:4.1f} %)".replace(',', ' '))
    print()
    a_arbitrer = [r for r in resultats if r['decision'] == 'A ARBITRER']
    if a_arbitrer:
        print("A soumettre a la coordination :")
        for r in a_arbitrer:
            print(f"   {r['total_fcfa']:>8,} F  {r['ecriture_registre'][:30]:30s} -> {r['occupant_parc'][:34]} ?".replace(',', ' '))


if __name__ == '__main__':
    main()
