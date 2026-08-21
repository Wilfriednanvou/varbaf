<?php

namespace Modules\Commerce\Filament\Resources\TauxCommissionResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Commerce\Filament\Resources\TauxCommissionResource;
use Modules\Commerce\Models\TauxCommission;
use Modules\Socle\Models\JournalAudit;

class ManageTauxCommission extends ManageRecords
{
    protected static string $resource = TauxCommissionResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Taux de commission',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Nouveau taux')
                ->visible(fn () => auth()->user()->can('ajouter_taux_commission'))
                ->modalHeading('Nouveau taux de commission')
                ->modalDescription('Le taux s\'applique à toutes les ventes à partir de sa date d\'effet. Le taux précédent reste en base : c\'est lui qui continue de s\'appliquer aux ventes antérieures.')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->after(fn (TauxCommission $record) => JournalAudit::enregistrer(
                    'Création taux de commission',
                    'COMMERCE',
                    'TauxCommission',
                    $record->id,
                    [
                        'taux' => $record->taux,
                        'date_effet' => $record->date_effet?->toDateString(),
                        'decision' => $record->reference_decision,
                    ],
                )),
        ];
    }
}
