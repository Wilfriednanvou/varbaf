<?php

namespace Modules\Portail\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Portail\Enums\DisponibilitePortail;
use Modules\Portail\Models\PublicationProduit;
use Modules\Portail\Services\ServicePortail;
use Modules\Portail\Services\ServiceRecommandationPortail;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Catalogue public et fiche produit.
 *
 * **Un produit non visible n'existe pas.** Le service ne le rend pas, et
 * la fiche répond 404 — pas 403. Distinguer « ça n'existe pas » de
 * « vous n'y avez pas droit » renseignerait un visiteur sur ce que le
 * village a en réserve, ce qui est précisément ce que la règle de
 * non-exposition du stock cherche à éviter.
 */
class CatalogueController extends Controller
{
    public function __construct(
        protected ServicePortail $portail,
        protected ServiceRecommandationPortail $recommandation,
    ) {}

    public function index(Request $requete): View
    {
        $categorieId = $this->entierOuNul($requete->query('categorie'));
        $corpsMetierId = $this->entierOuNul($requete->query('metier'));

        $catalogue = $this->portail->catalogue($categorieId, $corpsMetierId);

        return view('portail::catalogue.index', [
            'catalogue' => $catalogue,
            'disponibilites' => $this->disponibilites($catalogue->items()),
            'categories' => $this->portail->categoriesDuCatalogue(),
            'corpsMetiers' => $this->portail->corpsMetiersDuCatalogue(),
            'categorieChoisie' => $categorieId,
            'corpsMetierChoisi' => $corpsMetierId,
        ]);
    }

    public function show(string $reference): View
    {
        $publication = $this->portail->ficheProduit($reference);

        if (! $publication) {
            throw new NotFoundHttpException();
        }

        $autres = $this->portail->autresProduitsDeLArtisan($publication);
        $similaires = $this->recommandation->produitsSimilaires($publication);

        // Les deux blocs se recoupent parfois : un artisan qui décline
        // une gamme apparaît dans « ses autres produits » et parmi les
        // similaires. On retire le doublon du second, jamais du premier :
        // « les autres produits de cet artisan » est une promesse
        // explicite faite au visiteur, « produits similaires » une
        // suggestion. Une suggestion cède le pas à une promesse.
        $similaires = $similaires->reject(
            fn (PublicationProduit $proche): bool => $autres->contains('id', $proche->getKey()),
        )->values();

        return view('portail::catalogue.produit', [
            'publication' => $publication,
            'disponibilite' => $this->portail->disponibilite($publication),
            'autres' => $autres,
            'similaires' => $similaires,
            'moteurSimilarite' => $similaires->isEmpty() ? null : $this->recommandation->nomDuMoteur(),
            'disponibilites' => $this->disponibilites(
                array_merge($autres->all(), $similaires->all()),
            ),
        ]);
    }

    protected function entierOuNul(mixed $valeur): ?int
    {
        return filled($valeur) && ctype_digit((string) $valeur) ? (int) $valeur : null;
    }

    /**
     * @param  array<int, PublicationProduit>  $publications
     * @return array<int, DisponibilitePortail>
     */
    protected function disponibilites(array $publications): array
    {
        $disponibilites = [];

        foreach ($publications as $publication) {
            $disponibilites[$publication->getKey()] = $this->portail->disponibilite($publication);
        }

        return $disponibilites;
    }
}
