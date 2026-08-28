<?php

namespace Modules\Artisanat\Filament\Resources\BoutiqueResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Artisanat\Filament\Resources\BoutiqueResource;
use Modules\Artisanat\Filament\Widgets\StatistiquesBoutiques;
use Modules\Artisanat\Models\Boutique;
use Modules\Socle\Filament\Concerns\TitreLisible;
use Modules\Socle\Models\JournalAudit;

class ManageBoutiques extends ManageRecords
{
    // Filament capitalise chaque mot du libelle pluriel pour en
    // faire le titre : « Corps De Metier » la ou le menu et le fil
    // d'Ariane disent « Corps de metier ». Voir le trait.
    use TitreLisible;

    protected static string $resource = BoutiqueResource::class;

    /**
     * Les indicateurs se lisent **au-dessus** du tableau : un chiffre
     * placé sous une liste n'est rencontré qu'une fois la lecture finie.
     *
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            StatistiquesBoutiques::class,
        ];
    }

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Boutiques',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn () => auth()->user()->can('ajouter_boutique'))
                ->modalHeading('Nouvelle boutique')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->after(fn (Boutique $record) => JournalAudit::enregistrer(
                    'Création boutique',
                    'ARTISANAT',
                    'Boutique',
                    $record->id,
                    ['numero' => $record->numero],
                )),
        ];
    }
}
