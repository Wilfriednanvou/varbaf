<?php

return [

    'name' => 'Portail',

    /*
    |--------------------------------------------------------------------------
    | Visuels du village
    |--------------------------------------------------------------------------
    |
    | Les photographies versees dans public/images/portail/ ont ete prises au
    | village le 28 aout 2026. Elles sont declarees ici plutot qu'ecrites dans
    | les vues : une vue qui nomme un fichier en dur casse en silence le jour
    | ou le fichier change de nom, et rien ne dit plus quelles images le site
    | utilise reellement.
    |
    | **Un visuel de corps de metier n'est pose que si la photo montre ce
    | metier-la.** Les neuf corps de metier absents de cette liste tombent sur
    | le motif dessine du composant `illustration` — un aplat, jamais une
    | photo d'un autre metier. Illustrer la broderie par une photo de vannerie
    | serait plus joli et faux : le visiteur croirait voir une piece brodee.
    |
    */
    'visuels' => [

        /*
         | Corps de metier, par code de `corps_metiers`. Cinq des quatorze
         | sont couverts par les photographies disponibles.
         */
        'metiers' => [
            'SCU' => 'images/portail/metiers/sculpture',
            'ARP' => 'images/portail/metiers/arts-plastiques',
            'DEC' => 'images/portail/metiers/decoration',
            'VAN' => 'images/portail/metiers/vannerie',
            'AGR' => 'images/portail/metiers/agroalimentaire',
        ],

        /*
         | Interieurs de boutique, par numero de local. Quinze des dix-sept
         | locaux ont ete photographies ; B16 et B17 ne l'ont pas ete.
         */
        'boutiques' => [
            'B01' => 'images/portail/boutiques/b01',
            'B02' => 'images/portail/boutiques/b02',
            'B03' => 'images/portail/boutiques/b03',
            'B04' => 'images/portail/boutiques/b04',
            'B05' => 'images/portail/boutiques/b05',
            'B06' => 'images/portail/boutiques/b06',
            'B07' => 'images/portail/boutiques/b07',
            'B08' => 'images/portail/boutiques/b08',
            'B09' => 'images/portail/boutiques/b09',
            'B10' => 'images/portail/boutiques/b10',
            'B11' => 'images/portail/boutiques/b11',
            'B12' => 'images/portail/boutiques/b12',
            'B13' => 'images/portail/boutiques/b13',
            'B14' => 'images/portail/boutiques/b14',
            'B15' => 'images/portail/boutiques/b15',
        ],

        /*
         | Vues du village et de la vie du village, appelees par leur nom
         | depuis les pages editoriales.
         */
        'village' => [
            'facade' => 'images/portail/village/facade',
            'preau' => 'images/portail/village/preau',
            'exposition' => 'images/portail/village/exposition',
        ],

        /*
         | Creations photographiees a l'exposition. Elles illustrent les
         | pages editoriales — elles ne sont **jamais** presentees comme la
         | photo d'un produit du catalogue : aucune n'a ete rattachee a une
         | reference, et une illustration prise pour une fiche produit
         | tromperait le visiteur sur ce qu'il trouvera en boutique.
         */
        'creations' => [
            'perles-etal' => 'images/portail/creations/perles-etal',
            'perles-bijoux' => 'images/portail/creations/perles-bijoux',
            'perles-colliers' => 'images/portail/creations/perles-colliers',
            'perles-sac-violet' => 'images/portail/creations/perles-sac-violet',
            'perles-sac-vert' => 'images/portail/creations/perles-sac-vert',
            'perles-sac-rose' => 'images/portail/creations/perles-sac-rose',
            'perles-pochettes' => 'images/portail/creations/perles-pochettes',
            'pochette-perles' => 'images/portail/creations/pochette-perles',
            'coiffure' => 'images/portail/creations/coiffure',
            'sculpture-mur' => 'images/portail/metiers/sculpture-mur',
        ],
    ],
];
