# Données de reprise du registre de ventes

Ce dossier contient le registre de ventes transcrit par la coordination et
les rapports produits par `php artisan varbaf:importer`.

---

## Les fichiers

| Fichier | Ce qu'il est |
|---|---|
| `registre.csv` | Le registre de ventes du village, tenu à la main puis transcrit. **Source de vérité de la reprise.** |
| `rapport-import-20260825-reprise-productive.csv` | Synthèse de la **reprise effective** du 25/08 : ce qui a été lu, importé, rejeté, créé |
| `rapport-import-20260825-reprise-productive-signalements.csv` | Le détail ligne à ligne de cette même reprise. 734 lignes signalées, dont **705 importées et 29 rejetées** — c'est le seul fichier où les rejets sont identifiables |
| `rapport-import-20260826-controle-idempotence.csv` | Synthèse d'un **second passage à vide**, lancé pour prouver que la commande est relançable : 0 ligne importée, 1 149 déjà reprises, 0 création |
| `rapport-import-20260826-controle-idempotence-signalements.csv` | Le détail de ce second passage. Les 734 lignes y sont toutes marquées « Déjà reprise » : ce fichier ne dit rien des rejets |

**Ne pas confondre les deux paires.** Elles portent les mêmes compteurs
d'anomalies — la lecture du fichier ne dépend pas de l'état de la base —
mais seule celle du 25/08 porte les compteurs de création et les statuts
d'import réels. Celle du 26/08 mesure l'idempotence, pas la reprise.

---

## Ce que la première transcription a produit

Relevé des tables après la reprise du 25/08, puis après la remise à zéro du
26/08. La base a été vidée pour repartir sur une transcription améliorée :
ces chiffres sont la seule trace de ce qu'a donné la première.

| Table | Après reprise | Après `migrate:fresh --seed` |
|---|---:|---:|
| `artisans` | 232 | 0 |
| `boutiques` | 18 | 17 |
| `espaces_locatifs` | 344 | 17 |
| `attributions_espaces` | 343 | 0 |
| `produits` | 837 | 0 |
| `depots` / `lignes_depot` | 1 120 | 0 |
| `ventes` / `lignes_vente` | 1 120 | 0 |
| `mouvements_stock` | 2 240 | 0 |
| `mouvements_caisse` | 1 120 | 0 |
| `lignes_registre_importees` | 1 149 | 0 |
| `journaux_audit` | 2 | 0 |
| `taux_commissions` | 1 | 1 |
| `corps_metiers` | 14 | 14 |
| `categories_produits` | 28 | 28 |
| `roles` / `permissions` | 8 / 121 | 8 / 121 |

Trois lectures de ce tableau :

`mouvements_stock` vaut exactement le double de `ventes` : chaque ligne du
registre produit une entrée par le dépôt puis une sortie par la vente, et
laisse le stock à zéro. C'est le comportement attendu d'une reprise
historique — le village ne détient plus ces articles.

`taux_commissions` vaut 1 avant comme après : aucun taux n'avait été saisi
à la main, le seul enregistrement est celui du seeder.

`espaces_locatifs` passe de 17 à 344, et `boutiques` de 17 à 18. Voir
ci-dessous.

---

## Les quatre défauts connus de la première reprise

À corriger avant la prochaine, faute de quoi ils se figeront à nouveau.

### 1. Le taux de commission est un placeholder

`TauxCommissionSeeder` pose 10 % au 01/01/2023 et l'annonce lui-même comme
provisoire. La règle 1 fige le taux sur chaque vente à l'enregistrement :
les 1 120 ventes de la première reprise portaient donc un taux inventé, et
corriger le taux après coup n'en aurait recalculé aucune.

**Saisir les vrais taux et leurs dates d'effet avant de réimporter.** Le
taux a varié dans le temps ; `TauxCommission` est historisé pour ça.

### 2. La reprise multiplie le parc locatif par vingt

L'import crée un espace locatif par artisan — 327, dont 108 sur une
boutique technique qu'il fabrique pour les emplacements hors parc. Le parc
passe de 17 espaces réels à 344.

Or `RapportService::tauxOccupationEspaces()` prend `EspaceLocatif::count()`
sans filtre. Le taux d'occupation affiche alors un village plein, sur un
indicateur que la coordination présente à sa tutelle. C'est le miroir exact
de ce que l'arbitrage A-05 bis de `../dette-technique.md` cherchait à
éviter : là où sept emprises non louables sous-évaluaient le village d'un
tiers, trois cent vingt-sept espaces fictifs le déclarent complet.

### 3. Le rapprochement des noms d'artisans bute sur les civilités

232 artisans créés pour 240 écritures restées distinctes. Les 42
rapprochements écartés se lisent d'un coup d'œil : `M. DJOKO` / `Djoko`,
`Mme Sidonie` / `Sidonie`, `M. KAMTA` / `KAMTA`, `Mme Noussjou` /
`Noussjou`, `Pa mambou` / `Mambou`, `en Floralis` / `Floralis`. Ce sont les
civilités et les mots parasites qui font tomber la similarité sous 85 %.

Normaliser ces préfixes **avant** la comparaison en récupérerait une
vingtaine, sans toucher au seuil — ce qui est plus sûr que de descendre à
75 %, car `Dora` / `Nora` / `Doro` et `MEKA` / `MEKO` sont vraisemblablement
des personnes différentes.

### 4. Des désignations de produits sont dans la colonne artisan

`Cookies manioc`, `Chips manioc`, `croquette`, `fève sur la table`,
`Vin Therapeutig` apparaissent comme noms d'artisan. Le défaut est dans le
cahier, pas dans l'import : ces lignes demandent un arbitrage de la
coordination.

---

## Ce que la reprise laisse à compléter à la main

La première reprise a produit 232 artisans sans secteur d'activité, 837
produits sans catégorie et 343 attributions sans redevance convenue, alors
que 14 corps de métier et 28 catégories sont en base. Le registre ne porte
simplement pas ces informations.

Conséquence à connaître avant une démonstration : les filtres du catalogue
public par catégorie et par métier, ainsi que la ventilation des ventes par
corps de métier du tableau de bord, restent vides tant que ce rattachement
n'est pas fait.

---

## Relancer la reprise

```
php artisan varbaf:importer                      # docs/donnees/registre.csv par défaut
php artisan varbaf:importer --rapport=docs/donnees
php artisan varbaf:importer --seuil=85 --marge=10 # sensibilité du rapprochement des noms
```

La commande est relançable : chaque ligne laisse une empreinte dans
`lignes_registre_importees` et une ligne déjà reprise est comptée puis
sautée. **C'est aussi le piège d'une purge partielle** — vider les ventes
sans vider cette table rendrait tout réimport inopérant, chaque ligne étant
considérée comme déjà traitée.
