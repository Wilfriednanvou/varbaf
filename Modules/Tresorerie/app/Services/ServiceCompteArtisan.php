<?php

namespace Modules\Tresorerie\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Artisanat\Models\Artisan;
use Modules\Commerce\Enums\EtatVente;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
     * Le compte de **tous** les artisans, en une seule requête.
     *
     * **Pourquoi ce n'est pas une boucle sur `soldeDu()`.** L'écran des
     * reversements affiche les cinquante-cinq artisans du registre. Les
     * trois lectures unitaires appelées pour chacun feraient cent
     * soixante-cinq requêtes par affichage — et la lenteur n'arriverait
     * pas d'un coup : elle croîtrait avec le nombre d'artisans, donc
     * personne ne saurait dire quel changement l'a causée. Le même
     * raisonnement que DT-06, tranché dans l'autre sens parce qu'ici
     * l'échelle est connue et déjà atteinte.
     *
     * Les deux agrégats sont des sous-requêtes jointes en `LEFT JOIN` :
     * un artisan qui n'a jamais vendu doit apparaître à zéro, pas
     * disparaître. C'est même le cas qui intéresse la coordination —
     * un artisan installé qui ne vend rien.
     *
     * Ce que la méthode ne fait pas : décider. Elle rend des montants ;
     * c'est la campagne de reversement qui décide qui est payé, et elle
     * seule (RG-11).
     *
     * @return Collection<int, object>
     */
    public function comptesDeTousLesArtisans(): Collection
    {
        $ventes = DB::table('ventes')
            ->selectRaw('artisan_id')
            ->selectRaw('count(*) as nombre_ventes')
            ->selectRaw('coalesce(sum(montant_total), 0) as montant_vendu')
            ->selectRaw('coalesce(sum(montant_commission), 0) as commission')
            ->selectRaw('coalesce(sum(part_artisan), 0) as part_artisan')
            ->where('etat', EtatVente::VALIDEE->value)
            ->groupBy('artisan_id');

        $reverses = DB::table('reversements as r')
            ->join('campagnes_reversement as c', 'c.id', '=', 'r.campagne_id')
            ->selectRaw('r.artisan_id')
            ->selectRaw('coalesce(sum(r.montant_paye), 0) as total_reverse')
            // Seules les campagnes validées comptent : une campagne en
            // préparation n'a rien décaissé. Même règle que
            // `totalReverse()`, et elle doit le rester — deux définitions
            // du même solde finiraient par diverger.
            ->where('c.statut', StatutCampagneReversement::VALIDEE->value)
            ->groupBy('r.artisan_id');

        return collect(
            DB::table('artisans as a')
                ->leftJoinSub($ventes, 'v', 'v.artisan_id', '=', 'a.id')
                ->leftJoinSub($reverses, 'p', 'p.artisan_id', '=', 'a.id')
                ->select('a.id', 'a.matricule', 'a.nom', 'a.prenom', 'a.actif')
                ->selectRaw('coalesce(v.nombre_ventes, 0) as nombre_ventes')
                ->selectRaw('coalesce(v.montant_vendu, 0) as montant_vendu')
                ->selectRaw('coalesce(v.commission, 0) as commission')
                ->selectRaw('coalesce(v.part_artisan, 0) as part_artisan')
                ->selectRaw('coalesce(p.total_reverse, 0) as total_reverse')
                // RG-15 : le solde dû est une soustraction, jamais une
                // colonne. Il est calculé ici comme il l'est dans
                // `soldeDu()`, et pour la même raison — un solde stocké
                // se met à jour, donc se trompe.
                ->selectRaw('coalesce(v.part_artisan, 0) - coalesce(p.total_reverse, 0) as solde_du')
                ->orderByDesc('solde_du')
                ->orderBy('a.nom')
                ->get()
        );
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
