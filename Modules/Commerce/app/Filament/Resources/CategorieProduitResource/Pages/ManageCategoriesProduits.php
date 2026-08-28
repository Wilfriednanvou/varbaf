<?php

namespace Modules\Commerce\Filament\Resources\CategorieProduitResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Commerce\Filament\Resources\CategorieProduitResource;
use Modules\Commerce\Models\CategorieProduit;
use Modules\Socle\Filament\Concerns\TitreLisible;
use Modules\Socle\Models\JournalAudit;

class ManageCategoriesProduits extends ManageRecords
{
    // Filament capitalise chaque mot du libelle pluriel pour en
    // faire le titre : « Corps De Metier » la ou le menu et le fil
    // d'Ariane disent « Corps de metier ». Voir le trait.
    use TitreLisible;

    protected static string $resource = CategorieProduitResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Catégories de produits',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn () => auth()->user()->can('ajouter_categorie_produit'))
                ->modalHeading('Nouvelle catégorie de produit')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->after(fn (CategorieProduit $record) => JournalAudit::enregistrer(
                    'Création catégorie de produit',
                    'COMMERCE',
                    'CategorieProduit',
                    $record->id,
                    ['code' => $record->code, 'libelle' => $record->libelle],
                )),
        ];
    }
}
