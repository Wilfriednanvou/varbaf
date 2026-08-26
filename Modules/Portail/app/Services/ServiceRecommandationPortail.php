<?php

namespace Modules\Portail\Services;

use Illuminate\Database\Query\Builder as RequeteBrute;
use Illuminate\Support\Collection;
use Modules\Pilotage\Recommandation\CriteresDeVoisinage;
use Modules\Pilotage\Recommandation\ProduitVoisin;
use Modules\Pilotage\Services\ServiceRecommandationProduit;
use Modules\Portail\Models\PublicationProduit;

/**
 * Les produits similaires, tels que le portail public a le droit de les
 * montrer.
 *
 * **Le portail ne réécrit pas ses règles de visibilité, il les prête au
 * moteur.** `ServicePortail::identifiantsVisibles()` est la requête qui
 * décide, partout ailleurs, de ce qu'un visiteur peut voir : publication
 * active, produit exposé, artisan consentant. Elle est passée telle
 * quelle au moteur, qui l'applique à l'intérieur de sa propre requête.
 *
 * Deux conséquences, et c'est tout l'intérêt du procédé. Il n'existe
 * qu'une seule définition de « visible » : le jour où elle change, la
 * recommandation suit sans qu'on y pense, et les règles éprouvées par
 * `PortailPublicationTest` valent ici sans exception. Et le nombre de
 * suggestions demandé est réellement rendu — filtrer après coup une
 * liste déjà limitée en aurait retiré des lignes sans les remplacer.
 *
 * **Le stock épuisé n'est pas exclu**, et c'est délibéré : sur le
 * portail, un produit sans stock est annoncé « sur commande » parce
 * qu'un artisan peut le refaire. Le masquer contredirait le catalogue,
 * la fiche produit, et le comportement qu'éprouve `PortailPublicTest`.
 *
 * La dépendance va bien vers le bas : le Portail est le module 6, le
 * Pilotage le module 5.
 */
class ServiceRecommandationPortail
{
    public function __construct(
        protected ServicePortail $portail,
        protected ServiceRecommandationProduit $recommandation,
    ) {}

    /**
     * Les publications proches d'une publication donnée, dans l'ordre du
     * classement.
     *
     * @return Collection<int, PublicationProduit>
     */
    public function produitsSimilaires(PublicationProduit $publication, ?int $limite = null): Collection
    {
        $produitId = $publication->produit_id;

        if (! $produitId) {
            return new Collection();
        }

        $voisins = $this->recommandation->voisins(
            (int) $produitId,
            $this->criteres($limite),
        );

        if ($voisins->isEmpty()) {
            return new Collection();
        }

        return $this->publicationsDans($voisins);
    }

    /**
     * Le nom du moteur, affiché à côté du bloc.
     *
     * Un visiteur qui voit des suggestions doit pouvoir savoir sur quoi
     * elles reposent. C'est aussi ce qui rendra visible, le jour venu,
     * le passage à une branche dense ou le repli sur celle-ci.
     */
    public function nomDuMoteur(): ?string
    {
        return $this->recommandation->nomDuMoteur();
    }

    protected function criteres(?int $limite): CriteresDeVoisinage
    {
        return CriteresDeVoisinage::depuisLaConfiguration(
            limite: $limite,
            exclureStockEpuise: false,
            restreindre: fn (RequeteBrute $requete) => $requete->whereIn(
                'p.id',
                $this->portail->identifiantsVisibles(),
            ),
        );
    }

    /**
     * Charge les publications des produits retenus, en conservant
     * l'ordre du classement — que `whereIn` ne garantit pas.
     *
     * @param  Collection<int, ProduitVoisin>  $voisins
     * @return Collection<int, PublicationProduit>
     */
    protected function publicationsDans(Collection $voisins): Collection
    {
        $publications = PublicationProduit::query()
            ->visible()
            ->with(['produit.artisan.corpsMetier', 'produit.categorie'])
            ->whereIn('produit_id', $voisins->pluck('produitId')->all())
            ->get()
            ->keyBy('produit_id');

        return $voisins
            ->map(fn (ProduitVoisin $voisin): ?PublicationProduit => $publications->get($voisin->produitId))
            ->filter()
            ->values();
    }
}
