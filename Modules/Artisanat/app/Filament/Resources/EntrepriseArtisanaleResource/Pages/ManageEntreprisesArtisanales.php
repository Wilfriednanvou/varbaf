<?php

namespace Modules\Artisanat\Filament\Resources\EntrepriseArtisanaleResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Artisanat\Filament\Resources\EntrepriseArtisanaleResource;
use Modules\Artisanat\Filament\Widgets\StatistiquesEntreprises;
use Modules\Artisanat\Models\EntrepriseArtisanale;
use Modules\Socle\Filament\Concerns\TitreLisible;
use Modules\Socle\Models\JournalAudit;

class ManageEntreprisesArtisanales extends ManageRecords
{
    // Filament capitalise chaque mot du libelle pluriel pour en
    // faire le titre : « Corps De Metier » la ou le menu et le fil
    // d'Ariane disent « Corps de metier ». Voir le trait.
    use TitreLisible;

    protected static string $resource = EntrepriseArtisanaleResource::class;

    /**
     * Les indicateurs se lisent **au-dessus** du tableau : un chiffre
     * placé sous une liste n'est rencontré qu'une fois la lecture finie.
     *
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            StatistiquesEntreprises::class,
        ];
    }

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Entreprises artisanales',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn () => auth()->user()->can('ajouter_entreprise'))
                ->modalHeading('Nouvelle entreprise artisanale')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->after(fn (EntrepriseArtisanale $record) => JournalAudit::enregistrer(
                    'Création entreprise artisanale',
                    'ARTISANAT',
                    'EntrepriseArtisanale',
                    $record->id,
                    ['raison_sociale' => $record->raison_sociale],
                )),
        ];
    }
}
