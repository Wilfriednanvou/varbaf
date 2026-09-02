# Dette technique assumée

Écarts connus entre la conception et l'implémentation, décidés volontairement sous contrainte de délai. Ce document alimente la section « limites et perspectives » du rapport.

---

| # | Écart | Motif | Traitement prévu |
|---|---|---|---|
| DT-02 | Aucun contrôle de cohérence inter-villages sur les attributions | Le système ne gère qu'un village en exploitation ; le contrôle protège un scénario non atteignable | Version 2, si le déploiement s'étend à d'autres villages |
| DT-03 | `SessionConnexion` non implémentée | Le journal d'audit couvre le besoin de traçabilité | Retirée du périmètre |
| DT-04 | `EcheanceRedevance` et `PaiementRedevance` non implémentées | Retirées du périmètre par arbitrage de charge ; les redevances restent encaissables comme mouvement de caisse ordinaire | Version 2 |
| DT-05 | Méthodes de modèle écrites mais non appelées (`estDisponible()`, `getAttributionActive()`, `getHistoriqueAttributions()`, etc.) | Écrites pour les modules suivants qui les consommeront | À couvrir par les modules Commerce, Trésorerie et Pilotage |
| DT-06 | Requête N+1 sur la colonne « occupant » de la table des boutiques | Parc de 24 boutiques : impact nul à cette échelle | À optimiser si le parc s'étend |
| DT-07 | Panneau artisan classé « maquetté » | Contrainte de délai — remise au 5 septembre | Version 2. **La couche de notification est sortie de cette ligne le 26/08** : le canal `database` et la cloche du panneau sont en service pour la règle 15. Ce qui reste attaché au panneau artisan, c'est le destinataire *artisan* de cette même règle — voir A-09 |
| DT-08 | Aucune Policy déclarée : la couche d'autorisation de Filament autorise tout compte du panneau. La sécurité repose entièrement sur le `->visible()` porté par chaque action | Les `->visible()` sont exhaustifs et vérifiés ; ajouter sept Policies avant le gel du code coûterait plus qu'il ne protégerait | Le comportement est figé à deux niveaux : `tests/Feature/HabilitationArtisanTest.php` l'éprouve en exécution sur `ArtisanResource` seule (accès forgé à l'action `create` compris) ; `tests/Feature/ConventionsFilamentTest.php` est le garde-fou transversal — il parcourt statiquement toutes les ressources et pages de tous les modules et échoue si une action est déclarée sans `->visible()` interrogeant une permission. Les deux échoueront si une Policy vient un jour changer ce comportement — signal explicite pour reprendre ce point |
| DT-09 | `date_debut_facturation` est recalculée à chaque écriture. Corriger la date d'entrée d'une attribution déplace donc le mois offert, y compris si une redevance a déjà été encaissée sur la période | Le recalcul est le comportement juste tant qu'aucun encaissement n'existe — cas de tous les contrats saisis à ce jour | Garde-fou à poser côté Trésorerie : refuser la modification de `date_debut` dès qu'un paiement de redevance est rattaché à l'attribution |
| DT-10 | Le journal d'audit est immuable au niveau du modèle, mais une suppression de masse par le constructeur de requêtes (`JournalAudit::query()->delete()`) ou un `DELETE` SQL direct passe outre | Fermer la porte demanderait un déclencheur PostgreSQL ou le retrait du droit `DELETE` au rôle applicatif | À poser au déploiement, avec la configuration de la base de production |
| DT-11 | Les gabarits PDF (`Modules/Commerce/resources/views/ventes/recu.blade.php`, `.../depots/decharge.blade.php`) portent du CSS inline et un bloc `<style>`, alors que CLAUDE.md interdit tout style hors du thème du panneau | La règle visait l'interface Filament ; un gabarit rendu par DomPDF n'a ni accès au thème du panneau ni à une feuille de style externe partagée — DomPDF ne charge pas les assets compilés de l'application | Exception documentée, pas un écart à corriger : ces deux vues sont hors panneau et hors périmètre de la règle CSS |
| DT-12 | RG-23 (séparation saisie / clôture) n'est vérifiée par un test que pour la vente. Côté caisse, `coordonnateur` et `chef_section_administrative_financiere` détiennent aujourd'hui à la fois `saisir_mouvement_caisse` et `ouvrir_section_caisse` / `cloturer_section_caisse` | Le rôle « caissier », qui aurait porté la seule saisie, a été retiré du périmètre (voir « Écarts corrigés ») sans qu'un profil de saisie dédié à la caisse ne soit réintroduit | **Partiellement traité le 22/08 :** RG-23 est désormais tenue et éprouvée sur les campagnes de reversement — `preparer_campagne_reversement` revient à la section Administrative et Financière, `valider_campagne_reversement` au coordonnateur seul, et deux tests de `SeparationDesRolesTest` le vérifient. Le cumul saisie / clôture subsiste sur la **section de caisse** : à trancher avec la coordination — soit un rôle de caissier distinct réapparaît, soit le cumul est assumé (structure trop petite pour séparer physiquement caisse et direction) et consigné comme tel |
| DT-13 | Un produit dont la fiche technique porte plusieurs conditionnements à des prix distincts n'est représentable que par autant de produits séparés : `produits.prix_unitaire` est un montant unique, figé sur la vente par la règle 1 | Le défaut n'est pas né de la fiche technique, il y est devenu **visible**. Le registre le portait déjà, caché dans la désignation : « savon 1l », « savon 1/2l », « eau de javel 5l », « Miel 650g » — et « Huile de coco » du même artisan vendue 2 000 F le 19/06 puis 5 000 F le 01/10, sans que rien dans la donnée dise quelle taille. La colonne `conditionnement` du registre normalisé est renseignée sur 2 lignes sur 603. Corriger le modèle des prix demanderait de rouvrir la règle 1, l'écran de vente, l'import et les 602 ventes déjà chargées | **Contenu, pas corrigé.** `AnalyseurFicheTechnique` repère les montants d'une fiche, les compte, et n'en reporte **aucun** au formulaire : un `prix_unitaire` pré-rempli à 6 000 pour l'huile de palmiste serait faux deux fois sur trois, et faux en silence. Le signalement `PLUSIEURS_MONTANTS` le dit à l'agent, qui saisit le prix du produit enregistré. `FicheTechniqueTest::test_les_montants_sont_signales_et_jamais_reportes()` retient la décision. Version 2 : un produit porteur de conditionnements, chacun avec son prix |

---

## Écarts corrigés

| Date | Écart | Correction |
|---|---|---|
| 20/08 | Règles métier situées dans l'interface (artisan inactif, immuabilité du journal d'audit, boutique indisponible) | Déplacées dans les modèles via crochets Eloquent |
| 20/08 | Attribution rattachable à un exercice clôturé | Contrôle ajouté au modèle |
| 20/08 | `FileUpload` de la photo sans disque explicite | `->disk('public')` sur le champ **et** sur la colonne, plus `storage:link` |
| 20/08 | Checklist « test avec un utilisateur non super-utilisateur » jamais exécutée sur trois commits | `tests/Feature/HabilitationArtisanTest.php` |
| 23/08 | Attribution portée par la boutique : deux artisans d'un même local refusés comme chevauchement | `AttributionEspace` porte l'espace locatif ; la règle de non-chevauchement suit |
| 23/08 | Redevance calculée `superficie × tarif au m²`, jamais renseignée faute de barème | Montant convenu par espace, de 2 000 à 60 000 FCFA, contrôlé par le modèle |
| 23/08 | Taux d'occupation rapporté au parc de boutiques | Rapporté au parc d'espaces locatifs : un local partagé comptait pour une seule occupation |
| 23/08 | Nomenclature des corps de métier composée plutôt que reprise de la structure | Les 14 secteurs officiels, le seeder faisant autorité |
| 22-23/08 | Les quatorze points de `docs/audit-tresorerie.md` | Onze corrigés : B1 (ouverture de section, `Exercice::courant()`), M2 (`#[Locked]` sur les quatre composants Livewire et filtrage des `find()`), M3 (RG-27 comparé au dernier jour arrêté), M4 (RG-07, `journeesNonArretees()`), M5 (RG-02, solde d'ouverture dérivé dans `creating`), M6 (verrou porté sur la ligne de section, réessai restreint à l'unicité), Y8 (`ecart` recalculé et retiré de `$fillable`), Y9, Y10 (agrégat SQL), Y12 (montants en entiers de bout en bout), Y14 (captures restreintes aux exceptions métier). Y11 et Y13 sont consignés en A-06 et A-07. Y7 est resté ouvert jusqu'au 27/08 — voir la ligne de cette date |
| 26/08 | `valider()` ne décaissait plus, `payerReversement()` n'avait aucun appelant, la chaîne de reversement était coupée | Décaissement rétabli dans la transaction de validation, voir l'arbitrage A-08 |
| 26/08 | `DB_PASSWORD` en clair dans `phpunit.xml`, fichier suivi par Git | Déplacé dans `.env.testing`, ignoré par Git. Le champ n'est pas laissé vide dans `phpunit.xml` mais retiré : PHPUnit pose ses variables avant Laravel et Dotenv n'écrase pas une variable déjà définie |
| 26/08 | Règle 15 déclarée dans `CLAUDE.md` et sans effet : `SeuilAlerteFranchi` était émis au bon moment, aucun auditeur ne l'écoutait | `NotifierSeuilAlerte` branché par le fournisseur du module Commerce, table `notifications`, cloche activée sur le panneau. Portée réelle et écart résiduel en A-09 |
| 28/08 | Le portail public déclarait la police « Instrument Sans » sans jamais la charger : aucun `@font-face`, aucun `<link>`, donc un repli silencieux sur la police système | Inter servie depuis `public/fonts/portail/`, recopiée depuis les assets publiés par Filament plutôt que référencée chez eux. Aucune requête vers un CDN : le poste du village peut n'avoir aucune connexion le jour de la démonstration |
| 28/08 | La table `contenus_page` n'était alimentée par aucun seeder : chaque page du portail tombait sur son texte de repli, et « Le village » annonçait une présentation « publiée prochainement » | `ContenuPageSeeder` branché par `PortailDatabaseSeeder`. Le défaut ne levait aucune erreur — les vues gèrent l'absence de contenu, ce qui est correct, et c'est ce qui l'a rendu invisible |
| 26/08 | Le sous-sol et l'espace vert écartés du parc alors qu'ils portent trois espaces loués, dont le plus cher du village | Entrés au parc, colonne `nature` sur les contenants, taux d'occupation calculable sur les boutiques seules. Voir l'arbitrage A-05 bis |
| 26/08 | DT-01 : `Exercice::cloturer()` ne vérifiait ni les sections de caisse ouvertes ni les campagnes non validées — on pouvait refermer une période en laissant de l'argent en caisse et des artisans impayés | Registre de verrous : le Socle expose `VerrouDeCloture`, la Trésorerie vient s'y déclarer depuis son propre fournisseur. La dépendance continue de descendre, et le Socle ne référence rien du module 4. Neuf tests, dont un qui éprouve le câblage lui-même |
| 26/08 | La nature d'un contenant n'était éditable dans aucun écran : seul le seeder pouvait déclarer un local hors vente | Champ, colonne et filtre ajoutés à `BoutiqueResource` |
| 26/08 | `EspaceLocatif::genererCode()` ne gardait que les chiffres du numéro : `SS01` et `EV01` se réduisaient à `B01` et fabriquaient les codes de la boutique B01 | Préfixe alphanumérique, rang calculé sur les seuls codes qui suivent la règle, et un code relevé sur le terrain — `G0201` sous `SS01` — survit désormais à la dérivation |
| 26/08 | `notifications.data` posé en `text` par la migration standard de Laravel : la cloche de Filament compte les non-lues avec `data->>'format'`, que PostgreSQL refuse sur du texte. **Toute page du panneau plantait dans le navigateur** | Colonne passée en `json`. Le défaut était masqué parce que les tests montent les composants Livewire directement : seuls les deux qui font une vraie requête HTTP rendent la barre supérieure, et ils vivent dans un module sans rapport |
| 27/08 | Y7 : `ServiceTresorerie::$sectionCible` était un état **statique**, posé par l'écran de caisse et remis à null dans le `finally` de l'appelant. Un `finally` oublié, ou un chemin de sortie non prévu, et la section restait ciblée pour toutes les écritures suivantes du même processus — sans qu'aucune n'échoue : elles réussissaient, dans la mauvaise caisse | Propriété d'instance, et une méthode `pour(SectionCaisse, callable)` qui pose le ciblage, exécute, et le relâche dans son propre `finally` — que l'appelant ne peut plus oublier. La section précédente est restaurée et non effacée, de sorte que deux ciblages imbriqués se défont dans l'ordre. `ServiceTresorerie` devient un singleton du conteneur, sans quoi le ciblage porterait sur une instance et l'écriture sur une autre. Le repli de `resoudreSectionOuverte()` reçoit un `orderBy` explicite : il désignait jusque-là une section au gré du plan d'exécution de PostgreSQL. **Quatre tests, dont deux qui n'existaient pas** : le ciblage lui-même n'était éprouvé nulle part — avec une seule caisse ouverte, le repli tombait juste par accident — et le câblage du singleton est vérifié pour lui-même, comme celui du registre des verrous |

---

## Conventions amendées

Deux fois, la relecture a montré que le code avait raison contre le document. Dans les deux cas c'est le document qui a été corrigé — l'inverse aurait consisté à dégrader le code pour le faire ressembler à sa description.

**L'audit des suppressions** est enregistré dans `->before()` et non dans `->after()` : l'enregistrement n'est plus lisible après sa suppression. `CLAUDE.md` a été corrigé en conséquence.

**L'immuabilité du brouillard n'a pas de fenêtre.** RG-05 et la règle 4 de `CLAUDE.md` annonçaient une correction directe possible jusqu'à l'arrêté de la journée. `MouvementCaisse` n'a jamais rien implémenté de tel : ses crochets `updating` et `deleting` refusent toute écriture, sans condition de date, et la contre-passation est la seule voie de correction depuis l'origine. Les deux documents ont été alignés le 22/08.

**Le parc ne comptait pas vingt-quatre boutiques, et la boutique n'était pas la maille de location.** Le relevé du village a établi dix-sept locaux de vente, numérotés B01 à B17, dont chacun abrite un ou plusieurs **espaces locatifs** — c'est l'espace qui se loue, et plusieurs artisans cohabitent couramment dans un même local. Le modèle initial attribuait la boutique : il refusait donc la cohabitation, qui est la situation ordinaire, et ne protégeait pas le partage d'un même espace, qui est la vraie faute. `CLAUDE.md` et `docs/modele-classes.md` ont été alignés le 23/08.

Le choix se défend en deux points. Une fenêtre de correction ferait dépendre l'immuabilité d'une donnée extérieure au mouvement — l'existence d'un arrêté sur sa journée — de sorte que la même ligne serait modifiable ou non selon le moment où on la regarde ; c'est une règle qu'on ne peut pas énoncer sans dire « ça dépend ». Et une correction directe, même parfaitement légitime, ne laisse aucune trace : le brouillard afficherait un chiffre juste sans montrer qu'il a été faux, alors qu'un journal de caisse existe précisément pour montrer les deux.

---

## Arbitrages à défendre

Dix décisions qui ne sont pas des dettes mais des choix, et qu'on demandera d'expliquer.

### A-01 — Redevance convenue, et non dérivée *(remplace l'arbitrage du 20/08)*

Cet arbitrage défendait l'inverse : `boutiques.redevance_mensuelle` était dérivée de `superficie × tarif_metre_carre`, recalculée par le modèle et matérialisée en colonne pour rester triable. Il est caduc depuis le 23/08, et il vaut d'être conservé sous cette forme parce que la façon dont il est tombé est instructive.

Le calcul n'était pas mal implémenté : il n'a **jamais produit un seul montant**. Le barème au mètre carré n'existe pas au village — la coordination négocie un forfait, local par local, entre 2 000 et 60 000 FCFA. Les deux colonnes sont restées nulles depuis l'origine, et le seeder disait déjà, en toutes lettres, qu'elles étaient « à renseigner depuis le barème de la coordination ». Une règle métier inventée par symétrie avec un modèle de référence, que rien dans les données ne venait démentir puisque rien ne venait la remplir non plus.

La redevance est désormais un montant convenu, porté par `attributions_espaces.redevance_convenue` en entier, figé sur le contrat, et borné par le modèle. Les bornes ne sont pas décoratives : rien ne rattraperait une faute de frappe après le figement.

Ce qui survit de l'arbitrage initial : le refus du `0` par défaut. `redevance_convenue` est obligatoire, il n'existe pas d'attribution gratuite implicite.

### A-02 — Le coordonnateur n'est pas le super-utilisateur

Le coordonnateur est un rôle métier adossé à une fonction de l'organigramme ; le super-utilisateur est un compte technique qui peut tout, y compris s'attribuer des droits et effacer des traces. Les fusionner permettrait à une personne exerçant une fonction administrative de modifier ses propres permissions — dans une structure qui manipule de l'argent public, c'est précisément ce qu'un contrôle interne cherche à empêcher.

En exploitation, le coordonnateur du village portera probablement les deux rôles. Ce sera une décision d'attribution explicite, jamais une propriété du modèle. `CLAUDE.md`, qui décrivait le coordonnateur comme super-utilisateur, a été corrigé.

### A-03 — Une trace se constate, elle ne se choisit pas

`attributions_boutiques.validee_par` prend le compte connecté au moment où la case « dossier complet » est cochée. Il n'existe **pas** de liste déroulante de validateurs : une trace qu'on peut attribuer à quelqu'un d'autre ne prouve rien.

Si le coordonnateur valide physiquement un dossier saisi par une secrétaire, la réponse n'est pas d'ouvrir un `Select` — c'est que la secrétaire ne doit pas avoir la permission de cocher la case. Même raisonnement que l'exception levée plutôt que le `false` silencieux sur le journal d'audit : un mécanisme de preuve qui se laisse contourner poliment n'est pas un mécanisme de preuve.

**Conséquence, implémentée depuis :** cocher « dossier complet » est gardé par une permission distincte de `modifier_attribution`. `valider_dossier_attribution` existe au seeder, n'est accordée qu'au coordonnateur, et la case n'apparaît qu'à qui la détient. `validee_par` reste hors de `$fillable` et `constaterValidationDuDossier()` prend `Auth::id()` : la trace se constate, elle ne se choisit ni ne se saisit.

*Relevé le 26/08 : ce paragraphe annonçait encore ce travail comme à faire alors qu'il était fait. Un document qui décrit une dette éteinte fait perdre autant de temps qu'un document qui en tait une.*

### A-04 — Suppressions : la trace décide

Ce qui porte une histoire ne se supprime pas, ce qui n'est qu'un libellé se corrige.

- **Verrouillé pour tous les rôles métier :** artisan, attribution, boutique, exercice, village. On désactive, on résilie, on clôture.
- **Ouvert à qui peut créer :** corps de métier, entreprise artisanale. Sans cela, un chef de section ayant saisi un doublon devrait appeler l'administrateur pour corriger sa propre erreur — et en pratique il contournerait, laissant traîner une ligne « à supprimer » qui pourrit le référentiel.

### A-05 bis — Le sous-sol et l'espace vert sont dans le parc *(remplace l'arbitrage du 23/08)*

Cet arbitrage défendait l'inverse, et il est conservé sous cette forme parce que la façon dont il est tombé se répète.

**Ce qu'il disait.** Le décompte de vingt-quatre « boutiques » qui circulait incluait le sous-sol et l'espace vert. Ni l'un ni l'autre n'est un local de vente : le sous-sol tient de la réserve et des locaux techniques, l'espace vert est une emprise extérieure. On en concluait qu'aucun des deux ne comportait d'espace locatif, et `BoutiqueSeeder` les écartait en affichant l'exclusion à chaque passage. L'enjeu n'était pas cosmétique : laisser sept emprises non louables gonfler le dénominateur donnait un village structurellement sous-occupé d'un tiers.

**Ce qui l'a renversé.** L'état de recouvrement des redevances 2026, transmis le 26/08, nomme trois espaces loués hors du bâtiment de vente : G0201 au sous-sol, occupé par la CNTC pour 60 000 FCFA par mois — la redevance la plus élevée de tout le parc — G0202 au sous-sol pour SCOOPS AAMRO à 10 000, et EV0101 sur l'espace vert à 5 000. Soit 75 000 FCFA de redevance mensuelle que le système ne voyait pas, et trois attributions qu'il aurait refusé d'enregistrer.

**Ce que la conclusion doit à sa prémisse.** Le raisonnement sur le dénominateur était juste et le reste. Ce qui était faux, c'est le fait sur lequel il s'appuyait — « ni l'un ni l'autre ne comporte d'espace locatif » — qui n'a jamais été vérifié contre une donnée du village, seulement déduit de ce que le sous-sol *est*. C'est la même mécanique que l'arbitrage A-01, où une redevance au mètre carré cohérente sur le papier n'a jamais produit un seul montant : une règle inventée par déduction, que rien ne venait démentir puisque rien ne venait la remplir.

**Ce qui a été retenu.** Les trois espaces entrent au parc. La table `boutiques` devient celle des contenants et porte une colonne `nature` — `BOUTIQUE`, `SOUS_SOL`, `ESPACE_VERT` — qui distingue les locaux de vente du reste du locatif. `RapportService::tauxOccupationEspaces()` accepte ce périmètre en argument : sans lui, il couvre tout ce qui se loue ; avec `NatureContenant::BOUTIQUE`, il retrouve exactement l'indicateur que la coordination présente à sa tutelle. Les deux chiffres existent, et aucun ne se déguise en l'autre.

**Ce que le nom ne dit plus.** `Boutique` désigne désormais un contenant qui peut ne pas être une boutique. Renommer le modèle, la table et leurs sept références à huit jours du gel coûterait plus que la gêne de lecture : c'est consigné plutôt que corrigé.

**À renseigner :** l'emplacement dans le bâtiment et la superficie des locaux, ainsi que le découpage de B13 et B17 — absents de l'état de recouvrement, qui ne mentionne que ce qui se facture. Le seeder les pose comme locaux sans espace connu, ce qui n'est pas la même chose que des locaux inexistants, plutôt que d'inventer un espace par local comme le faisait la version précédente.

### A-05 — `solde_apres` est un solde d'ordre de saisie, pas un solde à la date

Un mouvement antidaté reçoit le numéro d'ordre suivant et le solde courant **du moment où il est écrit**, non celui qu'aurait eu la caisse à sa date. Le brouillard se lit donc par numéro d'ordre, et la colonne y est cohérente de bout en bout ; l'arrêté journalier, lui, cumule par date et n'a aucune raison de retomber sur le `solde_apres` de la dernière ligne du jour.

Recalculer `solde_apres` de toutes les lignes suivantes à chaque insertion antidatée reviendrait à réécrire le journal, ce que l'immuabilité interdit. La colonne est donc une commodité de lecture chronologique de saisie — c'est ce qu'annonce la migration — et non un solde historique opposable. Le solde opposable est celui de l'arrêté.

Depuis la correction de RG-27, le cas est de toute façon devenu marginal : un mouvement ne peut plus viser une date antérieure au dernier arrêté sans être reporté au jour courant.

### A-06 — L'arrêté est unique par caisse, son solde théorique est calculé par section

`arretes_caisse` porte l'unicité `(caisse_id, date_arrete)` — un arrêté par caisse et par jour, comme l'exige RG-25 — mais `ServiceArreteCaisse::soldeTheorique()` ne cumule que les mouvements de la section passée en paramètre.

Les deux ne divergent que si une caisse voit deux sections se succéder le même jour, ce qu'une section couvrant un exercice rend très improbable. L'asymétrie est assumée plutôt que corrigée : cumuler par caisse obligerait à choisir un solde d'ouverture entre deux sections, et la question ne se pose réellement que le jour d'un changement d'exercice. À reprendre si la structure adopte plusieurs caisses simultanées — question encore ouverte au §8 de la spécification.

### A-07 — `origine_type` stocke un nom de classe court, pas une relation polymorphe

`MouvementCaisse.origine_type` reçoit `class_basename()` — `Vente`, `Reversement` — et non le nom qualifié d'une relation `morphTo`. Deux modèles homonymes dans deux modules deviendraient indiscernables.

Le choix tient à la règle de dépendance descendante : une relation polymorphe classique suppose que la Trésorerie connaisse les classes qu'elle référence, or elle reçoit ses origines de modules qui, eux, la connaissent. Un nom court suffit à identifier l'origine dans le brouillard et à la retrouver, sans que la Trésorerie ait à importer quoi que ce soit. La forme canonique — `morphTo` avec `Relation::enforceMorphMap()` — reste ouverte si le nombre d'origines augmente.

### A-08 — Le décaissement a lieu à la validation de la campagne, pas au passage de l'artisan

La variante a été écrite, essayée, puis retirée le 26/08. Elle mérite d'être racontée parce que ce qui l'a fait tomber n'est pas une difficulté technique mais une garantie qu'elle coûtait.

**Ce qui a été tenté.** `valider()` ne faisait plus que rattacher les ventes et refermer la campagne ; le décaissement passait dans une méthode `payerReversement()`, appelée artisan par artisan au moment où il se présente au guichet. L'argument est réel : RG-18 exige « un reçu signé par l'artisan », donc une présence physique, et la date portée en caisse devenait la date effective du paiement au lieu de la date administrative de validation. Sur ce seul point, la variante est plus juste.

**Ce qu'elle coûtait.** Le décaissement sortait de la transaction qui rattache les ventes. Or c'est exactement la garantie que la classe annonce en tête : le rattachement est posé *avant* les décaissements, dans la même transaction, pour que l'échec d'une écriture en caisse emporte le rattachement avec lui. Sans cela le scénario redouté redevient possible — des ventes marquées « reversées » sans que l'argent soit sorti, qui ne réapparaissent dans aucune campagne suivante puisque `preparer()` ne retient que les ventes non rattachées.

Deux conséquences en découlaient, qui n'ont pas été vues sur le moment. `ServiceCompteArtisan::totalReverse()` somme `montant_paye` sur toutes les campagnes validées, sans regarder le statut du reversement : un reversement resté `A_PAYER` faisait donc tomber le solde dû de l'artisan à zéro alors que l'argent était encore en caisse — précisément le piège que le commentaire de la méthode décrit un cran plus tôt, pour les campagnes en préparation. Et `payerReversement()` n'a jamais reçu d'écran : la chaîne vente → reversement → argent sorti était coupée, aucun artisan n'étant payable par l'interface.

**Ce qui a été retenu.** La validation atomique. Un décaissement par bénéficiaire, à l'intérieur de la transaction, `montant_total` conservé depuis la préparation — ce que la validation décaisse est exactement ce que l'état récapitulatif signé annonçait. Le défaut de `totalReverse()` disparaît de lui-même : tout reversement d'une campagne validée est payé.

`payerReversement()` reste dans le fichier, non branchée et documentée comme telle. Sa garde de statut la rend inerte après une validation normale, un double décaissement est donc impossible. C'est la perspective : un village qui paierait ses artisans au fil de leurs passages plutôt qu'en une séance mensuelle demanderait ce mode, et il faudrait alors sortir le rattachement de la validation pour le porter, artisan par artisan, sur le paiement — c'est-à-dire déplacer la garantie, pas la supprimer.

**La leçon de méthode.** Ce changement de contrat métier a vécu vingt-quatre heures sur le disque sans commit et sans que la suite de tests soit relancée. C'est elle qui l'a rattrapé, le 26/08, par cinq échecs dont quatre dans `CampagneReversementTest` — les tests décrivaient encore le contrat que le code venait d'abandonner. La règle 5 du rétroplanning (commiter et pousser chaque soir) n'est pas une précaution de sauvegarde : c'est le moment où l'on relance les tests.

### A-09 — L'alerte de rupture part aux deux sections, pas à l'artisan

La règle 15 nomme trois destinataires : l'artisan, la section Production, la section Commercialisation. Le branchement du 26/08 en sert deux.

**Ce n'est pas un oubli, c'est une absence de canal.** Un artisan n'a pas de compte dans le système : `artisans` ne porte aucun lien vers `users`, et le panneau artisan de la règle 12 n'est pas construit (DT-07). Il n'existe donc aucun endroit où une notification lui serait remise. Les deux substituts imaginables ont été écartés. Le courriel suppose une adresse : le registre importé en renseigne une fraction, et le village n'a pas de service d'envoi configuré — une alerte qui échoue silencieusement est pire qu'une alerte absente, parce qu'on croit l'avoir envoyée. Le SMS suppose un fournisseur, donc un abonnement et une dépendance externe, à huit jours du gel.

**Ce qui a été fait à la place.** Le nom de l'artisan et le numéro de sa boutique sont portés dans le corps du message, avec la quantité restante et le seuil. La section qui lit l'alerte sait qui appeler et où aller. C'est la forme que prend l'information dans un village où l'artisan est joignable de vive voix et vient chercher lui-même ses ventes — et c'est, en pratique, ce qui se passait déjà, sans que le système le déclenche.

**Ce qu'il faudrait pour fermer la règle.** Un compte par artisan et un panneau où le lire, c'est-à-dire DT-07. Le jour où ils existent, le seul changement est la méthode `destinataires()` de l'écouteur : le reste de la chaîne — émission au franchissement, format, canal — est déjà en place et déjà éprouvé.

**Le point de méthode.** L'événement `SeuilAlerteFranchi` était écrit, correctement émis, et couvert par cinq tests d'émission depuis la première tranche du module Commerce. Aucun ne pouvait révéler que personne ne l'écoutait : ils vérifiaient qu'il partait, pas qu'il arrivait. Une règle métier n'est tenue que lorsqu'un test décrit son effet observable — ici, une ligne dans `notifications` pour un compte identifié.

---

### A-10 — Le portail public passe de « maquetté » à « livré »

`docs/retroplanning.md` range le portail parmi les éléments **« maquettés et démontrés »** : quatre pages, en local. Le 28/08, il est repris comme un livrable à part entière — huit pages, une identité visuelle, les photographies du village. **Ce dépassement de périmètre est délibéré et il doit être défendu comme tel**, faute de quoi le rapport annonce un niveau d'engagement que le code contredit.

**Ce qui le justifie.** La règle 2 du rétroplanning dit qu'un jour de retard se compense par une coupe, et que la coupe porte sur le portail. Cette règle a été respectée : le portail n'a été repris qu'une fois la chaîne vente → brouillard → compte artisan → reversement livrée et testée, la fonctionnalité IA choisie, mesurée et documentée, et le tableau de bord en service. Aucun de ces chantiers n'a été écourté pour financer celui-ci. Le portail est donc payé sur la marge, pas sur le reste.

**Ce qui a rendu la reprise peu coûteuse.** Le service, les règles de visibilité et les contrôleurs existaient déjà et n'ont pas bougé : `ServicePortail` porte toujours les trois conditions de publication, la fiche d'un produit invisible répond toujours 404, aucune quantité de stock n'a jamais quitté le service. Ce qui a changé est la couche de présentation — un gabarit, huit vues, deux composants — plus deux lectures ajoutées au service (`village()` et `reperes()`). Le risque de régression métier est faible par construction, et `PortailPublicTest` le retient inchangé.

**Ce qui reste hors périmètre, et pourquoi.** Pas de commande, pas de paiement, pas de compte visiteur : CLAUDE.md l'énonce deux fois, et le pied de page le dit au visiteur plutôt que de le laisser chercher un panier qui n'existera jamais. Pas de page « actualités » ni « formations » : elles supposent un flux éditorial que la coordination n'a pas les moyens de tenir, et une rubrique d'actualités figée à la date de la soutenance vieillit plus mal qu'une rubrique absente.

**Ce qui reste vrai du classement d'origine.** Le portail est complet **en local**. Sa mise en service suppose un nom de domaine et un hébergement mutualisé, non budgétés à ce jour — c'est la section 5 du rétroplanning, et elle ne change pas.
