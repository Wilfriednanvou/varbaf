<?php

namespace Modules\Artisanat\Filament\Resources\EntrepriseArtisanaleResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Artisanat\Filament\Resources\EntrepriseArtisanaleResource;
use Modules\Artisanat\Models\EntrepriseArtisanale;
use Modules\Socle\Models\JournalAudit;

class ManageEntreprisesArtisanales extends ManageRecords
{
    protected static string $resource = EntrepriseArtisanaleResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Entreprises artisanales',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn () => auth()->user()->can('ajouter_entreprise'))
                ->modalHeading('Nouvelle entreprise artisanale')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->after(fn (EntrepriseArtisanale $record) => JournalAudit::enregistrer(
                    'Création entreprise artisanale',
                    'ARTISANAT',
                    'EntrepriseArtisanale',
                    $record->id,
                    ['raison_sociale' => $record->raison_sociale],
                )),
        ];
    }
}
