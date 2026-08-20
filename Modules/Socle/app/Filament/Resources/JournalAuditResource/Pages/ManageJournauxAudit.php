<?php

namespace Modules\Socle\Filament\Resources\JournalAuditResource\Pages;

use Filament\Resources\Pages\ManageRecords;
use Modules\Socle\Filament\Resources\JournalAuditResource;

class ManageJournauxAudit extends ManageRecords
{
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
