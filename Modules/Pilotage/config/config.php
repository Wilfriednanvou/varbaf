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
        'voisins' => 5,
        'seuil' => 0.15,
        'bonus_meme_artisan' => 1.15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Recherche descriptive
    |--------------------------------------------------------------------------
    */
    'recherche' => [
        'seuil' => 0.10,
        'extraits' => 5,
    ],

];
