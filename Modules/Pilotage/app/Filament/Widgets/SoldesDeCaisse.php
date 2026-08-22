<?php

namespace Modules\Pilotage\Filament\Widgets;

use Filament\Widgets\Widget;
use Modules\Pilotage\Services\RapportService;

/**
 * Solde de la section ouverte de chaque caisse, et consolidé.
 *
 * Sans filtre de date : un solde de caisse est un état à l'instant
 * présent, pas un cumul de période. Le borner par un intervalle
 * donnerait un nombre qui ne correspondrait à aucun billet dans aucun
 * tiroir.
 */
class SoldesDeCaisse extends Widget
{
    protected string $view = 'pilotage::widgets.soldes-de-caisse';

    /** @var array<string, mixed> */
    public array $filtres = [];

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $rapport = app(RapportService::class);

        return [
            'soldes' => $rapport->soldesParCaisse(),
            'consolide' => $rapport->soldeDeCaisseConsolide(),
        ];
    }
}
