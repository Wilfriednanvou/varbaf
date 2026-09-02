<?php

namespace Modules\Portail\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Modules\Portail\Services\ServicePortail;

/**
 * Accueil et présentation du village.
 *
 * Le contrôleur ne connaît aucun modèle : il demande au service et
 * passe le résultat à la vue. C'est ce qui garantit que les trois
 * conditions de publication sont appliquées partout de la même façon —
 * une requête écrite à la main dans un contrôleur en oublierait une tôt
 * ou tard.
 */
class AccueilController extends Controller
{
    public function __construct(protected ServicePortail $portail) {}

    public function index(): View
    {
        $catalogue = $this->portail->catalogue(parPage: 6);

        return view('portail::accueil', [
            'introduction' => $this->portail->contenu('accueil.introduction'),
            'vedettes' => $this->portail->artisansVedettes(),
            'nouveautes' => $catalogue,
            'disponibilites' => $this->disponibilites($catalogue->items()),
            'reperes' => $this->portail->reperes(),
            'corpsMetiers' => $this->portail->corpsMetiersDuCatalogue(),
        ]);
    }

    /**
     * Les locaux de vente, un par vignette.
     *
     * La page ne dit pas qui occupe quel local : l'attribution est une
     * donnée de gestion, elle change, et un visiteur qui viendrait
     * chercher un artisan devant une porte reattribuee la veille aurait
     * ete trompe par le site. Elle montre les lieux ; les artisans ont
     * leur propre annuaire.
     */
    public function boutiques(): View
    {
        return view('portail::boutiques', [
            'locaux' => $this->portail->locauxDeVente(),
            'visuels' => config('portail.visuels.boutiques', []),
        ]);
    }

    public function village(): View
    {
        return view('portail::village', [
            'sections' => $this->portail->contenus('village.'),
        ]);
    }

    /**
     * Disponibilités indexées par publication.
     *
     * Calculées ici plutôt que dans la vue : une vue qui appellerait le
     * service pour chaque vignette rouvrirait la porte que le service
     * ferme — celle du stock consulté à l'affichage.
     *
     * @param  array<int, \Modules\Portail\Models\PublicationProduit>  $publications
     * @return array<int, \Modules\Portail\Enums\DisponibilitePortail>
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
