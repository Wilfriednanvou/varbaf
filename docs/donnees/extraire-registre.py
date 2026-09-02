#!/usr/bin/env python3
"""
Extraction du registre des ventes depuis le classeur du VARBAF.

**Aucune valeur n'est saisie a la main.** Tout est lu dans la feuille
« VENTES » du classeur place dans `source/`. Le script est rejouable :
pour un classeur donne, il produit toujours le meme fichier, ce qui
permet de rejouer la reprise apres correction du classeur sans repasser
par une transcription.

Deux proprietes du support imposent un traitement, et elles sont la
raison d'etre de ce script plutot que d'un export brut.

**La colonne des dates porte aussi les sous-totaux.** Le registre y
inscrit « TOTAL AOUT 24 » entre deux mois. Une lecture naive compte ces
lignes comme des ventes et double les montants du mois. Elles sont
reconnues et ecartees, et leur nombre est rapporte.

**La date n'est pas repetee a chaque ligne.** Le registre la porte une
fois par journee, les lignes suivantes en heritent. Le report est donc
explicite, et chaque ligne indique si sa date a ete lue ou heritee : un
lecteur peut ainsi distinguer ce que le registre dit de ce que le
traitement a deduit.

Usage :
    python3 extraire-registre.py [classeur.xlsx] [sortie.csv]
"""

import csv
import datetime
import hashlib
import sys
from pathlib import Path

try:
    import openpyxl
except ImportError:
    sys.exit("Ce script requiert openpyxl :  pip install openpyxl")

RACINE = Path(__file__).parent
SOURCE = Path(sys.argv[1]) if len(sys.argv) > 1 else RACINE / 'source' / 'etat-des-ventes-varbaf-20260225.xlsx'
SORTIE = Path(sys.argv[2]) if len(sys.argv) > 2 else RACINE / 'registre.csv'

# Les sept colonnes utiles de la feuille VENTES, dans l'ordre du classeur.
PREMIERE_LIGNE = 4   # les trois premieres portent le titre et les en-tetes


def entier(valeur):
    """Un montant du registre est un entier de francs CFA : le FCFA n'a pas de subdivision."""
    if isinstance(valeur, (int, float)):
        return int(round(valeur))
    return None


def texte(valeur):
    return '' if valeur is None else str(valeur).strip()


def extraire(source: Path):
    classeur = openpyxl.load_workbook(source, data_only=True)
    feuille = classeur['VENTES']

    lignes = []
    derniere_date = None
    sous_totaux = 0
    vides = 0

    for numero, brut in enumerate(feuille.iter_rows(min_row=PREMIERE_LIGNE, values_only=True), start=PREMIERE_LIGNE):
        mois, designation, montant, artisan, type_artisanat, observation, reste = brut[:7]

        # Ligne de synthese : elle partage la colonne des dates.
        if mois is not None and texte(mois).upper().startswith('TOTAL'):
            sous_totaux += 1
            continue

        # Report de date : le registre ne la repete pas a chaque ligne.
        if isinstance(mois, datetime.datetime):
            derniere_date = mois
            date_lue = True
        else:
            date_lue = False

        if designation is None and montant is None:
            vides += 1
            continue

        lignes.append({
            'ligne_source': numero,
            'date': derniere_date.strftime('%Y-%m-%d') if derniere_date else '',
            'date_lue': 'oui' if date_lue else 'non',
            'designation': texte(designation),
            'montant': entier(montant) if entier(montant) is not None else '',
            'artisan': texte(artisan),
            'type_artisanat': texte(type_artisanat),
            'observation': texte(observation),
            'reste_a_payer': entier(reste) if entier(reste) is not None else 0,
        })

    return lignes, sous_totaux, vides


def main():
    if not SOURCE.exists():
        sys.exit(f"Classeur introuvable : {SOURCE}")

    lignes, sous_totaux, vides = extraire(SOURCE)

    with open(SORTIE, 'w', newline='', encoding='utf-8') as fichier:
        redacteur = csv.DictWriter(fichier, fieldnames=list(lignes[0].keys()), delimiter=';')
        redacteur.writeheader()
        redacteur.writerows(lignes)

    empreinte = hashlib.sha256(SOURCE.read_bytes()).hexdigest()
    total = sum(l['montant'] for l in lignes if l['montant'] != '')
    reste = sum(l['reste_a_payer'] for l in lignes)
    reportees = sum(1 for l in lignes if l['date_lue'] == 'non')

    print(f"Classeur              : {SOURCE.name}")
    print(f"Empreinte SHA-256     : {empreinte}")
    print(f"Sortie                : {SORTIE.name}")
    print()
    print(f"Lignes de vente       : {len(lignes)}")
    print(f"  dont date reportee  : {reportees}")
    print(f"Sous-totaux ecartes   : {sous_totaux}")
    print(f"Lignes vides ecartees : {vides}")
    print()
    print(f"Chiffre d'affaires    : {total:,} FCFA".replace(',', ' '))
    print(f"Reste a payer         : {reste:,} FCFA".replace(',', ' '))


if __name__ == '__main__':
    main()
