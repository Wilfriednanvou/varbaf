<?php

namespace Modules\Portail\Filament\Resources\ContenuPageResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Socle\Models\JournalAudit;
use Modules\Portail\Filament\Resources\ContenuPageResource;

class ManageContenusPage extends ManageRecords
{
    protected static string $resource = ContenuPageResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Contenus de page',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Nouveau contenu')
                ->visible(fn () => auth()->user()->can('ajouter_contenu_page'))
                ->modalHeading('Nouveau contenu de page')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->after(fn ($record) => JournalAudit::enregistrer(
                    'Création contenu de page',
                    'PORTAIL',
                    'ContenuPage',
                    $record->id,
                    ['cle' => $record->cle],
                )),
        ];
    }
}
