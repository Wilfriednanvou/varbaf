<?php

namespace Modules\Artisanat\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Artisanat\Services\StatistiquesArtisanat;

/**
 * Ce que le parc rapporte, et ce qui arrive à échéance.
 *
 * **La redevance est une somme de montants convenus, pas un calcul.**
 * RG-13 : elle est négociée espace par espace, entre 2 000 et 60 000
 * FCFA, puis figée sur l'attribution. Elle ne se déduit d'aucune surface
 * — l'arbitrage A-01 avait supposé le contraire pendant plusieurs jours,
 * et n'a jamais produit un seul montant juste.
 */
class StatistiquesAttributions extends StatsOverviewWidget
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

        $actives = $stats->nombreAttributionsActives();
        $terme = $stats->nombreAttributionsArrivantATerme();
        $redevance = $stats->redevanceMensuelleCumulee();

        return [
            Stat::make('Attributions en cours', (string) $actives)
                ->description('Actives et couvrant la date du jour')
                ->descriptionIcon('heroicon-m-key')
                ->color('primary'),

            Stat::make('Redevance mensuelle', number_format($redevance, 0, ',', ' ').' FCFA')
                ->description('Somme des montants convenus, attributions en cours')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            // Une attribution sans date de fin n'y figure jamais : elle
            // n'arrive pas à terme, et l'y faire apparaître serait un
            // contresens sur ce que « sans terme » veut dire.
            Stat::make('À échéance sous 30 jours', (string) $terme)
                ->description($terme > 0
                    ? 'Attributions à reconduire ou à clore'
                    : 'Aucune échéance dans le mois')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color($terme > 0 ? 'warning' : 'gray'),
        ];
    }
}
