<?php

namespace Modules\Tresorerie\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Artisanat\Models\Artisan;
use Modules\Commerce\Enums\EtatVente;
use Modules\Commerce\Models\Vente;
use Modules\Tresorerie\Enums\StatutCampagneReversement;
use Modules\Tresorerie\Models\Reversement;

/**
 * Situation financière d'un artisan (RG-15).
 *
 * Le solde dû n'est jamais stocké : il se recalcule à chaque appel
 * depuis les ventes validées et les reversements, exactement comme le
 * stock d'un produit se recalcule depuis `mouvements_stock`. Une vente
 * annulée sort du calcul dès son annulation — c'est le filtre
 * `EtatVente::VALIDEE`, pas une correction manuelle, qui le garantit.
 *
 * **Des agrégats, pas des collections.** Les trois totaux sont calculés
 * par la base. `totalVendu()` chargeait auparavant tout l'historique des
 * ventes d'un artisan en mémoire pour en faire la somme en PHP : sur un
 * exercice réel, cela veut dire des milliers de modèles hydratés à
 * chaque affichage d'une fiche, et la dérive serait passée inaperçue
 * jusqu'au jour où l'écran serait devenu lent sans raison visible.
 */
class ServiceCompteArtisan
{
    /**
     * Cumul des parts revenant à l'artisan sur ses ventes validées.
     */
    public function totalVendu(Artisan $artisan): int
    {
        return (int) $this->requeteVentesValidees($artisan)->sum('part_artisan');
    }

    /**
     * Cumul de ce qui lui a effectivement été versé.
     *
     * Seules les campagnes validées comptent : une campagne en
     * préparation n'a rien décaissé, et faire baisser le solde dû sur
     * la foi d'un calcul non validé afficherait une dette éteinte alors
     * que l'argent n'est pas sorti.
     */
    public function totalReverse(Artisan $artisan): int
    {
        return (int) Reversement::query()
            ->where('artisan_id', $artisan->getKey())
            ->whereHas(
                'campagne',
                fn (Builder $requete) => $requete->where(
                    'statut',
                    StatutCampagneReversement::VALIDEE->value,
                ),
            )
            ->sum('montant_paye');
    }

    /**
     * RG-15 : somme des parts artisan moins somme des reversements.
     */
    public function soldeDu(Artisan $artisan): int
    {
        return $this->totalVendu($artisan) - $this->totalReverse($artisan);
    }

    /**
     * Les ventes validées de l'artisan, paginées.
     *
     * Paginé et non rendu en bloc : c'est l'historique d'un exercice
     * entier, et l'écran n'en montre qu'une page à la fois.
     */
    public function ventesValidees(Artisan $artisan, int $parPage = 25): LengthAwarePaginator
    {
        return $this->requeteVentesValidees($artisan)
            ->orderByDesc('date_vente')
            ->paginate($parPage);
    }

    /**
     * Socle commun des trois lectures : les ventes qui comptent.
     */
    protected function requeteVentesValidees(Artisan $artisan): Builder
    {
        return Vente::query()
            ->where('artisan_id', $artisan->getKey())
            ->where('etat', EtatVente::VALIDEE->value);
    }
}
