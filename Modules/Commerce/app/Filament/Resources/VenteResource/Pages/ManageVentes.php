<?php

namespace Modules\Commerce\Filament\Resources\VenteResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Commerce\Contracts\JournalDeCaisse;
use Modules\Commerce\Enums\ModeReglement;
use Modules\Commerce\Filament\Resources\VenteResource;
use Modules\Commerce\Models\TauxCommission;
use Modules\Commerce\Models\Vente;
use Modules\Commerce\Services\ServiceVente;
use Modules\Socle\Models\JournalAudit;

class ManageVentes extends ManageRecords
{
    protected static string $resource = VenteResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Ventes',
        ];
    }

    /**
     * Avertissements affichés au-dessus du tableau.
     *
     * Deux conditions rendent la saisie impossible ou trompeuse : pas
     * de taux en vigueur — la vente sera refusée — et brouillard non
     * branché — la recette n'entre pas encore en caisse. Mieux vaut le
     * dire en haut de l'écran que le découvrir au rapprochement.
     */
    public function getSubheading(): ?string
    {
        $avertissements = [];

        if (! TauxCommission::existeUnTauxEnVigueur()) {
            $avertissements[] = 'Aucun taux de commission n\'est en vigueur : la saisie de vente sera refusée.';
        }

        if (! app(JournalDeCaisse::class)->estOperationnel()) {
            $avertissements[] = 'Le brouillard de caisse n\'est pas encore branché : les encaissements ne sont pas repris en caisse.';
        }

        return $avertissements === [] ? null : implode(' ', $avertissements);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Nouvelle vente')
                ->visible(fn () => auth()->user()->can('ajouter_vente'))
                ->modalHeading('Saisie d\'une vente')
                ->modalDescription('Choisissez d\'abord la boutique, puis les produits de cette boutique. Une vente ne porte que sur une seule boutique.')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                // La création ne passe pas par Eloquent : le service est
                // le seul à savoir enchaîner lignes, stock et caisse
                // dans une transaction unique.
                ->using(fn (array $data) => app(ServiceVente::class)->enregistrer(
                    lignes: $data['lignes'] ?? [],
                    modeReglement: ModeReglement::from($data['mode_reglement'] ?? ModeReglement::ESPECES->value),
                    client: [
                        'nom_client' => $data['nom_client'] ?? null,
                        'contact_client' => $data['contact_client'] ?? null,
                        'accepte_notifications' => $data['accepte_notifications'] ?? false,
                        'provenance_client' => $data['provenance_client'] ?? null,
                    ],
                ))
                ->after(fn (Vente $record) => JournalAudit::enregistrer(
                    'Enregistrement vente',
                    'COMMERCE',
                    'Vente',
                    $record->id,
                    [
                        'numero' => $record->numero,
                        'montant' => $record->montant_total,
                        'commission' => $record->montant_commission,
                        'artisan' => $record->artisan?->matricule,
                    ],
                )),
        ];
    }
}
