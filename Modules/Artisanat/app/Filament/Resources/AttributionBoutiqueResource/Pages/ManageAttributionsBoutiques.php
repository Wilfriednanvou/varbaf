<?php

namespace Modules\Artisanat\Filament\Resources\AttributionBoutiqueResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Artisanat\Filament\Resources\AttributionBoutiqueResource;
use Modules\Artisanat\Models\AttributionBoutique;
use Modules\Socle\Models\JournalAudit;

class ManageAttributionsBoutiques extends ManageRecords
{
    protected static string $resource = AttributionBoutiqueResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Attributions de boutiques',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn () => auth()->user()->can('ajouter_attribution'))
                ->modalHeading('Nouvelle attribution de boutique')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->after(fn (AttributionBoutique $record) => JournalAudit::enregistrer(
                    'Création attribution',
                    'ARTISANAT',
                    'AttributionBoutique',
                    $record->id,
                    [
                        'boutique' => $record->boutique?->numero,
                        'artisan' => $record->artisan?->matricule,
                        'periode' => $record->libellePeriode(),
                        'redevance' => $record->redevance_convenue,
                    ],
                )),
        ];
    }
}
