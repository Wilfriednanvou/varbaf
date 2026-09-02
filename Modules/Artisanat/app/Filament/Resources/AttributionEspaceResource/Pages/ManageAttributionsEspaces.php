<?php

namespace Modules\Artisanat\Filament\Resources\AttributionEspaceResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Artisanat\Filament\Resources\AttributionEspaceResource;
use Modules\Artisanat\Filament\Widgets\StatistiquesAttributions;
use Modules\Artisanat\Models\AttributionEspace;
use Modules\Socle\Filament\Concerns\TitreLisible;
use Modules\Socle\Models\JournalAudit;
use Modules\Socle\Services\ContexteExercice;

class ManageAttributionsEspaces extends ManageRecords
{
    // Filament capitalise chaque mot du libelle pluriel pour en
    // faire le titre : « Corps De Metier » la ou le menu et le fil
    // d'Ariane disent « Corps de metier ». Voir le trait.
    use TitreLisible;

    protected static string $resource = AttributionEspaceResource::class;

    /**
     * Les indicateurs se lisent **au-dessus** du tableau : un chiffre
     * placé sous une liste n'est rencontré qu'une fois la lecture finie.
     *
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            StatistiquesAttributions::class,
        ];
    }

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
                ->visible(fn () => auth()->user()->can('ajouter_attribution')
                    && app(ContexteExercice::class)->estModifiable())
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
