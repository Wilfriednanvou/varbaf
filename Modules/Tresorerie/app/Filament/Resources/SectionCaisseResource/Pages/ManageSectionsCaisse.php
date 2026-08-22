<?php

namespace Modules\Tresorerie\Filament\Resources\SectionCaisseResource\Pages;

use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\JournalAudit;
use Modules\Tresorerie\Filament\Resources\SectionCaisseResource;
use Modules\Tresorerie\Models\Caisse;
use Modules\Tresorerie\Models\SectionCaisse;

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
                    $sectionOuverte = SectionCaisse::query()
                        ->where('caisse_id', $data['caisse_id'])
                        ->where('etat', 'OUVERTE')
                        ->first();

                    if ($sectionOuverte) {
                        Notification::make()
                            ->title('Section déjà ouverte')
                            ->body("La section « {$sectionOuverte->libelle} » est déjà ouverte sur cette caisse. Clôturez-la avant d'en ouvrir une autre.")
                            ->danger()
                            ->send();

                        $action->halt();
                    }

                    // `exercice_id` est NOT NULL et n'est pas saisi : sans
                    // exercice en cours, l'ouverture doit être refusée par
                    // un message, pas par une erreur d'insertion.
                    if (! Exercice::courant()) {
                        Notification::make()
                            ->title('Aucun exercice en cours')
                            ->body("Ouvrez un exercice avant d'ouvrir une section de caisse.")
                            ->danger()
                            ->send();

                        $action->halt();
                    }
                })
                ->mutateFormDataUsing(function (array $data): array {
                    // Deux valeurs dérivées, posées ici plutôt que dans le
                    // formulaire : le village est celui de la caisse
                    // choisie, l'exercice est celui en cours. Le Socle
                    // expose `Exercice::courant()` précisément pour que
                    // les autres modules ne requêtent pas sa table.
                    $data['village_id'] = Caisse::findOrFail($data['caisse_id'])->village_id;
                    $data['exercice_id'] = Exercice::courant()?->getKey();

                    return $data;
                })
                ->after(fn ($record) => JournalAudit::enregistrer(
                    'Ouverture section de caisse', 'TRESORERIE', 'SectionCaisse', $record->id,
                    ['libelle' => $record->libelle, 'solde_ouverture' => $record->solde_ouverture]
                )),
        ];
    }
}
