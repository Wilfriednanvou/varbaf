# Journal de bord

Notes du soir, tenues au titre de la règle 3 du rétroplanning : *une demi-heure sur ce qui a été fait et sur les difficultés rencontrées, parce que c'est la matière du chapitre réalisation et qu'elle est irrécupérable a posteriori.*

Chaque entrée suit la même forme — ce qui a été fait, ce qui a résisté, ce qui a été décidé, ce qu'on en retient. La troisième et la quatrième colonnes sont les plus utiles au rapport : un chapitre réalisation qui n'énumère que des fonctionnalités livrées se lit comme une brochure ; celui qui montre ce qui a résisté et comment se lit comme un travail d'ingénieur.

---

## Mercredi 26 août 2026

### Ce qui a été fait

**Le jeu de données a été entièrement refait.** L'ancien registre, transcrit à la main le 20 août, a été supprimé — rapports d'import compris — et remplacé par la reprise de trois documents réels du village : l'état des ventes au 25 février 2026, l'inventaire du stock produits, et la liste des produits artisanaux, qui porte aussi la liste des occupants. Le taux de commission, jusque-là ouvert, est confirmé à **10 %**.

Le registre normalisé compte **603 lignes**, dont **602 ventes importées**. Les chiffres ont été vérifiés un à un en console :

| Grandeur | Valeur | Ce qu'elle vérifie |
|---|---|---|
| Chiffre d'affaires | 2 021 350 F | Somme des lignes du registre, au franc |
| Commission | 202 135 F | Exactement 10 % — RG-12 bis, arrondi à l'unité |
| Part artisan | 1 819 215 F | Commission + part = CA, sans reste |
| Entrées en caisse | 2 021 350 F | RG-13 : la vente entre en caisse pour son montant entier |
| Mouvements de stock | 1 204 | 602 × 2 — le stock revient à zéro, dépôt puis sortie |
| Attributions | 24, pour 115 000 F de redevance | Aucune sans montant |

**Le sous-sol et l'espace vert sont entrés au parc locatif.** L'état de recouvrement des redevances 2026 nomme trois espaces loués hors du bâtiment de vente : G0201 au sous-sol, occupé par la CNTC pour 60 000 F par mois — la redevance la plus élevée de tout le parc —, G0202 pour SCOOPS AAMRO à 10 000 F, et EV0101 sur l'espace vert à 5 000 F. Soit 75 000 F de redevance mensuelle que le système ne voyait pas et trois attributions qu'il aurait refusé d'enregistrer. La table `boutiques` devient celle des contenants et porte une colonne `nature` — boutique, sous-sol, espace vert — de sorte que le taux d'occupation présenté à la tutelle se calcule toujours sur les seuls locaux de vente, sans que l'autre chiffre disparaisse.

**La dette DT-01 est fermée.** `Exercice::cloturer()` ne vérifiait ni les sections de caisse ouvertes ni les campagnes de reversement non validées : on pouvait refermer une période en laissant de l'argent en caisse et des artisans impayés. Elle était restée ouverte pour une raison de fond — le Socle est le module 1, la Trésorerie le module 4, et la règle de dépendance descendante interdit au premier de connaître le second. La solution retourne le sens de la connaissance : le Socle expose un point d'accroche (`VerrouDeCloture`) et un registre, la Trésorerie vient s'y déclarer depuis son propre fournisseur. Le Socle ne référence rien du module 4, et le registre accueillerait demain un verrou du Commerce sans changer d'une ligne. Neuf tests, dont un qui n'éprouve pas la règle mais **le câblage** : si le fournisseur du module 4 cesse d'être chargé, la clôture ne protège plus rien, et il faut que quelque chose le dise.

**La nature d'un contenant est devenue éditable**, filtrable et lisible en liste. Le seeder seul savait déclarer un local hors vente ; la coordination ne pouvait pas.

### Ce qui a résisté

**Un bug de production qui cassait toutes les pages du panneau.** La migration standard de Laravel pose `notifications.data` en `text`. La cloche de Filament compte les notifications non lues avec `data->>'format'`, que PostgreSQL refuse sur du texte. Toute page du panneau plantait dans le navigateur — et rien ne le signalait, parce que les tests montent les composants Livewire directement : seuls deux tests font une vraie requête HTTP, donc rendent la barre supérieure, et ils vivent dans un module sans rapport avec les notifications. Le défaut n'est apparu qu'en lançant la suite **complète**, non filtrée.

**Une collision de codes d'espaces locatifs.** `EspaceLocatif::genererCode()` ne gardait que les chiffres du numéro de son contenant : `SS01` et `EV01` se réduisaient tous deux à `B01` et fabriquaient les codes de la boutique B01. Le préfixe est désormais alphanumérique, et le rang se calcule sur les seuls codes qui suivent la règle — de sorte qu'un code relevé sur le terrain, `G0201` sous `SS01`, survit à la dérivation au lieu de fausser la numérotation.

**Un défaut de conception dans l'import, que j'avais introduit moi-même.** Les attributions d'espaces étaient créées *avant* la transaction de la vente. Une attribution refusée pour chevauchement levait donc une exception qui remontait et **tuait la vente** — une donnée réelle perdue à cause d'une donnée annexe douteuse. Le refus est maintenant capté, compté sous une anomalie propre (`OCCUPATIONS_REFUSEES`), et la vente passe. Symptôme initial : un test annonçait trois lignes non importées là où deux étaient attendues.

**L'import fabriquait des espaces locatifs à la volée** — 327 au premier passage, tous fictifs, parce que `resoudreEspace()` créait ce qu'il ne trouvait pas. Il ne crée plus rien : il signale `ESPACE_ABSENT` quand la ligne n'en nomme aucun et `ESPACE_INTROUVABLE` quand le code n'est pas au parc. Le parc réel fait autorité.

**Le rapport d'import annonçait « 100 % des lignes signalées »**, ce qui n'apprend rien. Il ventile désormais les anomalies par nature, en regroupant les rejets techniques sous un seul libellé.

**Le rattachement des artisans à leurs espaces.** Le registre nomme les vendeurs comme on les appelle au village — prénom seul, surnom, enseigne. Ma première approche comparait les patronymes ; elle est tombée sur « Crousti Delice NGASSAM », où NGASSAM est le nom de l'artisan et non celui de la boutique. La méthode retenue croise le produit vendu et le corps de métier de l'occupant, document par document : un même nom présent dans les trois fichiers et sur le même local est très probablement une seule personne. La couverture passe de 70 % à 77 %, et chaque arbitrage est consigné avec son motif dans `docs/donnees/correspondance-artisans.csv` — un rattachement sans motif écrit est un rattachement qu'on ne saura pas défendre en soutenance.

### Ce qui a été décidé

- **`quantite` et `date` restent vides dans le registre** là où la source ne les donne pas. Ma première version écrivait `quantite = 1` : c'était faire passer une approximation pour une donnée relevée. Le lecteur déduit et signale, ce qui laisse la trace visible.
- **Les catégories de produits ne seront pas devinées.** 285 produits sans catégorie, aucune source ne disant à laquelle « biscuit de manioc » appartient. Une catégorie inventée ne se distinguerait plus, dans six mois, d'une catégorie relevée.
- **`Boutique` garde son nom** alors qu'il désigne désormais un contenant qui peut ne pas être une boutique. Renommer le modèle, la table et leurs références à huit jours du gel coûterait plus que la gêne de lecture. Consigné plutôt que corrigé.
- **Deux points partent en question à la coordination** plutôt que d'être tranchés seul : Mme Justina, 190 000 F de ventes sans aucun espace à son nom, et « Bijoux en perles », qui n'entre dans aucun des quatorze secteurs officiels.

### Ce que j'en retiens

**La documentation vieillit dans les deux sens.** `docs/dette-technique.md` annonçait l'arbitrage A-03 comme restant à implémenter : il l'était depuis plusieurs jours. J'ai failli réécrire du code existant. Un document qui décrit une dette éteinte fait perdre autant de temps qu'un document qui en tait une.

**Une règle inventée par déduction ne se fait jamais démentir tant que rien ne la remplit.** C'est la deuxième fois — après la redevance au mètre carré de l'arbitrage A-01, qui n'a jamais produit un seul montant. Ici, « le sous-sol ne comporte aucun espace locatif » n'avait jamais été vérifié contre une donnée du village : il était déduit de ce que le sous-sol *est*. Le raisonnement était juste, sa prémisse était fausse, et rien dans le système ne pouvait le dire.

**La suite complète attrape ce que la suite filtrée laisse passer.** Le bug de la cloche n'existait, du point de vue des tests, que dans deux fichiers d'un module sans rapport. Lancer `--filter` sur ce qu'on vient de modifier donne une confiance qui ne correspond à rien.

### En fin de journée

- **374 tests au vert** sur la suite complète. Quatre échecs constatés vers 2 h 40 dans `AlerteStockTest`, tous en `SQLSTATE[40P01] Deadlock detected` sur les index d'unicité de Spatie : deux processus écrivaient sur la même base au même moment. Repassés 11/11 en isolation. Artefact d'exécution, pas régression.
- Trois commits poussés : le registre de verrous, la nature du contenant, la mise à jour de la dette technique.

---

## Jeudi 27 août 2026

### Ce qui a été fait

**La branche dense a été construite, mesurée, puis écartée dans la même journée.** Ollama installé, `nomic-embed-text` téléchargé — 274 Mo, 768 dimensions —, corpus vectorisé à **100 % : 325 fiches sur 325, aucun échec**. La couche de code existait depuis la veille ; il ne lui manquait que des vecteurs.

Puis la mesure, sur les 48 questions du jeu d'évaluation :

| Moteur | Classification | Rappel@5 | Refus correct |
|---|---|---|---|
| lexical | 100,0 % | 20,0 % | **100,0 %** |
| mots_cles | 100,0 % | 0,0 % | **100,0 %** |
| dense | 100,0 % | 20,0 % | **0,0 %** |
| hybride | 100,0 % | 20,0 % | **0,0 %** |

> **Correction du soir — la colonne « Rappel@5 » de ce tableau est fausse.** Elle a été mesurée contre les seuls titres des sources, où le corps de métier d'une fiche produit ne figure jamais. Le défaut a été trouvé en fin de journée et la mesure refaite ; les chiffres corrigés sont plus bas, dans l'entrée du soir. Le tableau reste ici tel qu'il a été produit, parce qu'une mesure qu'on corrige se montre — et parce que le raisonnement qui suit a été tenu sur ces chiffres-là. La colonne « Refus correct », elle, était valide, et c'est elle qui a emporté la décision.

La branche dense ne gagne rien au rappel et **détruit le refus** : les huit questions auxquelles le système doit refuser de répondre reçoivent toutes une réponse. Or le refus est l'argument central du volet IA — « aucun montant ne peut être produit par proximité textuelle » ne vaut que si le système sait se taire.

`pilotage.moteur.ordre` est ramené à `['lexical']`. Le dense et l'hybride **restent enregistrés au catalogue des moteurs**, hors de l'ordre de résolution, au même titre que le témoin par mots-clés et pour la même raison déjà écrite dans le code : ce ne sont pas des moteurs de repli, ce sont des instruments de mesure. Les garder mesurables est ce qui permet de **citer** un résultat négatif plutôt que de le raconter.

### Ce qui a résisté

**L'instrument de mesure ne regardait pas ce qu'on lui demandait de mesurer.** `EvaluerAssistantCommand` énumérait ses moteurs en dur — `['lexical', 'mots_cles']` — et la session qui avait écrit la branche dense n'avait pas touché à cette ligne. Premier verdict : « 2 moteur(s) mesuré(s) » sur quatre. Un index construit à 100 % que rien n'allait voir, et aucun message d'erreur, puisque du point de vue de la commande tout s'était bien passé. Le texte d'aide de l'option `--moteur` était resté sur l'ancienne liste, ce qui aurait entretenu l'erreur pour le lecteur suivant.

**Une hypothèse plausible, documentée, et fausse.** `nomic-embed-text` est entraîné avec des préfixes de tâche obligatoires — `search_document:` à l'indexation, `search_query:` à l'interrogation — et le code ne les posait nulle part. L'explication du 0 % de refus semblait tenir : sans préfixes, l'espace se replie et le seuil ne sépare plus rien. Une sonde de quarante lignes sur trois couples témoins l'a démentie en quatre minutes.

| Couple | sans préfixe | avec préfixe |
|---|---|---|
| proche (attendu haut) | 0,644 | 0,583 |
| lointain (attendu bas) | 0,505 | 0,522 |
| étranger (attendu bas) | 0,538 | 0,560 |

Les préfixes **dégradent** : le pouvoir de séparation tombe de 0,106 à 0,023. Et surtout, la colonne de gauche dit l'essentiel — le couple étranger score *au-dessus* du couple lointain. L'ordre lui-même est faux. Tout tient entre 0,50 et 0,64, donc aucune valeur de seuil ne peut séparer ce qu'il faut retenir de ce qu'il faut rejeter. La cause n'est pas le réglage mais le corpus : un modèle massivement anglophone, des fiches de deux ou trois mots de français.

**Deux tests affirmaient une valeur de configuration.** `RechercheHybrideTest` et `AssistantInterrogationTest` lisaient `pilotage.moteur.ordre` pour affirmer « hybride ». Ils sont tombés au changement d'ordre — pour une décision qui ne les concernait pas. Chacun pose désormais l'ordre dont il a besoin, et un test neuf affirme, lui, que l'ordre livré ne retient que le lexical : celui-là ne couvre pas un mécanisme, il **retient une décision**.

### Ce qui a été décidé

- **Le dense est écarté sur la foi de la mesure, pas abandonné.** Le code reste, mesurable, avec son motif chiffré en commentaire dans le fichier de configuration.
- **Grok en rédaction seule**, sans rattrapage du routage. Mettre un modèle de langage sur le chemin qui choisit *quelle branche répond* le placerait en amont de la frontière entre agrégation calculée et descriptif — la frontière même qui rend le volet IA défendable. La rédaction est en aval et sous surveillance de `GardeDesChiffres`.
- **Le modèle de langage local est abandonné**, l'objet nul `ModeleIndisponible` absorbant sa disparition : la chaîne d'escalade passe de `['local', 'distant']` à `['distant']` sans qu'aucun appelant change. C'est ce que le port existe pour encaisser.
- Les CSV de la mesure sont versés dans `docs/donnees/evaluation/` : ce sont les pièces justificatives de la table 4.3, et `storage/` n'est pas suivi par Git.

### Ce que j'en retiens

**Une déduction ne coûte rien à formuler et se défend toute seule tant qu'on ne construit pas ce qui pourrait la démentir.** C'est la troisième fois — la redevance au mètre carré, le sous-sol sans espace locatif, et aujourd'hui les préfixes de tâche. Mais aujourd'hui est le contre-exemple des deux autres : l'hypothèse est tombée en quatre minutes **parce qu'on a écrit la sonde**. Quarante lignes jetables, dont la valeur n'était pas de confirmer ce qu'on croyait savoir mais de découvrir qu'on se trompait. Les deux échecs précédents n'ont pas manqué de raisonnement, ils ont manqué d'un objet capable de dire non.

**Trois défauts de la même famille en une journée.** Un instrument aveugle aux moteurs qu'on venait d'ajouter ; deux tests qui lisaient l'état du dépôt au lieu du comportement du code ; une hypothèse que rien ne mettait à l'épreuve. À chaque fois, quelque chose qui a l'air de vérifier et qui regarde ailleurs — et à chaque fois, un silence qu'on prend pour un accord.

**Un résultat négatif documenté vaut mieux qu'une fonctionnalité non mesurée.** « Nous avons construit la branche dense, mesurée sur 48 questions, et écartée parce qu'elle annule le refus sans gagner en rappel » est une phrase plus solide que « nous avons implémenté une recherche hybride ». La première se prouve, chiffres et CSV à l'appui ; la seconde s'affirme.

### En fin de journée

- **399 tests au vert**, 1053 assertions.
- Deux commits poussés : l'ouverture de la mesure aux nouvelles branches, puis le retrait du dense de l'ordre livré.

---

## Jeudi 27 août 2026 — soirée

### Ce qui a été fait

**La rédaction générative est livrée**, en aval de la frontière et sous surveillance du garde-fou 2. Un port volontairement étroit, `ModeleDeLangage`, n'expose qu'une opération : mettre en français suivi des extraits **déjà retrouvés**. Le modèle ne cherche rien, ne calcule rien, ne voit aucun indicateur — non parce qu'une consigne le lui interdit, mais parce qu'il n'a accès à rien d'où un chiffre pourrait venir.

`classer()`, prévu à la conception pour rattraper le routeur, a été retiré. Le routage décide *quelle branche répond* : il est donc en amont de la frontière entre l'agrégation calculée et le descriptif, et y placer un appel non déterministe affaiblirait la garantie centrale. La classification est d'ailleurs mesurée à 100 % sur les 48 questions — on ne remplace pas un composant qui ne se trompe jamais par un qui le peut.

**Une seule classe pour tous les fournisseurs.** xAI, Groq, Cerebras, Mistral, OpenRouter et Ollama exposent le même dialecte, `POST {url}/v1/chat/completions`. `ClientCompatibleOpenAI` le parle, et deux profils — `local` et `distant` — ne diffèrent que par ce qu'ils lisent en configuration. Changer de fournisseur est un changement de `.env`, jamais de code.

**Le local passe devant le distant**, et le motif n'est pas la qualité : un modèle sur la machine ne coûte rien, ne demande aucune clé et ne dépend d'aucune connexion. C'est la règle 4 du rétroplanning appliquée à la lettre — une démonstration qui échoue faute de réseau coûte plus cher que l'ambition n'en rapporte. Le distant retenu est **Groq**, palier gratuit sans carte bancaire ; xAI a été écarté, payant.

**Sans clé ni modèle, rien ne change.** `ModeleIndisponible` rend `null`, et l'assistant liste les extraits comme au premier jour. Le chemin dégradé n'est pas un secours écrit à part : c'est le chemin nominal d'hier, qu'on n'a pas retiré — et il est parcouru par toute la suite de tests, donc mieux éprouvé que le chemin nominal d'aujourd'hui.

**L'échafaudage des questions est élagué.** « Produits », « liste », « objets » sont retirés de la question — jamais du corpus. Motif : l'IDF donne à « produit » un poids fort et il a raison, le terme est *rare* dans le corpus, porté par les seules fiches dont la désignation d'origine est un vide-poche. Le décalage n'était pas dans le corpus mais entre le vocabulaire des questions et celui des fiches. Liste distincte de `MotsVides`, et un test vérifie qu'elles ne se recoupent pas.

### Ce qui a résisté

**Le rappel@5 ne mesurait pas ce qu'il prétendait mesurer.** Il comparait les fragments attendus aux seuls **titres** des sources. Or le titre d'une fiche produit est sa référence et sa désignation — « BTQ12-0038 — Collier » — tandis que le corps de métier vit dans l'extrait : « Collier — Vannerie — MINTCHOUGOM SIDONIE ». Un jeu qui vise les corps de métier, parce qu'ils sont seedés et stables, ne pouvait donc **jamais** valider une fiche produit, quelle que soit sa pertinence.

Le signal était visible depuis le matin et je ne l'ai pas lu : **20 %, identiques sur les quatre moteurs, témoin par mots-clés compris.** Un indicateur qui ne bouge jamais, quoi qu'on change, ne mesure pas ce qu'on croit.

Mesure refaite sur titre **et** extrait :

| Moteur | Rappel@5 avant | Rappel@5 après | Refus correct |
|---|---|---|---|
| lexical | 20,0 % | **70,0 %** | 100,0 % |
| mots_cles | 0,0 % | **60,0 %** | 100,0 % |
| dense | 20,0 % | **60,0 %** | 0,0 % |
| hybride | 20,0 % | **70,0 %** | 0,0 % |

Ce n'est pas un assouplissement destiné à obtenir un meilleur chiffre : une source dont l'extrait retrouvé parle de vannerie *est* une source sur la vannerie. C'est la définition de la pertinence qui était fausse, pas son seuil.

**La décision du matin en sort renforcée, pas fragilisée.** Elle avait été prise sur le refus — 100 % contre 0 % —, et cette colonne-là était valide. Avec un rappel qui fonctionne, le dense est en outre **moins bon** que le lexical, 60 contre 70 ; et l'hybride égale le lexical sur le rappel tout en perdant le refus. Il ne rapporte rien et coûte tout. La conclusion était juste, la preuve ne l'était pas ; elle l'est maintenant.

**Un identifiant de modèle est une valeur périssable.** `llama-3.3-70b-versatile` a été retiré du palier gratuit de Groq le 16 août, onze jours avant. Un modèle déprécié ne dégrade pas la réponse : il la refuse, et le repli le fait en silence.

**PHP sous Windows n'embarque aucun magasin de certificats racine.** Premier appel réel : `cURL error 60`. Le `curl` en ligne de commande fonctionnait — Git Bash apporte le sien —, l'extension cURL de PHP non. Corrigé par `curl.cainfo` et `openssl.cafile` dans `php.ini`, pointant sur le `cacert.pem` du projet cURL. **Ce qui n'a pas été fait :** désactiver la vérification TLS côté client, le premier correctif que renvoient les forums. Dans un système qui manipule de l'argent public, retirer délibérément l'authentification du serveur est indéfendable, et ce serait consigné dans le code pour toujours. C'est une condition de déploiement à ajouter à la section 5 du rétroplanning : le poste du village aura le même problème.

**La suite de tests allait partir sur le réseau.** `phpunit.xml` n'annulait pas `PILOTAGE_REDACTION` : dès qu'une clé était présente dans l'environnement, chaque test empruntant la branche descriptive aurait réellement appelé Groq. La suite serait devenue lente et intermittente, aurait échoué chez qui n'a pas de clé, et surtout **aurait éprouvé le service au lieu du code** — un test vert n'aurait plus dit si l'assistant est correct, mais si le fournisseur répondait ce matin-là. Une ligne le règle.

### Ce que j'en retiens

**Huit défauts aujourd'hui, tous de la même famille, et aucun ne s'est signalé tout seul.** Un instrument de mesure aveugle aux moteurs qu'on venait d'ajouter. Deux tests qui affirmaient une valeur de configuration au lieu d'un comportement. Une hypothèse plausible que rien ne mettait à l'épreuve. Un identifiant de modèle périssable. Un échec TLS avalé par le repli. Une suite de tests sur le point d'appeler le réseau sans le dire. Et un indicateur qui, depuis le début, mesurait une propriété des titres.

Le fil est le même à chaque fois : **quelque chose qui a l'air de vérifier, et qui regarde ailleurs.** Un silence pris pour un accord.

**Ce qui a permis de les voir n'est jamais l'intuition, c'est un objet écrit exprès.** La sonde de quarante lignes qui a démenti l'hypothèse des préfixes en quatre minutes. Le champ `redacteur` de la réponse, qui a montré qu'aucune rédaction n'avait eu lieu là où le texte semblait normal. Le `Log::warning` qui a nommé cURL 60 en une ligne. Sans eux, un système qui « marche » — et une moitié de journée de travail inutile qu'on n'aurait découverte qu'en soutenance.

**Le repli doit être silencieux pour l'utilisateur et bavard pour le développeur.** C'est la même exigence que le nommage du moteur à l'écran, appliquée aux journaux : un système dégradé qui se tait des deux côtés est indiscernable d'un système qui fonctionne.

### En fin de journée

- **407 tests au vert**, 1073 assertions.
- Rédaction générative opérationnelle sur données réelles. Question « Quels produits en vannerie sont exposés ? » → « Les produits en vannerie exposés sont le collier, le sac et le chapeau, tous présentés par MINTCHOUGOM SIDONIE. » Le modèle a écarté de lui-même les deux extraits hors sujet que le classement lui avait donnés — bénéfice réel, mais tri non audité : les cinq sources restent affichées, y compris celles qu'il n'a pas retenues.
- Les deux séries de mesures sont versées dans `docs/donnees/evaluation/`.
