<?php

namespace Modules\Commerce\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Enums\SensMouvementStock;
use Modules\Commerce\Enums\TypeMouvementStock;
use Modules\Commerce\Events\SeuilAlerteFranchi;
use Modules\Commerce\Exceptions\MouvementStockImmuableException;
use Modules\Commerce\Exceptions\StockInsuffisantException;
use Modules\Commerce\Models\MouvementStock;
use Modules\Commerce\Models\Produit;

/**
 * Point d'entrée unique du journal de stock (règle 3 de CLAUDE.md).
 *
 * Aucun module n'insère directement dans `mouvements_stock` : dépôts,
 * ventes, retraits et pertes passent tous par ici. C'est la seule
 * façon de garantir trois choses à la fois — la numérotation sans
 * rupture, l'impossibilité d'un stock négatif, et la détection du
 * franchissement de seuil.
 *
 * Le service est le jumeau du service d'écriture au brouillard de
 * caisse (RG-06) qui arrivera au module Trésorerie. La symétrie est
 * délibérée : les enjeux sont moindres sur le stock, c'est donc le bon
 * endroit pour rôder le patron.
 */
class ServiceMouvementStock
{
    /**
     * Solde en stock d'un produit : cumul des entrées moins les
     * sorties.
     *
     * Toujours recalculé depuis le journal, jamais lu dans une colonne
     * du produit. `solde_apres` sur chaque ligne n'est qu'une commodité
     * de lecture.
     */
    public function solde(Produit $produit): int
    {
        $entrees = (int) MouvementStock::query()
            ->where('produit_id', $produit->getKey())
            ->where('sens', SensMouvementStock::ENTREE->value)
            ->sum('quantite');

        $sorties = (int) MouvementStock::query()
            ->where('produit_id', $produit->getKey())
            ->where('sens', SensMouvementStock::SORTIE->value)
            ->sum('quantite');

        return $entrees - $sorties;
    }

    /**
     * Écrit une ligne au journal.
     *
     * Toute l'opération tient dans une transaction avec verrou sur les
     * lignes du produit : sans cela, deux ventes simultanées du même
     * article pourraient lire le même solde, attribuer le même numéro
     * d'ordre, et laisser passer une sortie de trop.
     *
     * @throws StockInsuffisantException
     */
    public function enregistrer(
        Produit $produit,
        SensMouvementStock $sens,
        TypeMouvementStock $type,
        int $quantite,
        ?string $motif = null,
        ?Model $origine = null,
        ?MouvementStock $contrepasse = null,
    ): MouvementStock {
        if ($quantite <= 0) {
            throw StockInsuffisantException::quantiteInvalide($quantite);
        }

        [$mouvement, $soldeAvant] = DB::transaction(function () use (
            $produit, $sens, $type, $quantite, $motif, $origine, $contrepasse
        ): array {
            MouvementStock::query()
                ->where('produit_id', $produit->getKey())
                ->lockForUpdate()
                ->get();

            $soldeAvant = $this->solde($produit);

            if ($sens === SensMouvementStock::SORTIE && $quantite > $soldeAvant) {
                throw StockInsuffisantException::pour($produit->identite, $quantite, $soldeAvant);
            }

            $dernierNumero = (int) MouvementStock::query()
                ->where('produit_id', $produit->getKey())
                ->max('numero_ordre');

            $mouvement = MouvementStock::query()->create([
                'numero_ordre' => $dernierNumero + 1,
                'date_mouvement' => now(),
                'produit_id' => $produit->getKey(),
                'sens' => $sens,
                'type' => $type,
                'quantite' => $quantite,
                'solde_apres' => $soldeAvant + ($sens->signe() * $quantite),
                'motif' => $motif,
                'origine_type' => $origine ? class_basename($origine) : null,
                'origine_id' => $origine?->getKey(),
                'mouvement_contrepasse_id' => $contrepasse?->getKey(),
                'saisi_par' => Auth::id(),
            ]);

            return [$mouvement, $soldeAvant];
        });

        $this->signalerFranchissementDeSeuil($produit, $soldeAvant, $mouvement);

        return $mouvement;
    }

    /**
     * Entrée en stock au titre d'un dépôt.
     */
    public function deposer(Produit $produit, int $quantite, ?Model $origine = null, ?string $motif = null): MouvementStock
    {
        return $this->enregistrer(
            $produit,
            SensMouvementStock::ENTREE,
            TypeMouvementStock::DEPOT,
            $quantite,
            $motif,
            $origine,
        );
    }

    /**
     * Reprise d'articles par l'artisan.
     *
     * Le contrôle du solde vit dans `enregistrer()`, pas ici : il doit
     * valoir identiquement pour la vente et pour la perte.
     */
    public function retirer(Produit $produit, int $quantite, string $motif, ?Model $origine = null): MouvementStock
    {
        return $this->enregistrer(
            $produit,
            SensMouvementStock::SORTIE,
            TypeMouvementStock::RETRAIT,
            $quantite,
            $motif,
            $origine,
        );
    }

    public function constaterPerte(Produit $produit, int $quantite, string $motif): MouvementStock
    {
        return $this->enregistrer(
            $produit,
            SensMouvementStock::SORTIE,
            TypeMouvementStock::PERTE,
            $quantite,
            $motif,
        );
    }

    /**
     * Annule un mouvement par un mouvement de sens inverse.
     *
     * Le mouvement d'origine n'est pas touché : c'est tout l'intérêt.
     * Le journal montre l'erreur, puis sa correction, et le solde
     * redevient juste sans qu'aucune ligne n'ait menti entre-temps.
     *
     * @throws MouvementStockImmuableException
     */
    public function contrepasser(MouvementStock $mouvement, string $motif): MouvementStock
    {
        if ($mouvement->estUneContrepassation()) {
            throw MouvementStockImmuableException::contrepassationDeContrepassation();
        }

        if ($mouvement->estContrepasse()) {
            throw MouvementStockImmuableException::dejaContrepasse($mouvement->numero_ordre);
        }

        return $this->enregistrer(
            $mouvement->produit,
            $mouvement->sens->inverse(),
            TypeMouvementStock::CORRECTION,
            $mouvement->quantite,
            $motif,
            null,
            $mouvement,
        );
    }

    /**
     * Émet l'événement d'alerte au seul moment du franchissement.
     *
     * Trois conditions : le produit est surveillé, le stock était
     * au-dessus du seuil, il ne l'est plus. Un mouvement qui laisse le
     * stock sous le seuil sans l'y faire passer ne déclenche rien.
     */
    protected function signalerFranchissementDeSeuil(Produit $produit, int $soldeAvant, MouvementStock $mouvement): void
    {
        $seuil = $produit->seuil_alerte;

        if ($seuil === null) {
            return;
        }

        $soldeApres = $mouvement->solde_apres;

        if ($soldeAvant > $seuil && $soldeApres <= $seuil) {
            SeuilAlerteFranchi::dispatch($produit, $soldeAvant, $soldeApres, $seuil, $mouvement);
        }
    }
}
