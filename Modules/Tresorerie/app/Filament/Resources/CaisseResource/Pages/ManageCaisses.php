<?php

namespace Modules\Tresorerie\Filament\Resources\CaisseResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Socle\Models\JournalAudit;
use Modules\Tresorerie\Filament\Resources\CaisseResource;

class ManageCaisses extends ManageRecords
{
    protected static string $resource = CaisseResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Caisses',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn () => auth()->user()->can('ajouter_caisse'))
                ->modalHeading('Nouvelle caisse')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->after(fn ($record) => JournalAudit::enregistrer(
                    'Création caisse', 'TRESORERIE', 'Caisse', $record->id, ['code' => $record->code]
                )),
        ];
    }
}
