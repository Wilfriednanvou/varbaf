<?php

namespace Modules\Pilotage\Filament\Widgets;

use Filament\Widgets\Widget;
use Modules\Pilotage\Data\FiltreRapport;
use Modules\Pilotage\Services\RapportService;

/**
 * Le chiffre d'affaires ventilé selon les trois axes qui intéressent la
 * coordination : la boutique, l'artisan, le vendeur.
 *
 * Les trois vivent dans un seul widget parce qu'ils répondent à la même
 * question — « d'où vient le chiffre ? » — et qu'ils doivent porter le
 * même filtre. Trois widgets séparés inviteraient à les filtrer
 * différemment, et à comparer des périodes distinctes sans le voir.
 */
class VentesParAxe extends Widget
{
    protected string $view = 'pilotage::widgets.ventes-par-axe';

    protected int | string | array $columnSpan = 'full';

    /** @var array<string, mixed> */
    public array $filtres = [];

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $rapport = app(RapportService::class);
        $filtre = FiltreRapport::depuisTableau($this->filtres);

        return [
            'intervalle' => $filtre->libelleIntervalle(),
            'axes' => [
                ['titre' => 'Par boutique', 'lignes' => $rapport->ventesParBoutique($filtre)],
                ['titre' => 'Par artisan', 'lignes' => $rapport->ventesParArtisan($filtre)],
                ['titre' => 'Par vendeur', 'lignes' => $rapport->ventesParVendeur($filtre)],
            ],
        ];
    }
}
