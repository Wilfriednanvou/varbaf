# Dette technique assumée

Écarts connus entre la conception et l'implémentation, décidés volontairement sous contrainte de délai. Ce document alimente la section « limites et perspectives » du rapport.

---

| # | Écart | Motif | Traitement prévu |
|---|---|---|---|
| DT-01 | `Exercice::cloturer()` ne vérifie ni les sections de caisse clôturées ni les campagnes de reversement validées | Les modules Trésorerie et Commerce n'existaient pas au moment de l'écriture | À réarmer à la fin du module 4 (Trésorerie) |
| DT-02 | Aucun contrôle de cohérence inter-villages sur les attributions | Le système ne gère qu'un village en exploitation ; le contrôle protège un scénario non atteignable | Version 2, si le déploiement s'étend à d'autres villages |
| DT-03 | `SessionConnexion` non implémentée | Le journal d'audit couvre le besoin de traçabilité | Retirée du périmètre |
| DT-04 | `EcheanceRedevance` et `PaiementRedevance` non implémentées | Retirées du périmètre par arbitrage de charge ; les redevances restent encaissables comme mouvement de caisse ordinaire | Version 2 |
| DT-05 | Méthodes de modèle écrites mais non appelées (`estDisponible()`, `getAttributionActive()`, `getHistoriqueAttributions()`, etc.) | Écrites pour les modules suivants qui les consommeront | À couvrir par les modules Commerce, Trésorerie et Pilotage |
| DT-06 | Requête N+1 sur la colonne « occupant » de la table des boutiques | Parc de 24 boutiques : impact nul à cette échelle | À optimiser si le parc s'étend |
| DT-07 | Panneau artisan et couche de notification classés « maquetté » | Contrainte de délai — remise au 5 septembre | Version 2 |
| DT-08 | Aucune Policy déclarée : la couche d'autorisation de Filament autorise tout compte du panneau. La sécurité repose entièrement sur le `->visible()` porté par chaque action | Les `->visible()` sont exhaustifs et vérifiés ; ajouter sept Policies avant le gel du code coûterait plus qu'il ne protégerait | Le comportement est figé par `tests/Feature/HabilitationArtisanTest.php`, qui échouera si une Policy est ajoutée — signal explicite pour reprendre ce point |
| DT-09 | `date_debut_facturation` est recalculée à chaque écriture. Corriger la date d'entrée d'une attribution déplace donc le mois offert, y compris si une redevance a déjà été encaissée sur la période | Le recalcul est le comportement juste tant qu'aucun encaissement n'existe — cas de tous les contrats saisis à ce jour | Garde-fou à poser côté Trésorerie : refuser la modification de `date_debut` dès qu'un paiement de redevance est rattaché à l'attribution |
| DT-10 | Le journal d'audit est immuable au niveau du modèle, mais une suppression de masse par le constructeur de requêtes (`JournalAudit::query()->delete()`) ou un `DELETE` SQL direct passe outre | Fermer la porte demanderait un déclencheur PostgreSQL ou le retrait du droit `DELETE` au rôle applicatif | À poser au déploiement, avec la configuration de la base de production |

---

## Écarts corrigés

| Date | Écart | Correction |
|---|---|---|
| 20/08 | Règles métier situées dans l'interface (artisan inactif, immuabilité du journal d'audit, boutique indisponible) | Déplacées dans les modèles via crochets Eloquent |
| 20/08 | Attribution rattachable à un exercice clôturé | Contrôle ajouté au modèle |
| 20/08 | `FileUpload` de la photo sans disque explicite | `->disk('public')` sur le champ **et** sur la colonne, plus `storage:link` |
| 20/08 | Checklist « test avec un utilisateur non super-utilisateur » jamais exécutée sur trois commits | `tests/Feature/HabilitationArtisanTest.php` |

---

## Convention amendée

L'audit des **suppressions** est enregistré dans `->before()` et non dans `->after()` : l'enregistrement n'est plus lisible après sa suppression. `CLAUDE.md` a été corrigé en conséquence — le code avait raison contre la convention initiale.

---

## Arbitrages à défendre

Trois décisions qui ne sont pas des dettes mais des choix, et qu'on demandera d'expliquer.

### A-01 — Redevance dérivée mais matérialisée

`boutiques.redevance_mensuelle` découle de `superficie × tarif_metre_carre` (règle 12). Elle est **recalculée par le modèle à chaque écriture et jamais saisie**, mais reste stockée en colonne plutôt que réduite à un accesseur : l'écran du parc trie dessus et les futurs échéanciers la requêteront en SQL. Dérivée sur le plan métier, matérialisée sur le plan technique.

Corollaire assumé : tant que la superficie **ou** le tarif manque, la redevance vaut **`null`, jamais `0`**. Une boutique dont on ignore la surface n'est pas une boutique gratuite, et l'écran affiche « À calculer » au lieu d'un montant faux qui se propagerait dans les états.

### A-02 — Le coordonnateur n'est pas le super-utilisateur

Le coordonnateur est un rôle métier adossé à une fonction de l'organigramme ; le super-utilisateur est un compte technique qui peut tout, y compris s'attribuer des droits et effacer des traces. Les fusionner permettrait à une personne exerçant une fonction administrative de modifier ses propres permissions — dans une structure qui manipule de l'argent public, c'est précisément ce qu'un contrôle interne cherche à empêcher.

En exploitation, le coordonnateur du village portera probablement les deux rôles. Ce sera une décision d'attribution explicite, jamais une propriété du modèle. `CLAUDE.md`, qui décrivait le coordonnateur comme super-utilisateur, a été corrigé.

### A-03 — Une trace se constate, elle ne se choisit pas

`attributions_boutiques.validee_par` prend le compte connecté au moment où la case « dossier complet » est cochée. Il n'existe **pas** de liste déroulante de validateurs : une trace qu'on peut attribuer à quelqu'un d'autre ne prouve rien.

Si le coordonnateur valide physiquement un dossier saisi par une secrétaire, la réponse n'est pas d'ouvrir un `Select` — c'est que la secrétaire ne doit pas avoir la permission de cocher la case. Même raisonnement que l'exception levée plutôt que le `false` silencieux sur le journal d'audit : un mécanisme de preuve qui se laisse contourner poliment n'est pas un mécanisme de preuve.

**Conséquence non encore implémentée :** le fait de cocher « dossier complet » n'est aujourd'hui gardé par aucune permission distincte — quiconque peut modifier une attribution peut se déclarer validateur. Une permission `valider_dossier_attribution` reste à créer.

### A-04 — Suppressions : la trace décide

Ce qui porte une histoire ne se supprime pas, ce qui n'est qu'un libellé se corrige.

- **Verrouillé pour tous les rôles métier :** artisan, attribution, boutique, exercice, village. On désactive, on résilie, on clôture.
- **Ouvert à qui peut créer :** corps de métier, entreprise artisanale. Sans cela, un chef de section ayant saisi un doublon devrait appeler l'administrateur pour corriger sa propre erreur — et en pratique il contournerait, laissant traîner une ligne « à supprimer » qui pourrit le référentiel.
