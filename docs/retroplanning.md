# Rétroplanning — remise le 5 septembre 2026
## Projet VARBAF — 16 jours restants au 20 août

---

## 1. Périmètre arbitré

Trois niveaux d'engagement. Ce classement doit apparaître tel quel dans le rapport : il transforme une contrainte de temps en choix de versionnement assumé.

### Livré et fonctionnel

| Module | Contenu |
|---|---|
| Socle et sécurité | Village, exercice, utilisateurs, rôles, permissions, journal d'audit |
| Artisans et boutiques | Artisans, corps de métier, boutiques, attributions |
| Commerce | Catégories, produits, dépôts et journal de stock, ventes avec commission |
| Trésorerie | Caisse, section, brouillard, compte artisan, campagnes de reversement |
| Pilotage | Tableau de bord avec indicateurs alimentés par les données réelles |
| IA | **Une seule** fonctionnalité, complète et démontrable |

### Maquetté et démontré

| Élément | Niveau attendu |
|---|---|
| Portail public | Pages accueil, catalogue, fiche artisan, contact — en local |
| Espace artisan | Deux écrans en lecture seule : mon solde, mes ventes |
| Notifications | Canal e-mail uniquement, un seul modèle de message |

### Documenté en version 2

Formations et inscriptions, réservation d'espaces, événements, échéancier et relances des redevances, canal WhatsApp, sessions de caisse journalières.

**Retiré du périmètre :** l'échéancier automatisé des redevances. Les redevances restent encaissables comme mouvement de caisse ordinaire.

---

## 2. Planning jour par jour

### Phase 1 — Collecte et fondations (20 au 23 août)

| Jour | Terrain et rapport | Développement |
|---|---|---|
| 20 août | Photographier registres et supports. Transcrire le registre de ventes dans un tableur | Installer l'environnement : Laravel, PostgreSQL, Filament, modules Nwidart, spatie |
| 21 août | Transcrire la liste des artisans et redevances. Récupérer décret et organigramme | Module Socle : village, exercice, utilisateurs, rôles, permissions |
| 22 août | Rédiger le chapitre présentation de la structure | Module Socle : journal d'audit, gabarit de ressource de référence |
| 23 août | Rédiger le chapitre analyse de l'existant | Module Artisans et boutiques : entités et CRUD |

**Livrable de fin de phase :** environnement opérationnel, deux chapitres rédigés, données réelles saisies dans un tableur.

### Phase 2 — Cœur métier (24 au 30 août)

| Jour | Rapport | Développement |
|---|---|---|
| 24 août | — | Attributions de boutiques, seeders alimentés par les données réelles |
| 25 août | Chapitre cahier des charges | Module Commerce : catégories, produits |
| 26 août | — | Dépôts et journal de stock |
| 27 août | — | Écran de vente : recherche produit, calcul de commission, figement |
| 28 août | Chapitre conception : diagrammes | Module Trésorerie : caisse, section, brouillard |
| 29 août | — | Compte artisan, calcul du solde dû |
| 30 août | — | Campagnes de reversement, reçus imprimables |

**Livrable de fin de phase :** chaîne complète vente → brouillard → compte artisan → reversement, testée sur données réelles.

### Phase 3 — Différenciation (31 août au 2 septembre)

| Jour | Rapport | Développement |
|---|---|---|
| 31 août | Chapitre réalisation | Tableau de bord et indicateurs |
| 1er sept | — | Fonctionnalité IA retenue |
| 2 sept | — | Portail public : catalogue et fiches artisans |

### Phase 4 — Consolidation (3 au 5 septembre)

| Jour | Tâches |
|---|---|
| 3 sept | **Gel du code.** Espace artisan et notification e-mail si le temps le permet, sinon abandon assumé. Jeu de démonstration figé et testé |
| 4 sept | Rédaction des chapitres restants, conclusion et perspectives. Manuel utilisateur court. Relecture complète |
| 5 sept | Marge de sécurité. Remise |

---

## 3. Règles de conduite du planning

1. **Le gel du code au 3 septembre est intangible.** Aucune fonctionnalité nouvelle après cette date, même simple. Les deux derniers jours servent à la rédaction et aux tests, jamais au développement.

2. **Un jour de retard se compense par une coupe, jamais par une nuit blanche.** Si le module Trésorerie déborde, c'est le portail qui se réduit — pas le sommeil.

3. **Écrire chaque soir.** Une demi-heure de notes sur ce qui a été fait et sur les difficultés rencontrées. C'est la matière du chapitre réalisation, et elle est irrécupérable a posteriori.

4. **Une seule fonctionnalité IA, complète.** Deux fonctionnalités à moitié faites valent moins qu'une seule qui fonctionne devant le jury. Choisir dès le 31 août et ne plus changer.

5. **Sauvegarder le dépôt Git à distance dès le premier jour**, avec des commits quotidiens. Une panne de machine le 2 septembre serait fatale.

---

## 4. Choix de la fonctionnalité IA

À trancher au plus tard le 31 août, selon les données effectivement disponibles :

| Option | Condition de faisabilité | Risque |
|---|---|---|
| Assistant d'interrogation en langage naturel | Base peuplée, accès à une API de modèle de langage | Moyen — dépend d'une connexion et d'un budget d'appel |
| Recommandation de produits par similarité | Catalogue renseigné avec catégories et descriptions | Faible — calculable sans service externe |
| Détection d'anomalies de caisse | Historique de ventes transcrit | Faible — règles statistiques simples |

Recommandation : choisir l'option la moins dépendante d'un service externe, puis présenter les deux autres en perspectives. Une démonstration qui échoue faute de connexion Internet le jour de la soutenance coûte plus cher que l'ambition n'en rapporte.

---

## 5. Conditions de déploiement à documenter

- ERP interne : déployable sur un poste du village en réseau local, sans coût.
- Portail public : nécessite un nom de domaine et un hébergement mutualisé, non budgétés à ce jour. À présenter à la coordination comme condition de mise en service.
- Canal WhatsApp : nécessite un compte Meta Business vérifié et engage des frais par message. Reporté en version 2.