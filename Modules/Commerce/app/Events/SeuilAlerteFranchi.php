<?php

namespace Modules\Commerce\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Commerce\Models\MouvementStock;
use Modules\Commerce\Models\Produit;

/**
 * Émis au moment précis où le stock d'un produit **franchit** son seuil
 * d'alerte à la baisse (règle 15).
 *
 * Au franchissement, pas à l'état. Émettre à chaque mouvement tant que
 * la quantité est sous le seuil produirait trois alertes pour trois
 * ventes dans l'après-midi, et les sections cesseraient de les lire au
 * bout d'une semaine. L'événement ne repart qu'après que le stock est
 * remonté au-dessus du seuil.
 *
 * Aucun auditeur n'est branché à ce stade : la tranche en cours livre
 * l'indicateur — colonne, filtre et compteur de navigation — qui reste
 * utile même si personne ne consulte ses notifications. La couche de
 * notification viendra s'abonner ici sans que le service ait à changer.
 */
class SeuilAlerteFranchi
{
    use Dispatchable;

    public function __construct(
        public readonly Produit $produit,
        public readonly int $soldeAvant,
        public readonly int $soldeApres,
        public readonly int $seuil,
        public readonly MouvementStock $mouvement,
    ) {}
}
