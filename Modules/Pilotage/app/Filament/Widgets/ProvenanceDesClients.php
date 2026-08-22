<?php

namespace Modules\Pilotage\Filament\Widgets;

use Filament\Widgets\Widget;
use Modules\Pilotage\Data\FiltreRapport;
use Modules\Pilotage\Services\RapportService;

/**
 * Répartition des ventes par provenance déclarée du client.
 *
 * La provenance est facultative au comptoir : la ligne « Non
 * renseignée » est donc affichée comme les autres. La masquer
 * donnerait un total de ventilation inférieur au chiffre d'affaires,
 * sans explication visible.
 */
class ProvenanceDesClients extends Widget
{
    protected string $view = 'pilotage::widgets.provenance-des-clients';

    /** @var array<string, mixed> */
    public array $filtres = [];

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $rapport = app(RapportService::class);
        $filtre = FiltreRapport::depuisTableau($this->filtres);

        $lignes = $rapport->ventesParProvenanceClient($filtre);

        return [
            'lignes' => $lignes,
            'totalVentes' => array_sum(array_column($lignes, 'nombre')),
        ];
    }
}
