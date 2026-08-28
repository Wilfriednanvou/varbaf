<?php

namespace Modules\Commerce\Filament\Resources\ProduitResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Commerce\Filament\Resources\ProduitResource;
use Modules\Commerce\Models\Produit;
use Modules\Socle\Filament\Concerns\TitreLisible;
use Modules\Socle\Models\JournalAudit;

class ManageProduits extends ManageRecords
{
    // Filament capitalise chaque mot du libelle pluriel pour en
    // faire le titre : « Corps De Metier » la ou le menu et le fil
    // d'Ariane disent « Corps de metier ». Voir le trait.
    use TitreLisible;

    protected static string $resource = ProduitResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Produits',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn () => auth()->user()->can('ajouter_produit'))
                ->modalHeading('Dépôt d\'un nouveau produit')
                ->modalDescription('La référence est attribuée automatiquement à partir de la boutique. Le produit est déposé au statut « soumis » et devra être validé avant d\'être vendable.')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->after(fn (Produit $record) => JournalAudit::enregistrer(
                    'Dépôt produit',
                    'COMMERCE',
                    'Produit',
                    $record->id,
                    [
                        'reference' => $record->reference,
                        'designation' => $record->designation,
                        'artisan' => $record->artisan?->matricule,
                        'boutique' => $record->boutique?->numero,
                    ],
                )),
        ];
    }
}
