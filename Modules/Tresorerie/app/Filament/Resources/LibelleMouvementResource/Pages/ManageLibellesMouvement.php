<?php

namespace Modules\Tresorerie\Filament\Resources\LibelleMouvementResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Socle\Models\JournalAudit;
use Modules\Tresorerie\Filament\Resources\LibelleMouvementResource;

class ManageLibellesMouvement extends ManageRecords
{
    protected static string $resource = LibelleMouvementResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Libellés de mouvement',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn () => auth()->user()->can('ajouter_libelle_mouvement'))
                ->modalHeading('Nouveau libellé')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->after(fn ($record) => JournalAudit::enregistrer(
                    'Création libellé mouvement', 'TRESORERIE', 'LibelleMouvement', $record->id,
                    ['code' => $record->code]
                )),
        ];
    }
}
