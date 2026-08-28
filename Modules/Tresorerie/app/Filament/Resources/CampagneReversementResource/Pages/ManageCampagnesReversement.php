<?php

namespace Modules\Tresorerie\Filament\Resources\CampagneReversementResource\Pages;

use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Socle\Filament\Concerns\TitreLisible;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\JournalAudit;
use Modules\Tresorerie\Filament\Resources\CampagneReversementResource;

class ManageCampagnesReversement extends ManageRecords
{
    // Filament capitalise chaque mot du libelle pluriel pour en
    // faire le titre : « Corps De Metier » la ou le menu et le fil
    // d'Ariane disent « Corps de metier ». Voir le trait.
    use TitreLisible;

    protected static string $resource = CampagneReversementResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Campagnes de reversement',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Ouvrir une campagne')
                ->visible(fn () => auth()->user()->can('preparer_campagne_reversement'))
                ->modalHeading('Ouvrir une campagne de reversement')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->before(function (Actions\CreateAction $action) {
                    // `exercice_id` est NOT NULL et n'est pas saisi :
                    // sans exercice en cours, mieux vaut un message
                    // qu'une erreur d'insertion.
                    if (! Exercice::courant()) {
                        Notification::make()
                            ->title('Aucun exercice en cours')
                            ->body("Ouvrez un exercice avant d'ouvrir une campagne de reversement.")
                            ->danger()
                            ->send();

                        $action->halt();
                    }
                })
                ->mutateFormDataUsing(function (array $data): array {
                    // L'exercice de la campagne est celui en cours.
                    // `Exercice::courant()` est le point d'entrée que le
                    // Socle expose : aucun autre module ne requête sa
                    // table directement.
                    $data['exercice_id'] = Exercice::courant()?->getKey();

                    return $data;
                })
                ->after(fn ($record) => JournalAudit::enregistrer(
                    'Ouverture campagne de reversement',
                    'TRESORERIE',
                    'CampagneReversement',
                    $record->id,
                    [
                        'periode' => $record->libellePeriode(),
                        'date_arrete' => $record->date_arrete?->toDateString(),
                    ],
                )),
        ];
    }
}
