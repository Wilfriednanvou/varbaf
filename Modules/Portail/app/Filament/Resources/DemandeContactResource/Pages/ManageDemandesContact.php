<?php

namespace Modules\Portail\Filament\Resources\DemandeContactResource\Pages;

use Filament\Resources\Pages\ManageRecords;
use Modules\Portail\Filament\Resources\DemandeContactResource;
use Modules\Socle\Filament\Concerns\TitreLisible;

/**
 * Aucune action d'en-tête : une demande de contact ne se crée pas depuis
 * le panneau. Elle arrive du site public, ou elle n'existe pas.
 */
class ManageDemandesContact extends ManageRecords
{
    // Filament capitalise chaque mot du libelle pluriel pour en
    // faire le titre : « Corps De Metier » la ou le menu et le fil
    // d'Ariane disent « Corps de metier ». Voir le trait.
    use TitreLisible;

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
