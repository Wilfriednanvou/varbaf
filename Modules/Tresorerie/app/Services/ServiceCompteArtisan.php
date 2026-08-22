<?php

namespace Modules\Tresorerie\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Artisanat\Models\Artisan;
use Modules\Commerce\Enums\EtatVente;
use Modules\Commerce\Models\Vente;

/**
 * Situation financière d'un artisan (RG-15).
 *
 * Le solde dû n'est jamais stocké : il se recalcule à chaque appel
 * depuis les ventes validées et les reversements, exactement comme le
 * stock d'un produit se recalcule depuis `mouvements_stock`. Une vente
 * annulée sort du calcul dès son annulation — c'est le filtre
 * `EtatVente::VALIDEE`, pas une correction manuelle, qui le garantit.
 *
 * `totalReverse()` renvoie `0` : aucune campagne de reversement n'existe
 * encore (tranche C, non construite). Le jour où `Reversement` existera,
 * seule cette méthode changera — `soldeDu()` restera correct par
 * construction.
 */
class ServiceCompteArtisan
{
    public function totalVendu(Artisan $artisan): int
    {
        return (int) $this->ventesValidees($artisan)->sum('part_artisan');
    }

    public function totalReverse(Artisan $artisan): int
    {
        return 0;
    }

    public function soldeDu(Artisan $artisan): int
    {
        return $this->totalVendu($artisan) - $this->totalReverse($artisan);
    }

    /**
     * @return Collection<int, Vente>
     */
    public function ventesValidees(Artisan $artisan): Collection
    {
        return Vente::query()
            ->where('artisan_id', $artisan->getKey())
            ->where('etat', EtatVente::VALIDEE->value)
            ->orderByDesc('date_vente')
            ->get();
    }
}
