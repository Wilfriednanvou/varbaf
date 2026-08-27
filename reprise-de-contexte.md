# VARBAF — contexte de reprise

**Instantané du 27 août 2026, en fin de journée.** À coller en tête d'une nouvelle conversation. Ce document périme vite : `docs/journal-de-bord.md` et `docs/dette-technique.md` font foi dès qu'ils divergent de lui.

---

## Le projet

**VARBAF** — ERP du Village Artisanal Régional de Bafoussam. Stage académique.

- **Remise : 5 septembre 2026.** Gel du code officiel le 3 septembre, **gel effectif décidé au 31 août**.
- Tout est en **français** : interface, libellés, commentaires, messages de commit.
- Stack : PHP 8.3, Laravel 12, Filament 5 (panneau `admin`), `nwidart/laravel-modules`, `spatie/laravel-permission`, PostgreSQL 14+, `barryvdh/laravel-dompdf`.
- Dépôt : `~/Desktop/varbaf`, branche `master`, distant à jour.

### Six modules, dépendance strictement descendante

Socle (1) → Artisanat (2) → Commerce (3) → Tresorerie (4) → Pilotage (5) → Portail (6).

**Un module ne référence que les modules dont il dépend. Aucune dépendance montante, jamais.** Pour franchir une frontière : le *consommateur* déclare l'interface, le *fournisseur* l'implémente et la lie. Quatre exemples en service — `Commerce\Contracts\JournalDeCaisse`, `Socle\Contracts\VerrouDeCloture`, `Pilotage\Contracts\FournisseurDEmbeddings`, `Pilotage\Contracts\ModeleDeLangage`.

### À lire avant d'agir

| Fichier | Contenu |
|---|---|
| `CLAUDE.md` | 15 règles métier non négociables, conventions Filament, permissions, audit |
| `docs/dette-technique.md` | Dettes ouvertes, arbitrages, et « Écarts corrigés » |
| `docs/retroplanning.md` | Périmètre arbitré et les 5 règles de conduite du planning |
| `docs/specification-tresorerie.md` | RG-01 à RG-27 |
| `docs/journal-de-bord.md` | Notes du soir — matière du chapitre réalisation |
| `docs/questions-coordination.md` | 7 questions en attente, avec l'hypothèse retenue à défaut |

---

## Contrainte d'environnement — importante

**`device_bash` est indisponible.** Toute lecture passe par `device_stage_files`, toute écriture par `SendUserFile` puis `device_commit_files`. **L'utilisateur exécute lui-même chaque commande** et colle la sortie.

Trois pièges vérifiés :

- Son terminal est **Git Bash (MINGW64)** sur Windows — pas PowerShell.
- Git Bash ne sait pas piper la sortie d'un binaire natif : utiliser `php artisan test --compact`, **jamais** `| tail`.
- **PHP sous Windows n'embarque aucun magasin de certificats racine.** Réglé le 27/08 par `curl.cainfo` et `openssl.cafile` dans `C:\php-8.3.30-Win32-vs16-x64\php.ini`, pointant sur `extras\ssl\cacert.pem`. Sans cela, tout appel HTTPS sortant échoue en `cURL error 60` — et le repli l'avale en silence. À refaire sur le poste du village.

**Avant de modifier un fichier, le re-stager.**

---

## État au moment de la reprise

`HEAD = 8ca5b81`, poussé, **arbre propre**. **407 tests au vert**, 1073 assertions.

Reste à faire immédiatement : sortir `storage/evaluation/` du suivi Git (les CSV versés vivent dans `docs/donnees/evaluation/`).

---

## Le volet IA tel qu'il existe

**Deux branches et une frontière qui ne se franchit jamais.** Une question d'agrégation part vers `RapportService` et se résout par calcul déterministe ; une question descriptive part vers la recherche et n'a **aucun** accès aux indicateurs. Aucun montant ne peut être produit par proximité textuelle.

**Trois garde-fous** : rien sous le seuil de similarité ; `GardeDesChiffres` bascule en refus si un nombre n'apparaît dans aucun extrait ; les sources accompagnent toujours la réponse.

**Rédaction générative** (27/08). Le port `ModeleDeLangage` n'expose qu'une opération : mettre en français suivi des extraits **déjà retrouvés**. Le modèle ne cherche rien, ne calcule rien, ne voit aucun indicateur. `classer()` a été délibérément retiré du port — le routage est en amont de la frontière. Sans clé ni modèle, `ModeleIndisponible` rend `null` et l'assistant liste les extraits : c'est le comportement d'origine, et il est parcouru par toute la suite de tests.

Une seule classe, `ClientCompatibleOpenAI`, sert tous les fournisseurs (même dialecte `POST {url}/v1/chat/completions`). Deux profils, `local` (Ollama) et `distant` (**Groq**, palier gratuit), ordre `['local', 'distant']` — le local devant parce qu'il fonctionne sans réseau le jour de la soutenance. Changer de fournisseur est un changement de `.env`.

**Branche dense construite, mesurée, écartée** le 27/08. Ollama + `nomic-embed-text`, index à 100 % (325 fiches, 768 dimensions). Elle est moins bonne au rappel que le lexical **et** fait tomber le refus correct de 100 % à 0 %. `pilotage.moteur.ordre` vaut `['lexical']` ; le dense et l'hybride restent au catalogue comme instruments de mesure, et un test retient la décision. Motif chiffré en commentaire dans `Modules/Pilotage/config/config.php`.

### Mesures — table 4.3 du rapport

Sur les 48 questions de `Modules/Pilotage/resources/evaluation/questions.csv` :

| Moteur | Classification | Rappel@5 | Refus correct |
|---|---|---|---|
| lexical | 100,0 % | **70,0 %** | **100,0 %** |
| mots_cles | 100,0 % | 60,0 % | 100,0 % |
| dense | 100,0 % | 60,0 % | 0,0 % |
| hybride | 100,0 % | 70,0 % | 0,0 % |

H3 : +10 points pour la pondération TF-IDF sur le témoin par mots-clés.

**Une série antérieure, à 20 % partout, figure aussi au dépôt et dans le journal.** Elle est fausse : le rappel était mesuré contre les seuls titres des sources, où le corps de métier d'une fiche produit ne figure jamais. Elle est conservée délibérément — une mesure qu'on corrige se montre. Le rapport doit porter les deux séries.

---

## Ce qui reste

- **Deux documents faux** : `docs/donnees/README.md` décrit le registre supprimé le 26 ; `docs/modele-classes.md` n'a pas suivi le changement de parc.
- **DT-09** — aucun garde-fou sur `date_debut` quand une redevance est encaissée. Facultatif, assumé.
- **DT-12** — cumul saisie / clôture en caisse ; question 6 à la coordination.
- **En attente du village** : `Mme Justina` (190 000 F de ventes, aucun espace) et « Bijoux en perles » (hors des quatorze secteurs). Voir `docs/questions-coordination.md`.
- La clé Groq du 27/08 a circulé en clair dans une conversation : à révoquer et remplacer.

---

## Trois leçons de méthode qui ont coûté cher

**Une règle inventée par déduction ne se fait jamais démentir tant que rien ne la remplit.** Trois fois : la redevance au mètre carré ; « le sous-sol ne comporte aucun espace locatif » ; les préfixes de tâche de `nomic-embed-text`. La troisième est tombée en quatre minutes — parce qu'on avait écrit la sonde qui pouvait la démentir.

**La suite complète attrape ce que la suite filtrée laisse passer.**

**Quelque chose qui a l'air de vérifier peut regarder ailleurs.** Huit cas le 27/08 : un instrument de mesure aveugle aux moteurs qu'on venait d'ajouter, deux tests qui affirmaient une valeur de configuration, une hypothèse jamais éprouvée, un identifiant de modèle périmé depuis onze jours, un échec TLS avalé par le repli, une suite de tests sur le point d'appeler le réseau, et un indicateur qui mesurait une propriété des titres. Aucun ne s'est signalé tout seul. **Le repli doit être silencieux pour l'utilisateur et bavard pour le développeur.**

---

## Conventions de travail avec l'utilisateur

- Il écrit court et va vite. Il décide ; ne pas multiplier les questions — annoncer un défaut raisonné et avancer.
- Il n'aime pas les questionnaires à choix multiples : poser la question en clair, en une ligne.
- Style de code du projet : commentaires denses en français qui expliquent **pourquoi**, pas quoi. Nommer les règles (RG-xx, DT-xx, A-xx). Les tests portent le raisonnement dans leur docblock.
- Messages de commit en français, sans accents, à l'infinitif ou à la troisième personne, décrivant l'intention et non le fichier touché.
