<?php

namespace Modules\Artisanat\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Artisanat\Services\StatistiquesArtisanat;

/**
 * Ce que la nomenclature des corps de métier dit du village.
 *
 * **L'indicateur utile n'est pas le total, c'est l'écart.** La liste
 * affiche quatorze secteurs ; la colonne « Artisans » montre que la
 * plupart sont à zéro. Un lecteur qui parcourt les lignes finit par s'en
 * apercevoir, mais il ne peut pas le chiffrer sans les compter à la main.
 * « 5 secteurs représentés sur 14 » est une phrase que la tutelle
 * comprend immédiatement — et c'est aussi ce qui justifie une nomenclature
 * conservée telle quelle plutôt que rabotée aux secteurs actifs.
 */
class StatistiquesCorpsMetiers extends StatsOverviewWidget
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

        $total = $stats->nombreCorpsMetiers();
        $representes = $stats->nombreCorpsMetiersRepresentes();
        $vides = $total - $representes;

        return [
            Stat::make('Secteurs de la nomenclature', (string) $total)
                ->description('Découpage officiel de la structure')
                ->descriptionIcon('heroicon-m-rectangle-group')
                ->color('gray'),

            Stat::make('Secteurs représentés', (string) $representes)
                ->description($total > 0 ? 'sur '.$total.' au registre' : 'Nomenclature vide')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('success'),

            // Gris, et non rouge : un secteur sans artisan n'est pas un
            // défaut du système. C'est un fait sur le village, et il a sa
            // place dans le rapport tel quel.
            Stat::make('Secteurs sans artisan actif', (string) $vides)
                ->description($vides > 0
                    ? 'Conservés à la nomenclature'
                    : 'Tous les secteurs sont représentés')
                ->descriptionIcon('heroicon-m-minus-circle')
                ->color('gray'),
        ];
    }
}
