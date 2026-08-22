<?php

namespace Modules\Commerce\Filament\Resources\VenteResource\Pages;

use Filament\Resources\Pages\ManageRecords;
use Modules\Commerce\Contracts\JournalDeCaisse;
use Modules\Commerce\Filament\Resources\VenteResource;
use Modules\Commerce\Models\TauxCommission;

/**
 * Écran de consultation. La saisie n'a qu'un chemin : le composant
 * Livewire `VentesCaisseTable` de la session de caisse — voir
 * `docs/specification-tresorerie.md` §7.5. Aucune action d'en-tête ici.
 */
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
}
