<?php

namespace Modules\Artisanat\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Artisanat\Services\StatistiquesArtisanat;

/**
 * Le parc bâti, tel que la tutelle le lit.
 *
 * **Contenant n'est pas local de vente.** Depuis le 26/08, la table
 * porte aussi le sous-sol et l'espace vert : trois espaces y sont loués,
 * dont le plus cher du village. Ce sont des contenants à part entière,
 * mais on n'y vend pas. Afficher les deux nombres côte à côte est la
 * seule manière d'éviter qu'un lecteur prenne l'un pour l'autre — et
 * c'est ce qui rend défendable un taux d'occupation calculé sur les
 * seules boutiques.
 */
class StatistiquesBoutiques extends StatsOverviewWidget
{
    protected int | string | array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 4;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $stats = app(StatistiquesArtisanat::class);

        $contenants = $stats->nombreContenants();
        $locaux = $stats->nombreLocauxDeVente();
        $espaces = $stats->nombreEspacesLocatifs();
        $sansEspace = $stats->nombreContenantsSansEspace();

        return [
            Stat::make('Contenants du parc', (string) $contenants)
                ->description('Boutiques, sous-sol et espace vert')
                ->descriptionIcon('heroicon-m-building-office-2')
                ->color('gray'),

            Stat::make('Locaux de vente', (string) $locaux)
                ->description('Périmètre du taux d\'occupation')
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color('primary'),

            Stat::make('Espaces locatifs abrités', (string) $espaces)
                ->description('Ce qui se loue réellement')
                ->descriptionIcon('heroicon-m-squares-2x2')
                ->color('success'),

            // Orange et non rouge : un contenant sans espace renseigné
            // n'est pas un contenant inexistant. C'est la question 3
            // partie à la coordination — B13 et B17.
            Stat::make('Contenants sans espace connu', (string) $sansEspace)
                ->description($sansEspace > 0
                    ? 'Locaux existants, espaces non relevés'
                    : 'Tous les contenants portent un espace')
                ->descriptionIcon('heroicon-m-question-mark-circle')
                ->color($sansEspace > 0 ? 'warning' : 'success'),
        ];
    }
}
