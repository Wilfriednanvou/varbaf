<?php

namespace Modules\Pilotage\Filament\Widgets;

use Filament\Widgets\Widget;
use Modules\Pilotage\Services\ServiceAnalyseCatalogue;

/**
 * Les segments où plusieurs artisans proposent des articles très proches.
 *
 * À l'usage de la section Production : là où l'offre se concentre, le
 * conseil aux artisans et l'orientation des formations ont quelque
 * chose à dire. L'indicateur ne juge pas — un segment dense peut être
 * la spécialité du village autant qu'une saturation. Il signale, la
 * lecture reste à la coordination.
 */
class SegmentsSatures extends Widget
{
    protected string $view = 'pilotage::widgets.segments-satures';

    /** @var array<string, mixed> */
    public array $filtres = [];

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'segments' => app(ServiceAnalyseCatalogue::class)->segmentsSatures(),
            'seuil' => (float) config('pilotage.analyse.seuil_saturation', 0.45),
            'minimum' => (int) config('pilotage.analyse.artisans_minimum', 2),
        ];
    }
}
