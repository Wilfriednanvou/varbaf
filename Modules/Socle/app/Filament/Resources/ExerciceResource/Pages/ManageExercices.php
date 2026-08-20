<?php

namespace Modules\Socle\Filament\Resources\ExerciceResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Socle\Filament\Resources\ExerciceResource;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\JournalAudit;

class ManageExercices extends ManageRecords
{
    protected static string $resource = ExerciceResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Exercices',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn () => auth()->user()->can('ajouter_exercice'))
                ->modalHeading('Nouvel exercice')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->after(fn (Exercice $record) => JournalAudit::enregistrer(
                    'Création exercice',
                    'SOCLE',
                    'Exercice',
                    $record->id,
                    ['libelle' => $record->libelle, 'en_cours' => $record->en_cours],
                )),
        ];
    }
}
