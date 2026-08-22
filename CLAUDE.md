# VARBAF — Système d'information du Village Artisanal Régional de Bafoussam

Projet de stage académique. **Échéance impérative : 5 septembre 2026.** Gel du code le 3 septembre.
Toute l'interface, les libellés, les commentaires et les messages sont en **français**.

---

## Stack

- PHP 8.3, Laravel 12
- Filament 5 (panneau `admin`)
- `nwidart/laravel-modules` pour le découpage modulaire
- `spatie/laravel-permission` pour les rôles et permissions
- PostgreSQL 14+
- Locale : `APP_LOCALE=fr`

---

## Architecture

Modules dans `Modules/<NomModule>/`, dans cet ordre de dépendance :

| # | Module | Contenu |
|---|---|---|
| 1 | Socle | Village, exercice, utilisateurs, rôles, permissions, journal d'audit |
| 2 | Artisanat | Artisans, corps de métier, entreprises, boutiques, espaces, attributions |
| 3 | Commerce | Catégories, produits, dépôts, journal de stock, ventes, taux de commission |
| 4 | Tresorerie | Caisses, sections, brouillard, comptes artisans, campagnes de reversement |
| 5 | Pilotage | Tableaux de bord, indicateurs, fonctionnalité IA |
| 6 | Portail | Site vitrine public, publication des produits, artisans vedettes |

**Règle de dépendance descendante.** Un module ne référence que les modules dont il dépend. Le Commerce n'écrit jamais directement dans les tables de la Trésorerie : il appelle le service exposé. Aucune dépendance montante.

---

## Règles métier non négociables

Ces règles priment sur toute considération de simplicité d'implémentation. Ne jamais les contourner sans validation explicite.

1. **Figement.** Une vente recopie à l'enregistrement : référence produit, désignation, prix unitaire, quantité, boutique, artisan, taux de commission. Ces valeurs ne sont jamais recalculées depuis les référentiels.
2. **Journal unique de caisse.** Toute opération financière passe par le service d'écriture au brouillard. Aucun module n'insère directement dans `mouvements_caisse`.
3. **Journal unique de stock.** Toute variation de stock (dépôt, vente, retrait, perte) passe par `mouvements_stock`. La quantité en stock est un solde calculé, jamais un champ saisi.
4. **Immuabilité après arrêté.** Un mouvement est corrigeable tant que sa journée n'a pas été arrêtée. Après l'arrêté de caisse du jour, il devient immuable : correction par contre-passation uniquement.
5. **Arrêté journalier.** Un arrêté de caisse par caisse et par jour : le caissier saisit le montant compté, le système calcule l'écart, un écart non nul exige une justification. Une journée arrêtée est verrouillée.
6. **Numérotation.** Les mouvements de caisse sont numérotés séquentiellement par section, sans rupture.
7. **Section ouverte.** Aucune opération hors d'une section de caisse ouverte. Une seule section ouverte par caisse.
8. **Une vente, une boutique.** L'écran de vente impose de choisir d'abord une boutique, puis de sélectionner les produits dans la liste de cette seule boutique. La référence produit est générée automatiquement à la création, jamais saisie. Les informations client (nom, contact, consentement, provenance) sont facultatives.
9. **Solde artisan calculé.** `solde dû = somme des parts artisan − somme des reversements`. Jamais stocké comme valeur modifiable.
10. **Taux de commission.** Uniforme pour tous les artisans, historisé par date d'effet. Le taux appliqué est celui en vigueur à la date de la vente, puis figé sur la vente.
11. **Reversements mensuels.** Une campagne sélectionne les ventes non rattachées à une campagne validée dont la date est antérieure à la date d'arrêté. Un décaissement par artisan. Solde négatif non payé et reporté.
12. **Cloisonnement artisan.** Dans le panneau artisan, chaque requête est filtrée par l'artisan connecté, via un scope global. Un artisan ne voit jamais les données d'un autre.
13. **Redevance au mètre carré.** La redevance mensuelle d'une boutique se calcule à partir de sa superficie et du tarif au mètre carré. Le premier mois suivant l'attribution est gratuit.
14. **Validation des produits.** Un produit passe par les statuts soumis, validé, exposé, retiré. La validation relève du chef de section Production ; le coordonnateur peut suppléer en son absence, le journal d'audit conservant l'identité du validateur réel. Un produit non validé n'est ni vendable ni publiable sur le portail.
15. **Alerte de rupture.** Quand le stock d'un produit atteint son seuil d'alerte, une notification est adressée à l'artisan et aux sections Production et Commercialisation.

## Rôles réels de la structure

Coordonnateur, Coordonnateur adjoint, et cinq chefs de section : Production, Formation, Administrative et Financière, Promotion et Commercialisation, Orientation-Information-Documentation. Les rôles applicatifs calquent cet organigramme.

**Le coordonnateur n'est pas le super-utilisateur.** Ce sont deux objets de nature différente :

| | Coordonnateur | Super-utilisateur |
|---|---|---|
| Nature | Rôle métier, adossé à une fonction | Compte technique d'administration |
| Périmètre | Valide les dossiers et les produits, attribue les boutiques, arrête les exercices, consulte les tableaux de bord | Tout, y compris s'attribuer des droits et administrer les rôles |
| Justifié par | L'organigramme de la structure | L'exploitation du système |

Les fusionner reviendrait à permettre à une personne exerçant une fonction administrative de modifier ses propres permissions et d'effacer des traces. Dans une structure qui manipule de l'argent public, c'est précisément ce qu'un contrôle interne cherche à empêcher — et c'est le prolongement direct de la règle de séparation des rôles énoncée plus bas.

En pratique, le coordonnateur du village portera probablement les deux rôles. Ce sera une **décision d'attribution explicite**, consignée comme telle, jamais une propriété du modèle de données.

**Suppléance.** Une responsabilité de nature qualitative — valider un produit — peut être suppléée par la hiérarchie : sans cela, l'absence d'une seule personne bloque le flux principal. Une responsabilité de nature financière — clôturer une section, valider une campagne de reversement — ne se supplée pas, parce que le risque n'est pas l'immobilisme mais l'auto-attribution d'un avantage. La distinction est celle de RG-23.

---

## Conventions Filament

- Pattern **ManageRecords** : CRUD via modals, pas de page dédiée.
- Formulaires : `Filament\Schemas\Schema` avec `->columns(1)`, puis `Grid::make(2)` pour les paires de champs.
- Placeholders français obligatoires sur les `TextInput`. `helperText` uniquement sur les `Toggle`.
- Champs uniques : `->unique(ignoreRecord: true)`.
- Actions de ligne : `->iconButton()` + `->tooltip()`.
- Modals : `modalWidth('3xl')`, `stickyModalHeader()`, `stickyModalFooter()`, alignement `Alignment::End`, boutons **Enregistrer** et **Fermer** (jamais « Annuler »), `createAnother(false)` sur les créations.
- `getBreadcrumbs()` défini dans chaque page `Manage<Entite>`.
- Colonnes : `->searchable()` et `->sortable()` sur les champs pertinents, `->toggleable(isToggledHiddenByDefault: true)` sur les colonnes secondaires.

## Permissions

Nommage `<action>_<entite>` en snake_case : `lister_ventes`, `ajouter_vente`, `annuler_vente`, `ouvrir_section_caisse`, `cloturer_section_caisse`, `valider_campagne_reversement`, `modifier_taux_commission`.

- `canAccess()` sur chaque ressource vérifie `lister_<entites>`.
- Chaque action porte `->visible(fn () => auth()->user()->can('<permission>'))`.
- Séparation des rôles — **RG-23** de `docs/specification-tresorerie.md`, qui porte les règles RG-01 à RG-27 du module Trésorerie : l'ouverture et la clôture d'une section de caisse, ainsi que la validation d'une campagne de reversement, reviennent à un profil habilité **distinct** de celui de l'agent de saisie.
- **Le `->visible()` est la seule barrière.** Aucune Policy n'est déclarée : la couche d'autorisation de Filament laisse passer tout compte du panneau (DT-08). `tests/Feature/ConventionsFilamentTest.php` échoue si une action est déclarée sans `->visible()`, ou avec un `->visible()` qui n'interroge aucune permission.
- **Suppressions.** Ce qui porte une histoire ne se supprime pas, ce qui n'est qu'un libellé se corrige. Artisan, attribution, boutique, exercice, village, produit, vente, mouvement de stock, mouvement de caisse : hors de portée de tout rôle métier — on désactive, on résilie, on clôture, on contre-passe. Corps de métier, entreprise artisanale, catégorie de produit et autres référentiels de libellés : ouverts à qui peut les créer, pour qu'une erreur de saisie se corrige sans passer par l'administrateur.
- **Une trace se constate, elle ne se choisit pas.** Un champ qui enregistre *qui* a fait quelque chose prend le compte connecté, jamais une valeur choisie dans une liste. Si la mauvaise personne peut agir, la réponse est de lui retirer la permission, pas d'ouvrir un `Select`.

## Audit

Toute création, modification ou action métier sensible appelle `JournalAudit::enregistrer()` dans le `->after()` de l'action. **Exception : les suppressions** appellent l'audit dans le `->before()`, puisque l'enregistrement n'est plus lisible après coup.

## CSS

Aucun CSS personnalisé hors du fichier de thème du panneau. Pas de style inline dans les vues Blade ni dans les ressources.

---

## Méthode de travail

- Un module à la fois, dans l'ordre du tableau ci-dessus.
- Migrations puis modèles puis ressources Filament, dans cet ordre.
- Seeders alimentés par les données réelles du village (registre de ventes transcrit), jamais par des données fictives du type « Produit test ».
- Commit après chaque entité fonctionnelle, message en français.
- `php artisan migrate:fresh --seed` doit fonctionner sans erreur à tout moment.

## À ne pas faire

- Ne pas ajouter de fonctionnalité hors du périmètre défini dans `docs/retroplanning.md`.
- Ne pas installer de paquet supplémentaire sans nécessité démontrée.
- Ne pas coder le portail public dans le panneau Filament : c'est une interface publique distincte.
- Ne pas implémenter de vente, commande ou paiement en ligne dans le portail public.
- Ne pas modifier les règles métier ci-dessus pour simplifier une implémentation.