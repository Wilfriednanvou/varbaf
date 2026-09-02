#!/usr/bin/env python3
"""
Extraction du parc locatif depuis l'etat de recouvrement du VARBAF.

Source : feuille « LISTE OCCUPANTS » de la liste des produits artisanaux,
qui porte en realite l'etat de recouvrement des recettes de l'annee 2026.

## Pourquoi ce script plutot qu'une saisie

La transcription manuelle precedente avait omis un occupant entier —
MAKAMTE Bibiane, boutique B13 — ce qui retirait 80 000 FCFA d'imputation,
30 000 de paye et 50 000 de reste aux totaux. L'ecart de 154 000 FCFA
que le rapport presentait comme « interne au releve » etait en realite
la somme de cette omission et de deux lignes que le document lui-meme
laisse incompletes. Une extraction mecanique ne peut pas oublier une
ligne.

## Ce que le script conserve et que la premiere version perdait

**Le detail mensuel.** Le releve porte douze colonnes de versements, une
par mois, puis trois colonnes de synthese annuelle. Les deux ne
concordent pas toujours : sur trois lignes, un versement inscrit au mois
n'est pas repris dans le total annuel, pour 110 000 FCFA au total. La
premiere transcription ne gardait que la synthese, ce qui rendait
l'ecart indetectable.

Le fichier produit porte donc les deux : `paye_2026` est ce que le
document declare, `paye_mensuel_2026` est la somme de ses propres
colonnes mensuelles. Quand les deux different, la ligne porte
`ecart_paye`. Un lecteur peut ainsi voir ce que le document dit et ce que
son detail montre, sans qu'aucune des deux valeurs ne soit corrigee
d'autorite : trancher lequel fait foi releve de la coordination, pas d'un
script.

Usage :
    python3 extraire-parc.py [classeur.xlsx] [sortie.csv]
"""

import csv
import hashlib
import sys
from pathlib import Path

try:
    import openpyxl
except ImportError:
    sys.exit("Ce script requiert openpyxl :  pip install openpyxl")

RACINE = Path(__file__).parent
SOURCE = Path(sys.argv[1]) if len(sys.argv) > 1 else RACINE / 'source' / 'liste-produits-artisanaux-2026.xlsx'
SORTIE = Path(sys.argv[2]) if len(sys.argv) > 2 else RACINE / 'parc-locatif.csv'

FEUILLE = 'LISTE OCCUPANTS'
PREMIERE, DERNIERE = 12, 51      # la ligne 52 porte les totaux du document
MOIS = list(range(6, 18))        # les douze colonnes de versement mensuel


def entier(valeur):
    return int(round(valeur)) if isinstance(valeur, (int, float)) else 0


def texte(valeur):
    return '' if valeur is None else str(valeur).strip()


def extraire(source: Path):
    feuille = openpyxl.load_workbook(source, data_only=True)[FEUILLE]

    lignes, vides = [], 0
    contenant, nature = '', ''

    for numero, brut in enumerate(feuille.iter_rows(min_row=PREMIERE, max_row=DERNIERE, values_only=True), PREMIERE):
        occupant = texte(brut[4])

        # Le releve fusionne les cellules de type et de contenant sur la
        # premiere ligne de chaque boutique : les suivantes en heritent.
        if texte(brut[1]):
            nature = texte(brut[1])
        if texte(brut[2]):
            contenant = texte(brut[2])

        # « VIDE » ou une cellule blanche : espace non attribue.
        if not occupant or occupant.upper() == 'VIDE':
            vides += 1
            continue

        mensuel = sum(entier(brut[i]) for i in MOIS)
        declare = entier(brut[19])

        lignes.append({
            'ligne_source': numero,
            'contenant': contenant,
            'nature': nature or 'BOUTIQUE',
            'espace': texte(brut[3]),
            'occupant': occupant,
            'metier': texte(brut[23]),
            'redevance': entier(brut[5]),
            'du_2026': entier(brut[18]),
            'paye_2026': declare,
            'paye_mensuel_2026': mensuel,
            'ecart_paye': mensuel - declare,
            'reste_2026': entier(brut[20]),
        })

    return lignes, vides


def main():
    if not SOURCE.exists():
        sys.exit(f"Classeur introuvable : {SOURCE}")

    lignes, vides = extraire(SOURCE)

    with open(SORTIE, 'w', newline='', encoding='utf-8') as fichier:
        redacteur = csv.DictWriter(fichier, fieldnames=list(lignes[0].keys()), delimiter=';')
        redacteur.writeheader()
        redacteur.writerows(lignes)

    du = sum(l['du_2026'] for l in lignes)
    paye = sum(l['paye_2026'] for l in lignes)
    mensuel = sum(l['paye_mensuel_2026'] for l in lignes)
    reste = sum(l['reste_2026'] for l in lignes)

    print(f"Classeur          : {SOURCE.name}")
    print(f"Empreinte SHA-256 : {hashlib.sha256(SOURCE.read_bytes()).hexdigest()}")
    print(f"Sortie            : {SORTIE.name}")
    print()
    print(f"Occupants         : {len(lignes)}")
    print(f"Espaces non attribues : {vides}")
    print()
    print(f"Imputation annuelle          : {du:>10,} FCFA".replace(',', ' '))
    print(f"Paye, selon la synthese      : {paye:>10,} FCFA".replace(',', ' '))
    print(f"Paye, selon le detail mensuel: {mensuel:>10,} FCFA".replace(',', ' '))
    print(f"Reste a payer                : {reste:>10,} FCFA".replace(',', ' '))
    print()
    print(f"Taux de recouvrement, synthese : {100*paye/du:5.2f} %")
    print(f"Taux de recouvrement, mensuel  : {100*mensuel/du:5.2f} %")

    ecarts = [l for l in lignes if l['ecart_paye']]
    if ecarts:
        print()
        print(f"Lignes dont le detail mensuel ne concorde pas avec la synthese : {len(ecarts)}")
        for l in ecarts:
            print(f"   {l['espace'] or '(sans code)':10s} {l['occupant'][:32]:32s} "
                  f"mensuel {l['paye_mensuel_2026']:>7,} vs declare {l['paye_2026']:>7,} "
                  f"= {l['ecart_paye']:+,}".replace(',', ' '))

    incoherents = [l for l in lignes if l['du_2026'] and l['paye_2026'] + l['reste_2026'] != l['du_2026']]
    if incoherents:
        print()
        print(f"Lignes ou paye + reste != imputation : {len(incoherents)}")
        for l in incoherents:
            print(f"   {l['espace'] or '(sans code)':10s} {l['occupant'][:32]:32s} "
                  f"{l['paye_2026']} + {l['reste_2026']} != {l['du_2026']}")

    sans_code = [l for l in lignes if not l['espace']]
    if sans_code:
        print()
        print(f"Occupants sans code d'espace : {len(sans_code)}")
        for l in sans_code:
            print(f"   contenant {l['contenant']:6s} {l['occupant'][:40]}")


if __name__ == '__main__':
    main()
