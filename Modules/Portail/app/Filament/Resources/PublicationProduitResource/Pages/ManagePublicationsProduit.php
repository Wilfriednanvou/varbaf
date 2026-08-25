<?php

namespace Modules\Portail\Filament\Resources\PublicationProduitResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Socle\Models\JournalAudit;
use Modules\Portail\Filament\Resources\PublicationProduitResource;

class ManagePublicationsProduit extends ManageRecords
{
    protected static string $resource = PublicationProduitResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Publications de produits',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Préparer une fiche')
                ->visible(fn () => auth()->user()->can('publier_produit'))
                ->modalHeading('Préparer une fiche portail')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->using(fn (array $data) => PublicationProduitResource::enregistrer(null, $data))
                ->after(fn ($record) => $record
                    ? JournalAudit::enregistrer(
                        'Création fiche portail',
                        'PORTAIL',
                        'PublicationProduit',
                        $record->id,
                        ['produit' => $record->produit?->reference, 'publie' => $record->publie],
                    )
                    : null),
        ];
    }
}
