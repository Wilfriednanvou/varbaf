<?php

namespace Modules\Tresorerie\Filament\Resources\MouvementCaisseResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Socle\Filament\Concerns\TitreLisible;
use Modules\Socle\Models\JournalAudit;
use Modules\Tresorerie\Filament\Resources\MouvementCaisseResource;
use Modules\Tresorerie\Services\ServiceTresorerie;

class ManageMouvementsCaisse extends ManageRecords
{
    // Filament capitalise chaque mot du libelle pluriel pour en
    // faire le titre : « Corps De Metier » la ou le menu et le fil
    // d'Ariane disent « Corps de metier ». Voir le trait.
    use TitreLisible;

    protected static string $resource = MouvementCaisseResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Brouillard de caisse',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Saisir un mouvement')
                ->visible(fn () => auth()->user()->can('saisir_mouvement_caisse'))
                ->modalHeading('Saisir un mouvement de caisse')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->using(function (array $data) {
                    $service = app(ServiceTresorerie::class);
                    $section = \Modules\Tresorerie\Models\SectionCaisse::findOrFail($data['section_id']);

                    return $service->enregistrer(
                        section: $section,
                        nature: \Modules\Tresorerie\Enums\NatureMouvementCaisse::from($data['nature']),
                        sens: \Modules\Tresorerie\Enums\SensMouvementCaisse::from($data['sens']),
                        montant: (float) $data['montant'],
                        libelle: $data['libelle'],
                        pieceJustificative: $data['piece_justificative'] ?? null,
                    );
                })
                ->after(fn ($record) => JournalAudit::enregistrer(
                    'Saisie mouvement de caisse', 'TRESORERIE', 'MouvementCaisse', $record->id,
                    ['numero_ordre' => $record->numero_ordre, 'montant' => $record->montant]
                )),
        ];
    }
}
