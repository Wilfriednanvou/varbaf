<?php

namespace Modules\Commerce\Filament\Resources\DepotResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Commerce\Filament\Resources\DepotResource;
use Modules\Commerce\Models\Depot;
use Modules\Socle\Filament\Concerns\TitreLisible;
use Modules\Socle\Models\JournalAudit;

class ManageDepots extends ManageRecords
{
    // Filament capitalise chaque mot du libelle pluriel pour en
    // faire le titre : « Corps De Metier » la ou le menu et le fil
    // d'Ariane disent « Corps de metier ». Voir le trait.
    use TitreLisible;

    protected static string $resource = DepotResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Dépôts',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Nouveau dépôt')
                ->visible(fn () => auth()->user()->can('ajouter_depot'))
                ->modalHeading('Nouveau dépôt d\'articles')
                ->modalDescription('Le dépôt est créé en brouillon : rien n\'entre en stock tant qu\'il n\'est pas validé.')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->after(fn (Depot $record) => JournalAudit::enregistrer(
                    'Création dépôt',
                    'COMMERCE',
                    'Depot',
                    $record->id,
                    ['numero' => $record->numero, 'artisan' => $record->artisan?->matricule],
                )),
        ];
    }
}
