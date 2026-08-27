# VARBAF — contexte de reprise

**Instantané du 27 août 2026, vers 03 h 15.** À coller en tête d'une nouvelle conversation. Ce document périme vite : `docs/journal-de-bord.md` et `docs/dette-technique.md` font foi dès qu'ils divergent de lui.

---

## Le projet

**VARBAF** — ERP du Village Artisanal Régional de Bafoussam. Stage académique.

- **Remise : 5 septembre 2026.** Gel du code officiel le 3 septembre, **gel effectif décidé au 31 août** (avancer est plus strict, jamais plus laxiste).
- Tout est en **français** : interface, libellés, commentaires, messages de commit.
- Stack : PHP 8.3, Laravel 12, Filament 5 (panneau `admin`), `nwidart/laravel-modules`, `spatie/laravel-permission`, PostgreSQL 14+, `barryvdh/laravel-dompdf`.
- Dépôt : `~/Desktop/varbaf`, branche `master`, distant à jour.

### Six modules, dépendance strictement descendante

Socle (1) → Artisanat (2) → Commerce (3) → Tresorerie (4) → Pilotage (5) → Portail (6).

**Un module ne référence que les modules dont il dépend. Aucune dépendance montante, jamais.** Pour franchir une frontière : le *consommateur* déclare l'interface, le *fournisseur* l'implémente et la lie. Trois exemples en service — `Commerce\Contracts\JournalDeCaisse` (lié par la Trésorerie), `Socle\Contracts\VerrouDeCloture` (registre où la Trésorerie vient se déclarer), `Pilotage\Contracts\FournisseurDEmbeddings` (le même idiome appliqué à une dépendance externe).

### À lire avant d'agir

| Fichier | Contenu |
|---|---|
| `CLAUDE.md` | 15 règles métier non négociables, conventions Filament, permissions, audit |
| `docs/dette-technique.md` | Dettes ouvertes, arbitrages, et « Écarts corrigés » tenu au fil de l'eau |
| `docs/retroplanning.md` | Périmètre arbitré et les 5 règles de conduite du planning |
| `docs/specification-tresorerie.md` | RG-01 à RG-27 |
| `docs/journal-de-bord.md` | Notes du soir — matière du chapitre réalisation |
| `docs/questions-coordination.md` | 7 questions en attente, avec l'hypothèse retenue à défaut de réponse |

Règles de conduite qui ont réellement servi : **1.** le gel est intangible ; **2.** un jour de retard se compense par une coupe, jamais par une nuit blanche ; **3.** écrire chaque soir.

---

## Contrainte d'environnement — importante

**`device_bash` est indisponible.** Toute lecture passe par `device_stage_files`, toute écriture par `SendUserFile` puis `device_commit_files`. **L'utilisateur exécute lui-même chaque commande** et colle la sortie.

Deux pièges vérifiés :

- Son terminal est **Git Bash (MINGW64)** sur Windows — pas PowerShell.
- Git Bash ne sait pas piper la sortie d'un binaire natif (« stdout is not a tty ») : utiliser `php artisan test --compact`, **jamais** `| tail`.

**Avant de modifier un fichier, le re-stager.** Une autre session a écrit dans le même dépôt cette nuit ; travailler sur une copie de plus de quelques minutes fait écraser silencieusement le travail d'autrui (voir plus bas).

---

## État au moment de la reprise

`HEAD = d0f3267`, poussé. **L'arbre de travail n'est pas propre** — quatre lots distincts y cohabitent :

1. **Y7, fait cette nuit.** `ServiceTresorerie::$sectionCible` était un état *statique* relâché par le `finally` de l'appelant. Remplacé par une propriété d'instance et une méthode `pour(SectionCaisse, callable)` qui pose, exécute et relâche dans son propre `finally`. La section précédente est restaurée, pas effacée (ciblages imbriqués). `ServiceTresorerie` devient **singleton du conteneur**, sans quoi le ciblage porterait sur une instance et l'écriture sur une autre. `resoudreSectionOuverte()` reçoit un `orderBy('id')` explicite. Quatre tests ajoutés à `SessionCaisseTest`, dont deux qui n'existaient pas : **le ciblage n'était éprouvé nulle part** — avec une seule caisse ouverte le repli tombait juste par accident.
2. **Deux documents neufs** : `docs/journal-de-bord.md` (entrée du 26/08) et `docs/questions-coordination.md`.
3. **Une couche de recherche dense/hybride, écrite par une autre session** entre 02:10 et 02:21 : `FournisseurDEmbeddings`, `ClientOllama` (embeddings locaux, `nomic-embed-text`), `MoteurDense`, `MoteurHybride`, `FusionReciproque` (RRF, k=60), `ServiceIndexationDense`, `IndexerVecteursCommand`, migration `2026_08_27_500300_ajouter_le_vecteur_dense_aux_fiches`, `tests/Doubles/`, `RechercheHybrideTest`. `pilotage.moteur.ordre` vaut désormais `['hybride', 'lexical']`.
4. **Un correctif de nommage**, livré juste après : `MoteurHybride::voisins()` délègue au seul lexical **par conception** (les exclusions métier du voisinage sont en SQL et ne se rejouent pas sur un index en mémoire), mais `nom()` était partagé avec `rechercher()`. Le portail aurait annoncé « Hybride — lexical ⊕ dense » sous des suggestions que le dense n'a jamais vues, **le jour où Ollama tourne**. `nomDuVoisinage()` ajouté à `MoteurSemantique`, implémenté par le lexical et l'hybride, appelé par `ServiceRecommandationProduit`.

### Ce qui reste à faire tout de suite

```bash
php artisan test --compact
```

Le dernier passage donnait **2 échecs, 395 réussis** — les deux échecs sont ceux que le correctif de nommage adresse, et il n'a pas été rejoué depuis. La migration dense est appliquée. **L'index dense n'a jamais été construit** et on ne sait pas si Ollama est installé sur la machine.

Puis quatre commits séparés — Y7, les deux documents, la branche dense, le correctif de nommage. Ne pas les mélanger : le travail de l'autre session mérite le sien.

---

## Décisions en cours

**L'hybride local + Grok, demandé et pas encore livré.** L'utilisateur veut un modèle de langage local complété par une clé d'API **Grok** pour les tâches complexes. Conception arrêtée, code écrit mais **non livré sur sa machine** :

- Port `Modules\Pilotage\Contracts\ModeleDeLangage` — volontairement étroit : `redigerDepuisExtraits()` et `classer()`, rien de générique. **La forme du port est la contrainte : le modèle ne produit jamais un chiffre.** Les montants viennent de `RapportService`, par calcul, et de nulle part ailleurs.
- `ModeleIndisponible` — objet nul, pour que le chemin dégradé soit le *même code* que le nominal.
- `ResolveurDeModele` — rend une **chaîne** d'escalade (`['local', 'distant']`), l'appelant décide d'escalader sur une condition mécanique : routeur sous son seuil, recherche vide, délai dépassé. Budget de temps 8 s.
- API xAI : `https://api.x.ai/v1/chat/completions`, `Authorization: Bearer`, modèles `grok-4.6` / `grok-4`, corps compatible OpenAI.
- `GardeDesChiffres` existe déjà et relit mécaniquement toute réponse rédigée : c'est ce qui rend la rédaction générative tenable, et il n'a pas eu à changer.

**Deux questions sans réponse :** Ollama est-il installé, et avec quel modèle ? Et le périmètre — la rédaction seule, ou la rédaction plus le rattrapage du routage ?

**Coût annoncé :** environ deux jours, qui mangent le 28 et le 29. La coupe proposée en contrepartie : DT-09 redevient une limite assumée, `docs/modele-classes.md` glisse au 2 septembre.

### Dettes encore ouvertes

- **DT-09** — aucun garde-fou sur `date_debut` quand une redevance est encaissée. Facultatif.
- **DT-12** — cumul saisie / clôture en caisse ; c'est la question 6 à la coordination.
- `docs/donnees/README.md` décrit encore le registre supprimé le 26.
- `docs/modele-classes.md` n'a pas suivi le changement de parc du 26.

### En attente d'une information du village

`Mme Justina` (190 000 F de ventes, aucun espace à son nom) et « Bijoux en perles » (hors des quatorze secteurs). Les deux sont dans `docs/questions-coordination.md` avec leur hypothèse de repli.

---

## Le volet IA tel qu'il existe

L'assistant a **deux branches et une frontière qui ne se franchit jamais** : une question d'agrégation part vers `RapportService` et se résout par calcul déterministe ; une question descriptive part vers la recherche et n'a **aucun** accès aux indicateurs. Aucun montant ne peut être produit par proximité textuelle.

Trois garde-fous : rien sous le seuil de similarité ; `GardeDesChiffres` bascule en refus si un nombre n'apparaît dans aucun extrait ; les sources accompagnent toujours la réponse. S'y ajoutent la recommandation de produits, l'analyse du catalogue (produits isolés, segments saturés) et la commande `varbaf:evaluer-assistant` qui mesure classification, rappel@5 et **taux de refus correct**.

---

## Le jeu de données

Repris le 26 août depuis trois documents réels du village. **603 lignes, 602 ventes importées.** Vérifié en console : CA 2 021 350 F, commission 202 135 F (exactement 10 %), part artisan 1 819 215 F (la somme retombe sur le CA), entrées en caisse 2 021 350 F, 1 204 mouvements de stock (602 × 2), 24 attributions pour 115 000 F de redevance.

Le sous-sol et l'espace vert font partie du parc locatif depuis le 26 (G0201 loué à la CNTC pour 60 000 F, la redevance la plus élevée du village). La colonne `nature` sur les contenants porte la distinction ; le taux d'occupation présenté à la tutelle se calcule sur les seules boutiques.

**Ce qui n'a délibérément pas été fait :** les catégories des 285 produits, faute de source. Une catégorie devinée ne se distinguerait plus d'une catégorie relevée.

---

## Deux leçons de méthode qui ont coûté cher

**Une règle inventée par déduction ne se fait jamais démentir tant que rien ne la remplit.** Deux fois : la redevance au mètre carré, qui n'a jamais produit un montant ; et « le sous-sol ne comporte aucun espace locatif », déduit de ce que le sous-sol *est*, jamais vérifié contre une donnée.

**La suite complète attrape ce que la suite filtrée laisse passer.** Un bug qui cassait toutes les pages du panneau (`notifications.data` en `text`, que la cloche de Filament interroge en JSON) n'existait, du point de vue des tests, que dans deux fichiers d'un module sans rapport.

---

## Conventions de travail avec l'utilisateur

- Il écrit court et va vite. Il décide ; ne pas multiplier les questions — annoncer un défaut raisonné et avancer.
- Il n'aime pas les questionnaires à choix multiples : poser la question en clair, en une ligne.
- Style de code du projet : commentaires denses en français qui expliquent **pourquoi**, pas quoi. Nommer les règles (RG-xx, DT-xx, A-xx). Les tests portent le raisonnement dans leur docblock.
- Messages de commit en français, sans accents, à l'infinitif ou à la troisième personne, décrivant l'intention et non le fichier touché.
