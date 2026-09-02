# Données de reprise du VARBAF

Ce dossier a été **entièrement reconstruit le 2 septembre 2026**. Les
fichiers antérieurs ont été supprimés à la demande de la coordination du
projet. Ils restent récupérables dans l'historique Git au commit
`08b4647` :

```bash
git show 08b4647:docs/donnees/parc-locatif.csv > /tmp/ancien-parc.csv
```

---

## Le principe

**Une seule source, et tout le reste en dérive.** L'erreur de la première
version était de mélanger des fichiers saisis à la main et des fichiers
produits par traitement, sans que rien ne dise lequel était lequel. Un
fichier corrigé à la main devenait alors impossible à régénérer, et un
fichier régénéré écrasait des corrections.

La règle est désormais la suivante :

| Type | Fichiers | Peut-on l'éditer à la main ? |
|---|---|---|
| **Source** | `source/*.xlsx` | Non — ce sont les documents du village, tels qu'ils sont |
| **Script** | `*.py` | Oui — c'est la règle de traitement |
| **Produit** | `registre.csv`, `parc-locatif.csv`, `produits.csv`, `rattachements.csv` | **Non** — toute édition sera écrasée |

Un fichier produit ne se corrige jamais à la main. Si sa valeur est
fausse, c'est la source ou le script qui est en cause, et c'est là que la
correction se fait. Cette discipline est ce qui rend la reprise
rejouable : à tout moment, effacer les fichiers produits et relancer les
scripts doit redonner exactement le même résultat.

---

## Les fichiers

### Sources

| Fichier | Contenu |
|---|---|
| `source/etat-des-ventes-varbaf-20260225.xlsx` | Ventes, commissions, caisse, état de reversement, arrêté au 25/02/2026 |
| `source/liste-produits-artisanaux-2026.xlsx` | Inventaire des produits **et** état de recouvrement des recettes 2026 |

Empreintes SHA-256, rappelées à chaque exécution des scripts. Si l'une
change, c'est que le classeur a été modifié et que les chiffres du
rapport doivent être régénérés :

```
etat-des-ventes      a3a98a41b91fdab3df5014bd6bc4244b94962c97b21c53b09642d216122ac742
liste-produits       3f5c7f7a1f515cd3eb829652702a3946827b71839f15056586c49db991e695a1
```

Le second fichier porte un nom trompeur : sa feuille « LISTE OCCUPANTS »
n'est pas une liste d'occupants mais **l'état de recouvrement des
recettes 2026**, avec le détail mensuel des versements. C'est la source
des redevances, que rien d'autre ne porte.

### Scripts

| Script | Ce qu'il fait |
|---|---|
| `extraire-registre.py` | Feuille *Ventes* → `registre.csv` |
| `extraire-parc.py` | Feuille *Liste occupants* → `parc-locatif.csv` |
| `extraire-produits.py` | Feuille *Dépôt produits* → `produits.csv` |
| `rattacher-artisans.py` | `registre.csv` + `parc-locatif.csv` → `rattachements.csv` |

```bash
python3 extraire-registre.py
python3 extraire-parc.py
python3 extraire-produits.py
python3 rattacher-artisans.py     # a besoin des deux premiers
```

Les scripts n'ont qu'une dépendance, `openpyxl`. Aucun n'extrait les
numéros de téléphone, présents dans les deux sources.

### Produits

| Fichier | Contenu |
|---|---|
| `registre.csv` | 603 lignes de vente |
| `parc-locatif.csv` | 36 occupants, redevances et recouvrement 2026 |
| `produits.csv` | 134 produits de 14 artisans |
| `rattachements.csv` | Une ligne par écriture d'artisan, avec sa décision et son motif |

---

## Ce que l'extraction du registre traite

Deux propriétés du classeur imposent un traitement, et c'est la raison
d'être du script plutôt que d'un export brut.

**La colonne des dates porte aussi les sous-totaux.** Le registre y
inscrit « TOTAL AOUT 24 » entre deux mois. Une lecture naïve compte ces
13 lignes comme des ventes et double les montants du mois. Elles sont
reconnues et écartées.

**La date n'est pas répétée à chaque ligne.** Le registre la porte une
fois par journée ; 100 lignes en héritent. Le report est explicite, et la
colonne `date_lue` indique pour chaque ligne si sa date a été lue dans le
classeur ou héritée de la précédente — ce qui permet de distinguer ce que
le registre dit de ce que le traitement a déduit.

Résultat de l'extraction : **603 lignes**, **2 021 350 FCFA** de chiffre
d'affaires, **234 850 FCFA** de reste à payer.

---

## Ce que le rattachement décide, et ce qu'il refuse de décider

Le registre porte le nom de l'artisan tel qu'il est prononcé au
comptoir ; le parc porte le nom officiel de l'occupant. Les rapprocher
est un travail de résolution d'entités.

**La première version rattachait sur un seul mot, et de façon
incohérente** : « Ngassam Crousti » était rattaché à NGASSAM Bernadette,
« Ngassam Olivier » ne l'était pas, au même score. Elle comptait en outre
comme artisans des entités qui n'en sont pas — « Hall » et « osplame
salle innovation » sont des espaces du village, et Mme Guessong un agent
du village. La coordination a confirmé les trois.

La règle actuelle repose sur quatre principes.

**Ce n'est pas le nombre de mots communs qui établit une identité, c'est
leur pouvoir de désignation.** « MBIAKOP » ne partage qu'un mot avec
« Bambou House (MBIAKOP Roland) », et pourtant l'identité ne fait aucun
doute : ce mot ne désigne qu'un seul occupant. « Mme Sidonie » partage
aussi un seul mot, mais deux occupantes se prénomment Sidonie. Le même
score recouvre une certitude et une ambiguïté ; le critère retenu est
donc l'unicité du mot dans le parc.

**Un mot supplémentaire qui contredit interdit le rattachement.**
« Ngassam Olivier » partage « ngassam » avec NGASSAM Bernadette, mot
unique au parc — mais l'écriture porte « olivier », que le nom de
l'occupante ne contient pas. Un prénom différent sur un patronyme commun
désigne une autre personne bien plus souvent qu'une variante d'écriture.

**Le nom d'un agent est un parasite, pas une exclusion.** Mme Guessong
figure dans 158 des 603 observations du registre : c'est elle qui a
traité la majorité des décharges. Son nom apparaît donc de deux façons
dans la colonne artisan. Seul, il désigne un agent et la ligne ne peut
être reversée à personne. Accolé à un nom d'artisan — « Guy
Marcel(Guessong) » — il indique seulement qui a remis les fonds, et la
vente revient bien à Guy Marcel. Traiter les deux cas de la même façon
coûterait une vente à son bénéficiaire légitime : le nom de l'agent est
donc retiré de l'écriture avant tout rapprochement, et ce n'est que s'il
ne reste rien qu'on conclut à un agent.

**Le coût des deux erreurs n'est pas symétrique**, et c'est ce qui fixe
le curseur. Rattacher à tort revient à verser à un artisan les ventes
d'un autre : l'erreur est invisible et se paie en argent. Refuser un
rattachement juste produit un doublon visible, qu'un agent corrige. Le
seuil est réglé du côté où l'erreur se voit.

D'où quatre issues, dont une qui assume de ne pas trancher :

| Décision | Sens |
|---|---|
| `RATTACHE` | L'identité est établie |
| `A ARBITRER` | Ambiguïté réelle — **décision humaine requise**, jamais tranchée par le script |
| `SANS CORRESPONDANCE` | Déposant non installé ; la vente reste rattachée à son nom |
| `NON ARTISAN` | Emplacement ou agent du village, déclaré explicitement avec son motif |

Les non-personnes sont **déclarées et non devinées** : aucun algorithme
ne peut savoir que « Hall » est un espace du village. La liste figure en tête
de `rattacher-artisans.py`, chaque entrée porte son motif, et elle
apparaît dans le fichier produit — une exclusion qu'on ne peut pas
relire est une donnée perdue.

---

## Ce que la vérification des sources a établi

**Le relevé de recouvrement est cohérent au niveau de ses totaux** : la
somme des 36 lignes reproduit exactement les totaux qu'il déclare —
2 558 000 FCFA d'imputation, 395 000 payés, 2 009 000 restant à
recouvrer. L'« écart interne de 154 000 FCFA » que le rapport signalait
n'existe pas dans la source : il venait d'une transcription qui avait
omis un occupant entier, MAKAMTE Bibiane (boutique B13, 80 000 dus,
30 000 payés, 50 000 restants).

**Cinq lignes du relevé se contredisent pourtant elles-mêmes**, et cet
écart-là est réel :

| Espace | Occupant | Anomalie |
|---|---|---|
| B0102 | SCOOPSEMA | 2 000 F versés en janvier, non repris au total annuel |
| B0401 | FAFE | 36 000 F versés, aucune imputation ni total annuel |
| B0402 | SYESIYA BAMBOUS | 10 000 F dus, ni payé ni reste |
| B0501 | KAMENI Clovis | 72 000 F versés, non repris ; 144 000 dus sans payé ni reste |
| B0901 | Bambou House | redevance de 3 000 F/mois, imputation nulle |

Les trois premières lignes portent **110 000 FCFA encaissés au mois et
absents de la synthèse annuelle**. Selon le détail mensuel, le taux de
recouvrement est de **19,74 %** et non de 15,44 %. Les deux valeurs
figurent dans `parc-locatif.csv` — `paye_2026` pour la synthèse,
`paye_mensuel_2026` pour le détail — et la colonne `ecart_paye` porte la
différence. Trancher laquelle fait foi relève de la coordination.

**L'inventaire des produits est un squelette.** Il déclare dix-neuf
colonnes de caractérisation et n'en remplit que quatre : matériaux,
commune et département ne sont renseignés sur aucune des 134 lignes,
secteur et provenance sur deux. Quatorze artisans y figurent, pour
36 occupants au parc.

---

## Ce que signifie la colonne « reste à payer »

La question s'est posée pendant la vérification : le reste à payer est-il
la dette du village envers l'artisan, ou une créance client non encore
encaissée ? Les deux lectures produisent des constats opposés, et quatre
tests indépendants tranchent pour la première.

**Le document fait lui-même l'addition.** La feuille des ventes inscrit
en bas de colonne : reste à payer 234 850, commission retenue 135 275,
et **« Total des avoirs » 370 125** — soit exactement la somme des deux.
Un avoir est ce que la structure détient ; on ne détient pas une somme
qu'on n'a pas reçue. Le reste à payer est donc de l'argent encaissé.

**La commission recoupe le journal.** Les 135 275 FCFA de la ligne
« % ventes » sont, au franc près, le total de la feuille des commissions
retenues sur les décharges.

**Les dates concordent.** Sur les 495 lignes dont l'observation porte une
date, **96 % pointent vers une date qui figure au journal des
décharges**. Sur 73 couples (date, artisan) retrouvés dans les deux
documents, **65 portent le même montant au franc près**.

**Le compte se ferme.** Les 77 ventes sans observation totalisent
229 850 FCFA. Le reste à payer total vaut 234 850. Les 5 000 FCFA
d'écart s'expliquent entièrement : 3 000 pour la vente du Hall, dont
l'observation « Artisan inconnu » n'est pas une décharge, et 2 000 pour
l'anomalie de la ligne 602.

**Conclusion : une observation « payé le … » atteste que l'artisan a reçu
sa part.** L'article est alors soldé au sens comptable — son compte est
apuré — ce qui décrit le même événement sous un autre angle.

### Une conséquence sur le montant de la dette

Le reste à payer est inscrit **brut** : il vaut le montant entier de la
vente, vérifié sur 77 des 78 lignes concernées. Or la commission de 10 %
n'est prélevée qu'au moment du reversement, comme le montre la feuille
récapitulative où le « net à percevoir » vaut le montant moins 10 %.

La dette réelle du village envers ses artisans est donc **inférieure de
10 % au total de la colonne**. Pour l'exercice 2026 : 226 850 FCFA
inscrits, soit **204 165 FCFA effectivement dus** aux artisans et
22 685 FCFA de commission revenant au village.

---

## Ce qui reste à faire

**Faire trancher les cinq lignes incohérentes du relevé** par la section
Administrative et Financière, en particulier les 110 000 FCFA encaissés
au mois et absents du total annuel. Le taux de recouvrement du rapport
en dépend : 15,44 % ou 19,74 %.

**Attribuer un code d'espace à MAKAMTE Bibiane**, seule occupante du
relevé à ne pas en porter. Le contenant est renseigné — boutique B13 —
mais pas l'espace. Aucun script ne peut l'inventer.

**Trancher l'anomalie de la ligne 602** : le 25 mai 2026, une vente de
10 000 FCFA de Mme Justina porte un reste à payer de 12 000 FCFA. C'est
la seule ligne du registre où le reste dépasse le montant.

**Expliquer l'écart de la campagne de reversement.** L'état préparé
attribue 70 000 FCFA à Mme Justina, quand ses ventes non reversées
totalisent 81 000 FCFA et son reste à payer 83 000. L'écart de
11 000 FCFA ne correspond à l'exclusion d'aucune ligne.

**Fixer le vocabulaire.** Le mot « redevance » désigne deux flux opposés
selon le document : le loyer mensuel que l'artisan verse au village dans
le relevé de recouvrement, et la part de vente que le village doit à
l'artisan dans les états de reversement. Les fichiers produits emploient
« redevance » pour le premier et « part artisan » pour le second.

**Arbitrer les rapprochements ambigus.** Le script les liste à chaque
exécution. Ils portent sur environ 16 % du chiffre d'affaires et doivent
être soumis à la coordination — notamment les trois orthographes de
« Ngassam Crousti », qu'il faut d'abord unifier entre elles avant de
décider si elles désignent l'occupante NGASSAM Bernadette.
