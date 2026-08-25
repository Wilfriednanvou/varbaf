<?php

namespace Modules\Portail\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Portail\Services\ServicePortail;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Annuaire public des artisans et fiche individuelle.
 *
 * Un artisan qui n'a pas donné son autorisation de publication n'est pas
 * « masqué » : il est absent. Sa fiche répond 404 même si l'on connaît
 * son matricule — l'autorisation lui appartient, et un site qui
 * répondrait « cet artisan existe mais refuse d'apparaître » aurait déjà
 * publié quelque chose de lui.
 */
class ArtisanController extends Controller
{
    public function __construct(protected ServicePortail $portail) {}

    public function index(Request $requete): View
    {
        $corpsMetierId = filled($requete->query('metier')) && ctype_digit((string) $requete->query('metier'))
            ? (int) $requete->query('metier')
            : null;

        return view('portail::artisans.index', [
            'artisans' => $this->portail->artisansPublies($corpsMetierId),
            'corpsMetiers' => $this->portail->corpsMetiersDuCatalogue(),
            'corpsMetierChoisi' => $corpsMetierId,
        ]);
    }

    public function show(string $matricule): View
    {
        $artisan = $this->portail->ficheArtisan($matricule);

        if (! $artisan) {
            throw new NotFoundHttpException();
        }

        $produits = $this->portail->produitsDeLArtisan($artisan);

        $disponibilites = [];

        foreach ($produits as $publication) {
            $disponibilites[$publication->getKey()] = $this->portail->disponibilite($publication);
        }

        return view('portail::artisans.fiche', [
            'artisan' => $artisan,
            'produits' => $produits,
            'disponibilites' => $disponibilites,
        ]);
    }
}
