<?php

/**
 * Paramètres du volet analytique du Pilotage.
 *
 * Aucune de ces valeurs n'est codée en dur ailleurs : elles sont
 * destinées à être calibrées, puis reportées telles quelles dans le
 * dossier. Un seuil qu'on ne peut pas bouger est un seuil qu'on ne peut
 * pas justifier.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Moteur sémantique
    |--------------------------------------------------------------------------
    |
    | Ordre de préférence des implémentations. Le résolveur retient la
    | première qui se déclare disponible et retombe sur la suivante
    | sinon. La branche lexicale est toujours en dernier : elle ne
    | dépend d'aucun service externe et ne peut donc pas être
    | indisponible.
    |
    | L'hybride a été en tête le 27/08, le temps d'une mesure. Il en est
    | retiré le soir même, et le motif mérite d'être lu avant d'être
    | défait.
    |
    | La branche dense a été construite sur tout le corpus — 325 fiches,
    | 768 dimensions, 100 % de couverture — puis mesurée par
    | « varbaf:evaluer-assistant » sur les 48 questions du jeu
    | d'évaluation. Résultat : rappel@5 identique au lexical (20 %) et
    | taux de refus correct tombé de 100 % à 0 %. Les huit questions
    | auxquelles le système doit refuser de répondre reçoivent une
    | réponse.
    |
    | Ce n'est pas un seuil mal réglé. Une sonde sur trois couples
    | témoins donne 0,644 pour un couple qui doit être proche, 0,505 et
    | 0,538 pour deux couples qui doivent être lointains — l'étranger
    | au-dessus du lointain. L'ordre lui-même est faux, et un pouvoir de
    | séparation de 0,106 sur une échelle où tout tient entre 0,50 et
    | 0,64 signifie qu'aucune valeur de seuil ne sépare quoi que ce
    | soit. Les préfixes de tâche du modèle, essayés, dégradent encore
    | (0,023). La cause est le corpus et non le réglage :
    | « nomic-embed-text » est massivement anglophone, et les fiches du
    | village font deux ou trois mots de français.
    |
    | Le dense et l'hybride restent enregistrés au catalogue des moteurs
    | — comme le témoin par mots-clés, et pour la même raison : ce sont
    | des instruments de mesure, pas des moteurs de repli. Les garder
    | mesurables tout en les tenant hors de l'ordre de résolution est ce
    | qui permet de citer un résultat négatif plutôt que de le raconter.
    |
    */
    'moteur' => [
        'ordre' => ['lexical'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rédaction générative
    |--------------------------------------------------------------------------
    |
    | Un modèle de langage met les extraits retrouvés en français suivi.
    | Il n'intervient que dans la branche descriptive, ne reçoit que ces
    | extraits, et sa sortie est relue par « GardeDesChiffres » comme
    | n'importe quel autre texte. Sans clé, l'assistant liste les
    | extraits — le comportement livré depuis le premier jour.
    |
    | Le rattrapage du routage par le modèle a été écarté le 27/08 : le
    | routage décide quelle branche répond, donc se situe en amont de la
    | frontière entre l'agrégation calculée et le descriptif, et la
    | classification est déjà mesurée à 100 % sur les 48 questions du jeu
    | d'évaluation.
    |
    */
    'redaction' => [

        // Interrupteur franc, indépendant de la présence d'une clé : il
        // permet de démontrer le repli devant un jury sans avoir à
        // retirer quoi que ce soit du fichier d'environnement.
        'active' => (bool) env('PILOTAGE_REDACTION', true),

        // Chaîne d'escalade : le premier profil disponible rédige.
        //
        // **Le local d'abord, et le motif n'est pas la performance.** Un
        // modèle sur la machine ne coûte rien, ne demande aucune clé et
        // ne dépend d'aucune connexion — donc il fonctionne le jour de la
        // soutenance, y compris dans une salle sans réseau. Le distant
        // n'est que le rattrapage, et il est lui-même facultatif : sans
        // clé, sans réseau, sans rien, l'assistant liste les extraits
        // comme il le fait depuis le premier jour.
        'ordre' => ['local', 'distant'],

        // Budget de temps d'un appel, en secondes. Au-delà, l'assistant
        // compose mécaniquement : une réponse moins bien tournée vaut
        // mieux qu'une page qui ne s'affiche pas.
        'budget' => 8,

        /*
         | Les deux profils parlent le même dialecte — « chat completions »
         | d'OpenAI — et sont servis par la même classe. Changer de
         | fournisseur est un changement de configuration, jamais de code :
         | il suffit que l'URL réponde à « POST {url}/v1/chat/completions ».
         |
         | Bases connues, à titre de repère :
         |   Ollama (local)  http://127.0.0.1:11434
         |   Groq            https://api.groq.com/openai
         |   Cerebras        https://api.cerebras.ai
         |   Mistral         https://api.mistral.ai
         |   OpenRouter      https://openrouter.ai/api
         |   xAI (payant)    https://api.x.ai
         */
        'profils' => [

            'local' => [
                'libelle' => 'local',
                'url' => env('REDACTION_LOCALE_URL', 'http://127.0.0.1:11434'),
                'modele' => env('REDACTION_LOCALE_MODELE', 'qwen2.5:3b'),

                // Ollama exige un jeton et ne le vérifie pas. Mettre une
                // valeur ici n'est donc pas un secret laissé en clair,
                // c'est la satisfaction d'une formalité du protocole.
                'cle' => env('REDACTION_LOCALE_CLE', 'ollama'),

                // Un service local se sonde : il coûte une milliseconde
                // à interroger, et le cas « pas lancé » est fréquent.
                'sonder' => true,
                'delai_sonde' => 2,
            ],

            'distant' => [
                'libelle' => 'distant',
                'url' => env('REDACTION_DISTANTE_URL', 'https://api.groq.com/openai'),

                // **Un identifiant de modèle est une valeur périssable.**
                // « llama-3.3-70b-versatile » a été retiré du palier
                // gratuit de Groq le 16/08/2026, et un identifiant
                // déprécié ne dégrade pas la réponse : il la refuse. Le
                // repli couvre le cas — l'assistant listera les extraits
                // — mais silencieusement, et c'est précisément le genre
                // de panne qu'on découvre devant un jury. Vérifier
                // « GET {url}/v1/models » avant une démonstration coûte
                // dix secondes.
                'modele' => env('REDACTION_DISTANTE_MODELE', 'openai/gpt-oss-120b'),

                // Jamais de valeur par défaut ici : une clé écrite dans
                // un fichier suivi par Git est une clé compromise.
                'cle' => env('REDACTION_DISTANTE_CLE', ''),

                // Un service en ligne ne se sonde pas : l'aller-retour
                // pèserait sur chaque question, et la panne se découvre
                // très bien à l'appel, où le repli est déjà écrit.
                'sonder' => false,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Branche dense
    |--------------------------------------------------------------------------
    |
    | Le fournisseur d'embeddings est local — Ollama sur la machine du
    | village. Aucune clé, aucun budget par appel, et aucune donnée du
    | village qui sorte du village. C'est la condition qui rendait un
    | service distant inacceptable ici, pas son coût.
    |
    | Rien de ce bloc n'est requis pour que le système fonctionne : si
    | le service est absent, la branche se tait et le lexical répond.
    |
    */
    'dense' => [

        'ollama' => [

            'url' => env('OLLAMA_URL', 'http://127.0.0.1:11434'),

            // « nomic-embed-text » : 768 dimensions, environ 270 Mo,
            // entraîné pour la recherche de passages et non pour la
            // conversation. Un modèle de discussion produirait des
            // vecteurs, mais pas des vecteurs faits pour être comparés.
            'modele' => env('OLLAMA_MODELE_EMBEDDINGS', 'nomic-embed-text'),

            // Un appel de vectorisation, en secondes. Large : le premier
            // appel après le démarrage du service charge le modèle en
            // mémoire et peut prendre plusieurs secondes.
            'delai' => 20,

            // La sonde de disponibilité, elle, doit être brève : elle
            // est sur le chemin d'une question, là où l'utilisateur
            // attend. Mieux vaut se déclarer indisponible que faire
            // patienter.
            'delai_sonde' => 2,

            // Nombre de textes envoyés par appel à l'indexation.
            'lot' => 32,
        ],

        // Plancher du cosinus dense. Nettement plus haut que celui du
        // lexical, et ce n'est pas une préférence : un espace vectoriel
        // continu rapproche *toujours* quelque chose, et 0,30 entre deux
        // textes sans rapport y est banal. Les deux seuils ne mesurent
        // pas la même chose.
        'seuil' => 0.35,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fusion des branches
    |--------------------------------------------------------------------------
    |
    | Fusion par rangs réciproques : un score TF-IDF et un cosinus dense
    | ne vivent pas sur la même échelle, mais « premier » veut dire la
    | même chose chez les deux. Voir `FusionReciproque` pour le détail.
    |
    */
    'fusion' => [

        // Amortit l'écart entre les premiers rangs. À 60, passer du rang
        // 1 au rang 2 coûte 1,6 % : assez pour classer, trop peu pour
        // qu'un moteur impose seul son premier contre l'avis de l'autre.
        'k' => 60,

        // À poids égal, aucune branche n'a raison d'avance. Ces deux
        // nombres sont là pour être bougés et justifiés par la mesure de
        // « varbaf:evaluer-assistant », pas pour rester à 1 par défaut
        // faute d'avoir été regardés.
        'poids' => [
            'lexical' => 1.0,
            'dense' => 1.0,
        ],

        // Candidats remontés par chaque branche avant la fusion. Plus
        // large que le nombre d'extraits affichés : un passage huitième
        // chez l'un et deuxième chez l'autre mérite d'être vu, et il ne
        // le serait pas si chaque branche s'arrêtait à cinq.
        'candidats' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Indexation lexicale
    |--------------------------------------------------------------------------
    |
    | Les poids sont des facteurs de répétition : un champ de poids 3
    | compte trois fois dans la fréquence du terme. C'est la manière la
    | plus lisible de dire qu'une désignation pèse plus qu'une
    | description, et elle se démontre en une phrase.
    |
    */
    'index' => [

        'poids' => [

            'produit' => [
                'designation' => 3,
                'categorie' => 2,
                'corps_metier' => 2,
                'description' => 1,
                'artisan' => 1,
            ],

            'artisan' => [
                'identite' => 3,
                'corps_metier' => 3,
                'categories_produits' => 2,
                'designations_produits' => 1,
            ],
        ],

        // Sous cette longueur, un terme est écarté : les mots de deux
        // lettres du français sont presque tous des mots outils, et ils
        // gonflent l'index sans rien discriminer.
        'longueur_minimale_terme' => 3,

        // Au-delà de cette longueur, un pluriel en -s ou -x est ramené
        // au singulier. Le seuil protège « bois », « prix », « croix »,
        // qui sont des singuliers terminés par s ou x.
        'longueur_minimale_singularisation' => 5,

        // Nombre de lignes écrites par insertion groupée.
        'taille_lot' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Recommandation de produits
    |--------------------------------------------------------------------------
    */
    'recommandation' => [

        // Nombre de voisins restitués au plus.
        'voisins' => 5,

        // Plancher de qualite du rapprochement. Il porte sur la
        // similarité brute, jamais sur le score : la majoration du même
        // artisan classe, elle ne repêche pas.
        'seuil' => 0.15,

        // Facteur appliqué au score de classement quand le voisin est du
        // même artisan. À 1.0, la majoration est neutralisee.
        'bonus_meme_artisan' => 1.15,

        // Défaut du paramètre de surface. Le portail le force a false :
        // un produit épuisé y est annoncé « sur commande », pas masque.
        'exclure_stock_epuise' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Analyse du catalogue
    |--------------------------------------------------------------------------
    |
    | La meme mesure de similarité, retournée vers le catalogue entier.
    | Deux lectures : ce qui n'a de voisin nulle part, ce qui en a trop.
    |
    */
    'analyse' => [

        // En dessous, un produit est dit isolé : rien du catalogue ne
        // lui ressemble. Distinct du seuil de recommandation, pour
        // pouvoir être calibré séparément.
        'seuil_isolement' => 0.15,

        // Au-dessus, deux produits sont dits très proches. Plus haut que
        // le seuil de recommandation : suggérer un article approchant et
        // signaler une concurrence ne demandent pas la même exigence.
        'seuil_saturation' => 0.45,

        // Nombre d'artisans distincts à partir duquel on parle de
        // segment saturé.
        'artisans_minimum' => 2,

        // Bornes des listes affichées au tableau de bord.
        'limite' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Recherche descriptive
    |--------------------------------------------------------------------------
    */
    'recherche' => [

        // Plancher de similarité d'un extrait. En dessous, l'assistant
        // refuse plutôt que de restituer le passage le moins éloigné :
        // une réponse approchée à une question sans réponse est pire
        // qu'un aveu d'ignorance.
        'seuil' => 0.10,

        // Nombre d'extraits restitués, et borne du rappel@5.
        'extraits' => 5,

        // Longueur maximale d'un extrait affiché, en caractères.
        'longueur_extrait' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Assistant d'interrogation
    |--------------------------------------------------------------------------
    */
    'assistant' => [

        // Score minimal de reconnaissance d'une intention, en nombre de
        // termes. Deux au moins, et l'asymétrie est voulue : sous ce
        // seuil la question part vers la branche descriptive, où elle
        // n'obtiendra rien plutôt qu'un montant faux. Un faux négatif
        // coûte une question sans réponse ; un faux positif attribue un
        // montant au mauvais indicateur.
        'seuil_intention' => 2,
    ],

];
