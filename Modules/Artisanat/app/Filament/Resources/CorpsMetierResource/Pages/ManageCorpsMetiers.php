<?php

namespace Modules\Artisanat\Filament\Resources\CorpsMetierResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Artisanat\Filament\Resources\CorpsMetierResource;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Socle\Models\JournalAudit;

class ManageCorpsMetiers extends ManageRecords
{
    protected static string $resource = CorpsMetierResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Corps de métier',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn () => auth()->user()->can('ajouter_corps_metier'))
                ->modalHeading('Nouveau corps de métier')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->after(fn (CorpsMetier $record) => JournalAudit::enregistrer(
                    'Création corps de métier',
                    'ARTISANAT',
                    'CorpsMetier',
                    $record->id,
                    ['code' => $record->code, 'libelle' => $record->libelle],
                )),
        ];
    }
}
