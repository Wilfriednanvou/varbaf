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
 * L'auditeur est `NotifierSeuilAlerte`, branché par le fournisseur de
 * services du module. Il a été ajouté le 26/08, après que l'événement a
 * vécu plusieurs semaines sans personne à l'autre bout : la tranche
 * initiale livrait l'indicateur — colonne, filtre et compteur de
 * navigation — qui reste utile même sans notification, et la promesse
 * était que la couche de notification viendrait s'abonner ici sans que
 * le service ait à changer. Elle l'a fait, et le service n'a pas changé.
 *
 * L'événement ne connaît toujours pas ses destinataires, et c'est ce qui
 * permet d'en ajouter — l'artisan, le jour où il aura un compte (A-09) —
 * sans rouvrir le journal de stock.
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
