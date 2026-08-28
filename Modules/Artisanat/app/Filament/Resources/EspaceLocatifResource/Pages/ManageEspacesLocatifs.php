<?php

namespace Modules\Artisanat\Filament\Resources\EspaceLocatifResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Artisanat\Filament\Resources\EspaceLocatifResource;
use Modules\Artisanat\Filament\Widgets\StatistiquesEspacesLocatifs;
use Modules\Artisanat\Models\EspaceLocatif;
use Modules\Socle\Filament\Concerns\TitreLisible;
use Modules\Socle\Models\JournalAudit;

class ManageEspacesLocatifs extends ManageRecords
{
    // Filament capitalise chaque mot du libelle pluriel pour en
    // faire le titre : « Corps De Metier » la ou le menu et le fil
    // d'Ariane disent « Corps de metier ». Voir le trait.
    use TitreLisible;

    protected static string $resource = EspaceLocatifResource::class;

    /**
     * Les indicateurs se lisent **au-dessus** du tableau : un chiffre
     * placé sous une liste n'est rencontré qu'une fois la lecture finie.
     *
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            StatistiquesEspacesLocatifs::class,
        ];
    }

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Espaces locatifs',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn () => auth()->user()->can('ajouter_espace_locatif'))
                ->modalHeading('Nouvel espace locatif')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->after(fn (EspaceLocatif $record) => JournalAudit::enregistrer(
                    'Création espace locatif',
                    'ARTISANAT',
                    'EspaceLocatif',
                    $record->id,
                    ['code' => $record->code, 'boutique' => $record->boutique?->numero],
                )),
        ];
    }
}
