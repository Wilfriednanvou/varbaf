<?php

namespace Modules\Portail\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Modules\Portail\Http\Requests\DemandeContactRequest;
use Modules\Portail\Services\ServicePortail;

/**
 * Formulaire de contact — la seule écriture du portail.
 *
 * Le visiteur n'obtient jamais de retour sur ce que le village fait de
 * son message : la page de confirmation dit qu'il est parti, rien de
 * plus. Le suivi vit dans le panneau, où il appartient.
 */
class ContactController extends Controller
{
    public function __construct(protected ServicePortail $portail) {}

    public function create(): View
    {
        return view('portail::contact', [
            'introduction' => $this->portail->contenu('contact.introduction'),
        ]);
    }

    public function store(DemandeContactRequest $requete): RedirectResponse
    {
        $this->portail->enregistrerDemandeContact(
            $requete->validated(),
            $requete->ip(),
        );

        return redirect()
            ->route('portail.contact')
            ->with('succes', 'Votre message a bien été transmis au Village Artisanal. Nous vous répondrons dès que possible.');
    }
}
