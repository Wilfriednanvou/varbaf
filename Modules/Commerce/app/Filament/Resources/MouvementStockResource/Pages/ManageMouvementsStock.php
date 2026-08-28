<?php

namespace Modules\Commerce\Filament\Resources\MouvementStockResource\Pages;

use Filament\Resources\Pages\ManageRecords;
use Modules\Commerce\Filament\Resources\MouvementStockResource;
use Modules\Socle\Filament\Concerns\TitreLisible;

class ManageMouvementsStock extends ManageRecords
{
    // Filament capitalise chaque mot du libelle pluriel pour en
    // faire le titre : « Corps De Metier » la ou le menu et le fil
    // d'Ariane disent « Corps de metier ». Voir le trait.
    use TitreLisible;

    protected static string $resource = MouvementStockResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Journal de stock',
        ];
    }

    /**
     * Aucune action d'en-tête : une écriture de journal ne se saisit
     * pas à la main, elle naît d'une opération.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
