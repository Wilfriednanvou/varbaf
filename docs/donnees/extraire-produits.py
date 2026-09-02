#!/usr/bin/env python3
"""
Extraction de l'inventaire des produits artisanaux du VARBAF.

Source : feuille « Depot produits » de la liste des produits artisanaux.

## Ce que le support impose

**Le nom de l'artisan n'est pas repete.** Il est inscrit sur la premiere
ligne de son lot, les suivantes en heritent, exactement comme la date du
registre des ventes. Le report est donc explicite, et la colonne
`artisan_lu` indique pour chaque ligne si le nom a ete lu ou herite.

**La feuille annonce plus qu'elle ne porte.** Elle declare dix-neuf
colonnes de caracterisation — materiaux, dimensions, provenance, commune,
departement — dont plusieurs ne sont renseignees sur aucune ligne. Le
script les extrait quand meme et rapporte leur taux de remplissage : une
colonne vide est un constat sur l'etat du catalogue, pas une donnee a
taire.

Usage :
    python3 extraire-produits.py [classeur.xlsx] [sortie.csv]
"""

import csv
import hashlib
import sys
from collections import Counter
from pathlib import Path

try:
    import openpyxl
except ImportError:
    sys.exit("Ce script requiert openpyxl :  pip install openpyxl")

RACINE = Path(__file__).parent
SOURCE = Path(sys.argv[1]) if len(sys.argv) > 1 else RACINE / 'source' / 'liste-produits-artisanaux-2026.xlsx'
SORTIE = Path(sys.argv[2]) if len(sys.argv) > 2 else RACINE / 'produits.csv'

FEUILLE = 'Dépôt produits'
PREMIERE, DERNIERE = 17, 228

COLONNES = ['numero', 'produit', 'secteur', 'provenance', 'materiaux', 'hauteur',
            'largeur', 'poids', 'capacite', 'longueur', 'perissable', 'date_fabrication',
            'peremption', 'quantite', 'prix_unitaire', 'artisan', 'contact',
            'commune', 'departement']

# Le contact est un numero de telephone personnel : il n'est pas extrait.
EXCLUES = {'contact', 'numero'}


def entier(valeur):
    return int(round(valeur)) if isinstance(valeur, (int, float)) else ''


def texte(valeur):
    return '' if valeur is None else str(valeur).strip()


def extraire(source: Path):
    feuille = openpyxl.load_workbook(source, data_only=True)[FEUILLE]

    lignes, dernier = [], ''
    for numero, brut in enumerate(feuille.iter_rows(min_row=PREMIERE, max_row=DERNIERE, values_only=True), PREMIERE):
        donnees = dict(zip(COLONNES, [texte(v) for v in brut[:len(COLONNES)]]))

        if not donnees['produit'] and brut[14] is None:
            continue

        artisan_lu = bool(donnees['artisan'])
        if artisan_lu:
            dernier = donnees['artisan']

        ligne = {'ligne_source': numero}
        ligne.update({c: donnees[c] for c in COLONNES if c not in EXCLUES})
        ligne['quantite'] = entier(brut[13])
        ligne['prix_unitaire'] = entier(brut[14])
        ligne['artisan'] = dernier
        ligne['artisan_lu'] = 'oui' if artisan_lu else 'non'
        lignes.append(ligne)

    return lignes


def main():
    if not SOURCE.exists():
        sys.exit(f"Classeur introuvable : {SOURCE}")

    lignes = extraire(SOURCE)

    with open(SORTIE, 'w', newline='', encoding='utf-8') as fichier:
        redacteur = csv.DictWriter(fichier, fieldnames=list(lignes[0].keys()), delimiter=';')
        redacteur.writeheader()
        redacteur.writerows(lignes)

    print(f"Classeur          : {SOURCE.name}")
    print(f"Empreinte SHA-256 : {hashlib.sha256(SOURCE.read_bytes()).hexdigest()}")
    print(f"Sortie            : {SORTIE.name}")
    print()
    print(f"Produits          : {len(lignes)}")
    print(f"Artisans distincts: {len({l['artisan'] for l in lignes if l['artisan']})}")
    print(f"Noms herites      : {sum(1 for l in lignes if l['artisan_lu'] == 'non')}")

    prix = [l['prix_unitaire'] for l in lignes if l['prix_unitaire'] != '']
    quantites = [l['quantite'] for l in lignes if l['quantite'] != '']
    if prix:
        print(f"Prix unitaires    : {min(prix)} a {max(prix)} FCFA, mediane {sorted(prix)[len(prix)//2]}")
    if quantites:
        print(f"Quantite deposee  : {sum(quantites)} articles")

    print()
    print("Taux de remplissage des colonnes de caracterisation :")
    for colonne in ['produit', 'prix_unitaire', 'quantite', 'artisan', 'capacite',
                    'secteur', 'provenance', 'materiaux', 'commune', 'departement']:
        remplies = sum(1 for l in lignes if l.get(colonne) not in ('', None))
        etat = '' if remplies else '   <- jamais renseignee'
        print(f"   {colonne:16s} {remplies:4d} / {len(lignes)}  ({100*remplies/len(lignes):5.1f} %){etat}")

    print()
    print("Produits par artisan :")
    for artisan, nombre in Counter(l['artisan'] for l in lignes if l['artisan']).most_common():
        print(f"   {nombre:4d}  {artisan[:46]}")


if __name__ == '__main__':
    main()
