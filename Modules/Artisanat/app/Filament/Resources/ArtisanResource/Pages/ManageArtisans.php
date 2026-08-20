<?php

namespace Modules\Artisanat\Filament\Resources\ArtisanResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Artisanat\Filament\Resources\ArtisanResource;
use Modules\Artisanat\Models\Artisan;
use Modules\Socle\Models\JournalAudit;

class ManageArtisans extends ManageRecords
{
    protected static string $resource = ArtisanResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Artisans',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn () => auth()->user()->can('ajouter_artisan'))
                ->modalHeading('Nouvel artisan')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->after(fn (Artisan $record) => JournalAudit::enregistrer(
                    'Création artisan',
                    'ARTISANAT',
                    'Artisan',
                    $record->id,
                    ['matricule' => $record->matricule, 'nom' => $record->nom_complet],
                )),
        ];
    }
}
