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
    | indisponible. Une branche dense (embeddings, pgvector) viendra se
    | placer devant sans que les appelants changent.
    |
    */
    'moteur' => [
        'ordre' => ['lexical'],
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
