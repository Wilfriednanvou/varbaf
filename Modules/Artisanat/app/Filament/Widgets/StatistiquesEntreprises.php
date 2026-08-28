<?php

namespace Modules\Artisanat\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Artisanat\Services\StatistiquesArtisanat;

/**
 * L'état de formalisation des artisans du village.
 *
 * **Trois nombres qui ne mesurent pas la même chose.** Une entreprise
 * déclarée, une entreprise à laquelle un artisan se rattache, et une
 * entreprise immatriculée sont trois états distincts. Les confondre
 * ferait annoncer à la tutelle un taux de formalisation qui n'existe pas.
 */
class StatistiquesEntreprises extends StatsOverviewWidget
{
    protected int | string | array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 3;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $stats = app(StatistiquesArtisanat::class);

        $total = $stats->nombreEntreprises();
        $avecArtisan = $stats->nombreEntreprisesAvecArtisan();
        $formalisees = $stats->nombreEntreprisesFormalisees();

        return [
            Stat::make('Entreprises déclarées', (string) $total)
                ->description('Structures enregistrées au village')
                ->descriptionIcon('heroicon-m-briefcase')
                ->color('gray'),

            Stat::make('Avec au moins un artisan', (string) $avecArtisan)
                ->description($stats->nombreArtisansEnEntreprise().' artisan(s) rattaché(s)')
                ->descriptionIcon('heroicon-m-link')
                ->color('primary'),

            Stat::make('Portant un n° de contribuable', (string) $formalisees)
                ->description('Une raison sociale sans numéro reste une déclaration')
                ->descriptionIcon('heroicon-m-identification')
                ->color($formalisees === $total && $total > 0 ? 'success' : 'warning'),
        ];
    }
}
