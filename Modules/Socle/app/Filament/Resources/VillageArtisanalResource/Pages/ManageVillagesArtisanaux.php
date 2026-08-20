<?php

namespace Modules\Socle\Filament\Resources\VillageArtisanalResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Socle\Filament\Resources\VillageArtisanalResource;
use Modules\Socle\Models\JournalAudit;
use Modules\Socle\Models\VillageArtisanal;

class ManageVillagesArtisanaux extends ManageRecords
{
    protected static string $resource = VillageArtisanalResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Villages artisanaux',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn () => auth()->user()->can('ajouter_village'))
                ->modalHeading('Nouveau village artisanal')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->after(fn (VillageArtisanal $record) => JournalAudit::enregistrer(
                    'Création village',
                    'SOCLE',
                    'VillageArtisanal',
                    $record->id,
                    ['code' => $record->code, 'nom' => $record->nom],
                )),
        ];
    }
}
