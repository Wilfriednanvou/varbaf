# Module Trésorerie — Spécification fonctionnelle
## Projet : Système d'information intelligent du Village Artisanal Régional de Bafoussam

---

## 1. Objet du module

Le module Trésorerie assure le suivi de l'ensemble des flux financiers du Village Artisanal : encaissements liés à la vente des produits artisanaux, redevances des boutiques, locations d'espaces, frais de formation, dépenses, et reversements dus aux artisans.

Il repose sur trois principes :

1. **Un journal unique.** Toute opération financière, quelle que soit son origine, s'inscrit dans le brouillard de caisse. Aucun module ne tient sa propre comptabilité parallèle.
2. **L'immuabilité.** Un mouvement validé n'est jamais modifié ni supprimé. Toute correction passe par une contre-passation qui laisse une trace.
3. **Le figement.** Les informations d'une transaction sont recopiées au moment de son enregistrement, jamais reconstituées a posteriori depuis les référentiels.

---

## 2. Modèle de données

### 2.1 CAISSE
| Attribut | Description |
|---|---|
| code | Identifiant unique |
| libelle | Désignation (ex. caisse principale) |
| caissier_responsable | Agent titulaire de la caisse |
| etat | Active / inactive |

### 2.2 SECTION_CAISSE
Correspond à un exercice de la caisse.

| Attribut | Description |
|---|---|
| caisse_id | Caisse de rattachement |
| libelle | Ex. exercice 2026-2027 |
| date_ouverture / date_cloture | Bornes de l'exercice |
| solde_ouverture / solde_cloture | Soldes de début et de fin |
| etat | Ouverte / clôturée |
| ouverte_par / cloturee_par | Traçabilité |

### 2.3 MOUVEMENT_CAISSE (brouillard de caisse)
Journal chronologique de tous les flux.

| Attribut | Description |
|---|---|
| section_id | Section de rattachement |
| numero_ordre | Séquentiel, sans rupture, propre à la section |
| date_operation | Date du flux |
| nature | Vente, redevance, location, formation, dépense, reversement, contre-passation |
| libelle | Description lisible |
| sens | Entrée / sortie |
| montant | Montant du flux |
| solde_apres | Solde de caisse après l'opération |
| origine_type / origine_id | Référence de l'opération source |
| piece_justificative | Numéro de la pièce |
| saisi_par | Utilisateur |

### 2.4 VENTE
| Attribut | Description |
|---|---|
| numero | Identifiant du ticket |
| date_vente | Date de la transaction |
| boutique_id | Boutique concernée (une seule par vente) |
| artisan_id | Artisan bénéficiaire, figé |
| vendeur_id | Agent du village ayant réalisé la vente |
| montant_total | Total encaissé auprès du client |
| taux_commission | Taux appliqué, figé |
| montant_commission | Part revenant au village |
| part_artisan | montant_total − montant_commission |
| campagne_reversement_id | Nul tant que non reversée |
| etat | Validée / annulée |

### 2.5 LIGNE_VENTE
| Attribut | Description |
|---|---|
| vente_id | Vente de rattachement |
| reference_produit | Référence unique, figée |
| designation | Libellé du produit, figé |
| prix_unitaire | Prix au moment de la vente, figé |
| quantite | Quantité vendue |
| montant_ligne | prix_unitaire × quantite |

### 2.6 TAUX_COMMISSION
Historique du paramètre, uniforme pour tous les artisans.

| Attribut | Description |
|---|---|
| taux | Valeur en pourcentage |
| date_effet | Date d'entrée en vigueur |
| reference_decision | Acte ou note de service à l'origine du changement |
| saisi_par | Utilisateur |

### 2.7 COMPTE_ARTISAN
Solde calculé, jamais saisi directement.

| Attribut | Description |
|---|---|
| artisan_id | Artisan concerné |
| total_vendu | Cumul des parts artisan |
| total_reverse | Cumul des reversements |
| solde_du | total_vendu − total_reverse |

### 2.8 CAMPAGNE_REVERSEMENT
| Attribut | Description |
|---|---|
| periode | Mois concerné |
| date_arrete | Date de sélection des ventes |
| etat | En préparation / validée |
| validee_par / date_validation | Traçabilité |

### 2.9 REVERSEMENT
Un enregistrement par artisan et par campagne.

| Attribut | Description |
|---|---|
| campagne_id | Campagne de rattachement |
| artisan_id | Bénéficiaire |
| montant_periode | Part issue des ventes de la période |
| montant_regularisation | Part issue de ventes ou annulations antérieures reportées |
| montant_paye | Montant effectivement décaissé |
| solde_reporte | Reliquat reporté sur la campagne suivante |
| mouvement_caisse_id | Décaissement correspondant |

### 2.10 ARRETE_CAISSE
Contrôle physique quotidien de la caisse. Enregistrement de contrôle, non conteneur de mouvements.

| Attribut | Description |
|---|---|
| caisse_id | Caisse contrôlée |
| section_id | Section de rattachement |
| date_arrete | Journée contrôlée (une seule par caisse et par jour) |
| solde_theorique | Calculé par le système à partir du brouillard |
| solde_physique | Montant compté à la main par le caissier |
| ecart | solde_physique − solde_theorique |
| commentaire_ecart | Justification, obligatoire si écart non nul |
| arrete_par | Agent ayant procédé au comptage |
| date_validation | Horodatage de l'arrêté |

### 2.11 Entités connexes
`ARTISAN`, `BOUTIQUE`, `PRODUIT` (référence unique obligatoire), et `ATTRIBUTION_BOUTIQUE` (artisan, boutique, date de début, date de fin, redevance) qui historise l'occupation des 24 boutiques.

---

## 3. Règles de gestion

### Caisse et sections

**RG-01** — Une caisse peut comporter plusieurs sections, mais une seule section ouverte à la fois.

**RG-02** — Le solde d'ouverture d'une section est égal au solde de clôture de la section précédente de la même caisse.

**RG-03** — Aucune opération ne peut être enregistrée en dehors d'une section ouverte.

**RG-04** — Les mouvements d'une section sont numérotés séquentiellement, sans rupture ni réutilisation de numéro.

**RG-05** — Un mouvement peut être corrigé ou supprimé tant que la journée à laquelle il appartient n'a pas fait l'objet d'un arrêté de caisse. Une fois la journée arrêtée, le mouvement devient immuable : toute correction s'effectue par contre-passation, qui crée un nouveau mouvement de sens inverse référençant le mouvement d'origine.

**RG-06** — Tous les modules enregistrent leurs flux via un service unique d'écriture au brouillard. Aucune écriture directe en base n'est autorisée depuis un autre module.

**RG-07** — La clôture d'une section n'est possible que si tous ses mouvements sont validés et si toutes ses journées ont été arrêtées. Elle est irréversible.

### Arrêté de caisse journalier

**RG-25** — Un arrêté de caisse par caisse et par journée d'activité. Le caissier saisit le montant physiquement compté ; le système calcule l'écart avec le solde théorique issu du brouillard.

**RG-26** — Un écart non nul exige un commentaire de justification. L'arrêté est refusé sans lui.

**RG-27** — Une journée arrêtée est verrouillée : aucun mouvement ne peut y être ajouté, modifié ou supprimé rétroactivement. Un mouvement daté d'une journée déjà arrêtée est enregistré à la date du jour, avec mention de sa date d'origine.

### Ventes et commission

**RG-08** — Une vente porte sur une seule boutique. Un achat couvrant plusieurs boutiques génère autant de ventes distinctes.

**RG-09** — Tout produit porte une référence unique générée automatiquement à sa création, à partir du numéro de boutique et d'un compteur. Elle n'est jamais saisie par un utilisateur. Elle sert à l'étiquetage physique des produits déposés, au reçu de vente et à l'état de reversement.

**RG-09 bis** — La sélection d'un produit dans l'écran de vente se fait exclusivement par le choix préalable d'une boutique, puis par la liste des produits de cette boutique. Ce cheminement rend la règle RG-08 structurellement inviolable.

**RG-10** — À l'enregistrement, la vente fige : référence et désignation du produit, prix unitaire, quantité, boutique, artisan et taux de commission. Ces valeurs ne sont plus recalculées par la suite.

**RG-11** — Le taux de commission est uniforme pour tous les artisans. Il est historisé par date d'effet ; le taux appliqué à une vente est celui en vigueur à sa date de vente.

**RG-12** — La commission est calculée sur le montant total de la vente : `montant_commission = montant_total × taux_commission`, et `part_artisan = montant_total − montant_commission`.

**RG-13** — L'intégralité du montant de la vente entre en caisse. La part artisan constitue une dette du village envers l'artisan jusqu'à son reversement.

**RG-14** — La vente est réalisée par un agent du village, dont l'identifiant est enregistré sur la vente.

### Compte artisan et reversements

**RG-15** — Le solde dû à un artisan est un montant calculé : `somme des parts artisan − somme des reversements`. Il ne peut être saisi ni corrigé manuellement, et doit toujours pouvoir être recalculé depuis les mouvements.

**RG-16** — Les reversements sont mensuels et déclenchés par le Village Artisanal, jamais à la demande de l'artisan.

**RG-17** — Une campagne de reversement sélectionne toutes les ventes validées non encore rattachées à une campagne validée, dont la date est antérieure ou égale à la date d'arrêté.

**RG-18** — Une campagne génère un décaissement distinct par artisan. Chaque décaissement donne lieu à un reçu signé par l'artisan.

**RG-19** — Une vente saisie après la clôture d'une période est automatiquement reportée sur la campagne suivante. Elle y apparaît dans une rubrique de régularisation distincte, avec mention de sa date d'origine.

**RG-20** — Si le solde d'un artisan est négatif sur une campagne (annulations supérieures aux ventes), aucun décaissement n'est effectué et le solde négatif est reporté sur la campagne suivante jusqu'à absorption.

**RG-21** — La validation d'une campagne rattache définitivement les ventes retenues, interdisant tout second reversement des mêmes ventes.

### Sécurité et traçabilité

**RG-22** — Chaque caisse est rattachée à un caissier responsable.

**RG-23** — L'ouverture et la clôture d'une section, ainsi que la validation d'une campagne de reversement, sont réservées à un profil habilité distinct de celui de l'agent de saisie.

**RG-24** — Tout mouvement conserve l'identité de l'utilisateur qui l'a saisi et, le cas échéant, de celui qui l'a validé.

---

## 4. Écrans à prévoir

| Écran | Fonction |
|---|---|
| Brouillard de caisse | Consultation chronologique des mouvements avec solde progressif, filtres par période et nature |
| Ouverture / clôture de section | Gestion des exercices de caisse |
| Saisie de vente | Sélection de la boutique, puis des produits de cette boutique avec leur quantité, contrôle du stock disponible, saisie facultative du client, calcul automatique de la commission |
| Paramétrage du taux | Taux en vigueur et historique des taux avec dates d'effet |
| Campagne de reversement | Préparation, état récapitulatif par artisan, validation, génération des reçus |
| Situation d'un artisan | Ventes, parts dues, reversements effectués, solde restant |
| Tableau de bord trésorerie | Indicateurs de suivi |

---

## 5. Indicateurs du tableau de bord

| Indicateur | Formule |
|---|---|
| Solde de caisse | Solde du dernier mouvement de la section ouverte |
| Chiffre d'affaires de la période | Somme des montants totaux des ventes validées |
| Recettes de commission | Somme des montants de commission |
| Dettes envers les artisans | Somme des soldes dus non reversés |
| Ventes par boutique | Cumul des ventes groupées par boutique |
| Ventes par vendeur | Cumul des ventes groupées par agent |
| Taux d'occupation des boutiques | Boutiques attribuées / 24 |
| Montant du dernier reversement | Total décaissé lors de la dernière campagne validée |

---

## 6. Cas limites à traiter

| Cas | Traitement retenu |
|---|---|
| Vente saisie après clôture de période | Report automatique sur la campagne suivante, en rubrique de régularisation (RG-19) |
| Annulation d'une vente déjà reversée | Contre-passation, ligne négative reportée sur la campagne suivante |
| Solde artisan négatif sur une campagne | Aucun décaissement, report du solde négatif (RG-20) |
| Changement de taux en cours de mois | Chaque vente conserve le taux en vigueur à sa propre date (RG-11) |
| Changement d'occupant d'une boutique | L'historique des ventes reste rattaché à l'artisan figé sur chaque vente |
| Produits homonymes dans deux boutiques | Distinction par référence unique (RG-09) |

---

## 7. Patterns d'implémentation

Éprouvés sur un module de trésorerie comparable (ERP ASM), à reproduire.

### 7.1 Architecture en couches

Les pages Filament et les composants Livewire **n'exécutent jamais de requêtes directement**. Ils délèguent à des services, qui orchestrent les modèles :

- `VenteService` — enregistrement d'une vente, calcul de la commission, décrément du stock, appel au service de trésorerie
- `TresorerieService` — point d'entrée unique du brouillard, numérotation, calcul du solde, contre-passation
- `ReversementService` — préparation et validation des campagnes
- `RapportService` — calcul centralisé des indicateurs du tableau de bord

Sans cette séparation, la logique de commission se retrouve dupliquée dans chaque écran qui la manipule.

### 7.2 Verrouillage par hooks Eloquent

L'immuabilité d'une section clôturée ne se garantit pas dans l'interface, mais dans le modèle. Hooks `creating`, `updating` et `deleting` sur `MouvementCaisse` et `Vente` : toute écriture rattachée à une section clôturée lève une exception. Une règle métier appliquée seulement dans l'écran est contournée dès la première commande en console.

### 7.3 Numérotation atomique

Les numéros de vente et les numéros d'ordre du brouillard sont générés par un upsert PostgreSQL avec `lockForUpdate()`, avec trois tentatives en cas de collision. C'est ce qui garantit l'absence de rupture et de doublon en cas de saisie simultanée par deux agents.

Format de numéro de vente : `VTE-AAAAMMJJ-XXXX`.

### 7.4 Cloisonnement par utilisateur

Un caissier ne voit que ses propres caisses, sauf permission `voir_toutes_caisses`. Le filtrage passe par un scope global sur le modèle, jamais par un filtre écran par écran. Le même mécanisme s'applique au panneau artisan.

### 7.5 Écrans interactifs

L'écran de vente et le brouillard de caisse sont des composants Livewire, pas des ressources Filament : ils ne sont pas des CRUD mais des formulaires métier avec calcul en temps réel.

### 7.6 Performance

La section de caisse couvrant un exercice entier, la table des mouvements atteindra plusieurs milliers de lignes. Prévoir des index sur `(section_id, date_operation)` et `(section_id, numero_ordre)`, et une pagination systématique du brouillard.

### 7.7 Arrêté de caisse journalier — adopté

La section couvrant un exercice, aucun contrôle physique n'intervient avant sa clôture annuelle. Un écart de caisse resterait donc invisible pendant des mois.

Correctif léger : une table `ArreteCaisse` — date, solde théorique du jour, solde physique compté, écart, commentaire obligatoire si écart non nul, agent. Ce n'est pas un conteneur de mouvements, seulement un enregistrement de contrôle. Un écran, une table, et le module gagne son mécanisme de contrôle interne quotidien.

Si cet arrêté est adopté, la règle d'annulation devient : correction simple avant l'arrêté du jour, contre-passation obligatoire après. Sinon, la contre-passation reste la seule voie de correction en toute circonstance.

---

## 8. Points restant à valider auprès de la structure

- Modalités pratiques du reçu de reversement : signature manuscrite sur document imprimé, ou registre de décharge ?
- Existence de plusieurs caisses simultanées, ou caisse unique dans les faits ?
- Traitement des produits en pièce unique : gestion par quantité ou par article individuel ?
- Suivi des dépôts d'artisans : le village enregistre-t-il les produits confiés avant leur vente ?