<?php

namespace Modules\Pilotage\Filament\Widgets;

use Filament\Widgets\Widget;
use Modules\Pilotage\Services\RapportService;

/**
 * Produits dont le stock est retombé au niveau du seuil d'alerte
 * (règle 15).
 *
 * La liste est bornée par le service : le tableau de bord montre les
 * ruptures les plus proches, il ne tient pas l'inventaire. L'écran du
 * catalogue est là pour ça.
 */
class AlertesStock extends Widget
{
    protected string $view = 'pilotage::widgets.alertes-stock';

    /** @var array<string, mixed> */
    public array $filtres = [];

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $rapport = app(RapportService::class);

        return [
            'produits' => $rapport->produitsSousLeSeuil(10),
            'total' => $rapport->nombreDeProduitsSousLeSeuil(),
        ];
    }
}
