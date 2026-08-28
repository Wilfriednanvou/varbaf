<?php

namespace Modules\Socle\Filament\Resources\VillageArtisanalResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Socle\Filament\Concerns\TitreLisible;
use Modules\Socle\Filament\Resources\VillageArtisanalResource;
use Modules\Socle\Models\JournalAudit;
use Modules\Socle\Models\VillageArtisanal;

class ManageVillagesArtisanaux extends ManageRecords
{
    // Filament capitalise chaque mot du libelle pluriel pour en
    // faire le titre : « Corps De Metier » la ou le menu et le fil
    // d'Ariane disent « Corps de metier ». Voir le trait.
    use TitreLisible;

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
