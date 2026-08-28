<?php

namespace Modules\Artisanat\Filament\Resources\CorpsMetierResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Artisanat\Filament\Resources\CorpsMetierResource;
use Modules\Artisanat\Filament\Widgets\StatistiquesCorpsMetiers;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Socle\Filament\Concerns\TitreLisible;
use Modules\Socle\Models\JournalAudit;

class ManageCorpsMetiers extends ManageRecords
{
    // Filament capitalise chaque mot du libelle pluriel pour en
    // faire le titre : « Corps De Metier » la ou le menu et le fil
    // d'Ariane disent « Corps de metier ». Voir le trait.
    use TitreLisible;

    protected static string $resource = CorpsMetierResource::class;

    /**
     * Les indicateurs se lisent **au-dessus** du tableau : un chiffre
     * placé sous une liste n'est rencontré qu'une fois la lecture finie.
     *
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            StatistiquesCorpsMetiers::class,
        ];
    }

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Corps de métier',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn () => auth()->user()->can('ajouter_corps_metier'))
                ->modalHeading('Nouveau corps de métier')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->after(fn (CorpsMetier $record) => JournalAudit::enregistrer(
                    'Création corps de métier',
                    'ARTISANAT',
                    'CorpsMetier',
                    $record->id,
                    ['code' => $record->code, 'libelle' => $record->libelle],
                )),
        ];
    }
}
