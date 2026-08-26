<?php

/**
 * Paramètres du module Commerce.
 *
 * Comme ceux du Pilotage, ils sont destinés à être lus, discutés et
 * reportés dans le dossier plutôt qu'enfouis dans une classe.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Alerte de rupture (règle 15)
    |--------------------------------------------------------------------------
    */
    'alerte_stock' => [

        /*
         * Rôles auxquels l'alerte est adressée.
         *
         * La règle 15 nomme les sections Production et Commercialisation.
         * Le paramètre existe parce que l'organigramme d'un village
         * n'est pas figé : une réorganisation doit se traduire par une
         * ligne de configuration, pas par une reprise du code.
         *
         * Un rôle qui n'existe pas en base est simplement sans
         * destinataire — la liste n'a pas à être tenue synchrone du jeu
         * d'amorçage pour que l'alerte parte aux autres.
         */
        'roles_destinataires' => [
            'chef_section_production',
            'chef_section_promotion_commercialisation',
        ],
    ],

];
