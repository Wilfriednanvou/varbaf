<?php

namespace Modules\Artisanat\Filament\Resources\AttributionEspaceResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Artisanat\Filament\Resources\AttributionEspaceResource;
use Modules\Artisanat\Models\AttributionEspace;
use Modules\Socle\Models\JournalAudit;

class ManageAttributionsEspaces extends ManageRecords
{
    protected static string $resource = AttributionEspaceResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Attributions d\'espaces',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn () => auth()->user()->can('ajouter_attribution'))
                ->modalHeading('Nouvelle attribution d\'espace')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->after(fn (AttributionEspace $record) => JournalAudit::enregistrer(
                    'Création attribution',
                    'ARTISANAT',
                    'AttributionEspace',
                    $record->id,
                    [
                        'espace' => $record->espaceLocatif?->code,
                        'artisan' => $record->artisan?->matricule,
                        'periode' => $record->libellePeriode(),
                        'redevance' => $record->redevance_convenue,
                    ],
                )),
        ];
    }
}
