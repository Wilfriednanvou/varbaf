<?php

namespace Modules\Socle\Filament\Resources\JournalAuditResource\Pages;

use Filament\Resources\Pages\ManageRecords;
use Modules\Socle\Filament\Concerns\TitreLisible;
use Modules\Socle\Filament\Resources\JournalAuditResource;

class ManageJournauxAudit extends ManageRecords
{
    // Filament capitalise chaque mot du libelle pluriel pour en
    // faire le titre : « Corps De Metier » la ou le menu et le fil
    // d'Ariane disent « Corps de metier ». Voir le trait.
    use TitreLisible;

    protected static string $resource = JournalAuditResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Journal d\'audit',
        ];
    }

    /**
     * Aucune action d'en-tête : le journal ne se saisit pas à la main.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
