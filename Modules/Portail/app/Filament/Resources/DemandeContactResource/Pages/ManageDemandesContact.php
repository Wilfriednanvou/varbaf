<?php

namespace Modules\Portail\Filament\Resources\DemandeContactResource\Pages;

use Filament\Resources\Pages\ManageRecords;
use Modules\Portail\Filament\Resources\DemandeContactResource;

/**
 * Aucune action d'en-tête : une demande de contact ne se crée pas depuis
 * le panneau. Elle arrive du site public, ou elle n'existe pas.
 */
class ManageDemandesContact extends ManageRecords
{
    protected static string $resource = DemandeContactResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Demandes de contact',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
