<?php

namespace Modules\Artisanat\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Artisanat\Services\StatistiquesArtisanat;

/**
 * L'occupation du parc locatif, à la date du jour.
 *
 * **« Libre » n'est pas « total moins occupés ».** Un espace déclaré
 * indisponible n'est ni l'un ni l'autre ; l'additionner aux libres
 * annoncerait à la coordination une capacité qui n'existe pas. Les trois
 * états sont donc affichés séparément, et le taux se rapporte aux seuls
 * espaces attribuables — un espace hors service ne peut pas être occupé,
 * et le compter au dénominateur ferait baisser un taux dont personne
 * n'est responsable.
 */
class StatistiquesEspacesLocatifs extends StatsOverviewWidget
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

        $total = $stats->nombreEspacesLocatifs();
        $occupes = $stats->nombreEspacesOccupes();
        $libres = $stats->nombreEspacesLibres();
        $indisponibles = $stats->nombreEspacesIndisponibles();
        $taux = $stats->tauxOccupation();

        return [
            Stat::make('Taux d\'occupation', $taux.' %')
                ->description($occupes.' occupé(s) sur les espaces attribuables')
                ->descriptionIcon('heroicon-m-chart-pie')
                ->color($taux >= 75 ? 'success' : 'warning'),

            Stat::make('Espaces occupés', (string) $occupes)
                ->description('Attribution en cours à ce jour')
                ->descriptionIcon('heroicon-m-user')
                ->color('primary'),

            Stat::make('Espaces libres', (string) $libres)
                ->description('Attribuables et sans occupant')
                ->descriptionIcon('heroicon-m-key')
                ->color($libres > 0 ? 'success' : 'gray'),

            Stat::make('Hors service', (string) $indisponibles)
                ->description($total.' espace(s) au parc au total')
                ->descriptionIcon('heroicon-m-wrench-screwdriver')
                ->color($indisponibles > 0 ? 'danger' : 'gray'),
        ];
    }
}
