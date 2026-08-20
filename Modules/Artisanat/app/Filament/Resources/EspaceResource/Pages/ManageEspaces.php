<?php

namespace Modules\Artisanat\Filament\Resources\EspaceResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Artisanat\Filament\Resources\EspaceResource;
use Modules\Artisanat\Models\Espace;
use Modules\Socle\Models\JournalAudit;

class ManageEspaces extends ManageRecords
{
    protected static string $resource = EspaceResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Espaces',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn () => auth()->user()->can('ajouter_espace'))
                ->modalHeading('Nouvel espace')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->after(fn (Espace $record) => JournalAudit::enregistrer(
                    'Création espace',
                    'ARTISANAT',
                    'Espace',
                    $record->id,
                    ['nom' => $record->nom, 'type' => $record->type?->value],
                )),
        ];
    }
}
