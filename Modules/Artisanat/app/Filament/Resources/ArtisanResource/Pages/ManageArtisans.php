<?php

namespace Modules\Artisanat\Filament\Resources\ArtisanResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Artisanat\Filament\Resources\ArtisanResource;
use Modules\Artisanat\Filament\Widgets\StatistiquesArtisans;
use Modules\Artisanat\Models\Artisan;
use Modules\Socle\Filament\Concerns\TitreLisible;
use Modules\Socle\Models\JournalAudit;

class ManageArtisans extends ManageRecords
{
    // Sans ce trait, Filament afficherait « Artisans » correctement mais
    // « Corps De Métier » ailleurs : il capitalise chaque mot du libellé
    // pluriel. Voir le trait pour le détail.
    use TitreLisible;

    protected static string $resource = ArtisanResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Artisans',
        ];
    }

    /**
     * Les indicateurs se lisent **au-dessus** du tableau.
     *
     * Un chiffre placé sous une liste de quarante lignes n'est jamais lu :
     * il faut avoir fini de parcourir ce qu'on venait chercher pour le
     * rencontrer. Au-dessus, il cadre la lecture au lieu de la conclure.
     *
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            StatistiquesArtisans::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn () => auth()->user()->can('ajouter_artisan'))
                ->modalHeading('Nouvel artisan')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->after(fn (Artisan $record) => JournalAudit::enregistrer(
                    'Création artisan',
                    'ARTISANAT',
                    'Artisan',
                    $record->id,
                    ['matricule' => $record->matricule, 'nom' => $record->nom_complet],
                )),
        ];
    }
}
