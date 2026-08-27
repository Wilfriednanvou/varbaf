<?php

namespace Modules\Pilotage\Services;

use Illuminate\Support\Collection;
use Modules\Commerce\Models\Produit;
use Modules\Pilotage\Recommandation\CriteresDeVoisinage;
use Modules\Pilotage\Recommandation\ProduitVoisin;
use Modules\Pilotage\Recommandation\ResolveurDeMoteur;

/**
 * Le point d'entrée des surfaces qui veulent des produits proches.
 *
 * Une couche mince au-dessus du résolveur : elle traduit un produit en
 * voisins, sait dire quel moteur a répondu, et rend les modèles quand
 * l'appelant en a besoin. Aucune règle métier ici — elles sont dans le
 * moteur pour les universelles, dans les critères pour celles qui
 * appartiennent à la surface.
 *
 * Elle ne lève jamais : une fiche produit du portail doit rester
 * lisible même si l'index n'a pas encore été construit.
 */
class ServiceRecommandationProduit
{
    public function __construct(protected ResolveurDeMoteur $resolveur) {}

    /**
     * Les voisins d'un produit, du plus proche au moins proche.
     *
     * @return Collection<int, ProduitVoisin>
     */
    public function voisins(Produit|int $produit, ?CriteresDeVoisinage $criteres = null): Collection
    {
        $moteur = $this->resolveur->resoudreOuNul();

        if ($moteur === null) {
            return new Collection();
        }

        return $moteur->voisins(
            $produit instanceof Produit ? (int) $produit->getKey() : $produit,
            $criteres ?? CriteresDeVoisinage::depuisLaConfiguration(),
        );
    }

    /**
     * Les mêmes voisins, hydratés en modèles, **dans l'ordre du score**.
     *
     * `whereIn` rend les lignes dans l'ordre de la base, qui n'est pas
     * celui du classement : la collection est donc réordonnée depuis les
     * voisins. Sans cela, la meilleure suggestion se retrouverait en
     * troisième position sans que rien ne le signale.
     *
     * @return Collection<int, Produit>
     */
    public function produitsVoisins(Produit|int $produit, ?CriteresDeVoisinage $criteres = null): Collection
    {
        $voisins = $this->voisins($produit, $criteres);

        if ($voisins->isEmpty()) {
            return new Collection();
        }

        $identifiants = $voisins->pluck('produitId')->all();

        $produits = Produit::query()
            ->with(['artisan.corpsMetier', 'categorie', 'boutique'])
            ->whereIn('id', $identifiants)
            ->get()
            ->keyBy('id');

        return $voisins
            ->map(fn (ProduitVoisin $voisin): ?Produit => $produits->get($voisin->produitId))
            ->filter()
            ->values();
    }

    /**
     * Le nom de ce qui a calculé les suggestions affichées à côté.
     *
     * `nomDuVoisinage()` et non `nom()` : ce service ne fait que du
     * voisinage, et un moteur composite n'engage pas forcément les
     * mêmes branches dans les deux opérations. Voir `MoteurSemantique`.
     *
     * Null si aucun moteur n'est disponible.
     */
    public function nomDuMoteur(): ?string
    {
        return $this->resolveur->resoudreOuNul()?->nomDuVoisinage();
    }
}
