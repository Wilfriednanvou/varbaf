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
    | L'hybride est en tête depuis le 27/08. Il se déclare disponible
    | dès qu'une de ses deux branches l'est, et dégrade tout seul :
    | fournisseur d'embeddings arrêté, il répond ce que le lexical
    | aurait répondu, en le disant à l'écran. Le repli explicite qui le
    | suit couvre le seul cas où il ne saurait rien faire — un corpus
    | jamais indexé — et existe surtout pour que l'ordre reste lisible.
    |
    */
    'moteur' => [
        'ordre' => ['hybride', 'lexical'],
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
