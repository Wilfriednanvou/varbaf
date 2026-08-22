<?php

namespace Modules\Tresorerie\Filament\Resources\SectionCaisseResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Socle\Models\JournalAudit;
use Modules\Tresorerie\Filament\Resources\SectionCaisseResource;

class ManageSectionsCaisse extends ManageRecords
{
    protected static string $resource = SectionCaisseResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Sections de caisse',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Ouvrir une section')
                ->visible(fn () => auth()->user()->can('ouvrir_section_caisse'))
                ->modalHeading('Ouvrir une section de caisse')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->before(function (Actions\CreateAction $action, array $data) {
                    // RG-01 : vérifier qu'aucune section n'est déjà ouverte
                    $sectionOuverte = \Modules\Tresorerie\Models\SectionCaisse::query()
                        ->where('caisse_id', $data['caisse_id'])
                        ->where('etat', 'OUVERTE')
                        ->first();

                    if ($sectionOuverte) {
                        \Filament\Notifications\Notification::make()
                            ->title('Section déjà ouverte')
                            ->body("La section « {$sectionOuverte->libelle} » est déjà ouverte sur cette caisse. Clôturez-la avant d'en ouvrir une autre.")
                            ->danger()
                            ->send();

                        $action->halt();
                    }
                })
                ->after(fn ($record) => JournalAudit::enregistrer(
                    'Ouverture section de caisse', 'TRESORERIE', 'SectionCaisse', $record->id,
                    ['libelle' => $record->libelle, 'solde_ouverture' => $record->solde_ouverture]
                )),
        ];
    }
}
