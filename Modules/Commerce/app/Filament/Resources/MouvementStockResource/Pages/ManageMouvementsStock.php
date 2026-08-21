<?php

namespace Modules\Commerce\Filament\Resources\MouvementStockResource\Pages;

use Filament\Resources\Pages\ManageRecords;
use Modules\Commerce\Filament\Resources\MouvementStockResource;

class ManageMouvementsStock extends ManageRecords
{
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
