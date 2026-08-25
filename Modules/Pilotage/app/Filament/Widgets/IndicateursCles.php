<?php

namespace Modules\Pilotage\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Pilotage\Data\FiltreRapport;
use Modules\Pilotage\Services\RapportService;

/**
 * Les six chiffres qu'on regarde en premier.
 *
 * Le widget n'interroge jamais la base : il demande à
 * `RapportService`, qui seul sait ce que « chiffre d'affaires » veut
 * dire dans ce système.
 */
class IndicateursCles extends StatsOverviewWidget
{
    /** @var array<string, mixed> */
    public array $filtres = [];

    protected int | string | array $columnSpan = 'full';

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $rapport = app(RapportService::class);
        $filtre = FiltreRapport::depuisTableau($this->filtres);

        $occupation = $rapport->tauxOccupationEspaces();
        $sousLeSeuil = $rapport->nombreDeProduitsSousLeSeuil();
        $derniere = $rapport->dernierReversement();

        return [
            Stat::make("Chiffre d'affaires", $this->fcfa($rapport->chiffreAffaires($filtre)))
                ->description($rapport->nombreDeVentes($filtre).' vente(s) '.$filtre->libelleIntervalle())
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->color('success'),

            Stat::make('Recettes de commission', $this->fcfa($rapport->recettesDeCommission($filtre)))
                ->description('Part revenant au village')
                ->descriptionIcon('heroicon-m-receipt-percent')
                ->color('primary'),

            Stat::make('Dettes envers les artisans', $this->fcfa($rapport->dettesEnversLesArtisans()))
                ->description('Parts dues non encore reversées')
                ->descriptionIcon('heroicon-m-hand-raised')
                ->color('warning'),

            Stat::make('Solde de caisse', $this->fcfa($rapport->soldeDeCaisseConsolide()))
                ->description('Toutes caisses ouvertes confondues')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make("Taux d'occupation", $occupation['taux'].' %')
                ->description($occupation['occupes'].' espace(s) occupé(s) sur '.$occupation['total'])
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color($occupation['taux'] >= 75 ? 'success' : 'warning'),

            Stat::make('Dernier reversement', $this->fcfa($rapport->montantDernierReversement()))
                ->description($derniere
                    ? 'Campagne '.$derniere->libellePeriode()
                    : 'Aucune campagne validée à ce jour')
                ->descriptionIcon('heroicon-m-arrow-uturn-right')
                ->color('gray'),

            Stat::make("Alertes de stock", (string) $sousLeSeuil)
                ->description($sousLeSeuil > 0
                    ? 'Produit(s) au niveau du seuil'
                    : 'Aucun produit sous son seuil')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($sousLeSeuil > 0 ? 'danger' : 'success'),
        ];
    }

    protected function fcfa(int $montant): string
    {
        return number_format($montant, 0, ',', ' ').' FCFA';
    }
}
