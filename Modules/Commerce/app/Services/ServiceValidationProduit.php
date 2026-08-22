<?php

namespace Modules\Commerce\Services;

use Illuminate\Support\Facades\Auth;
use Modules\Commerce\Enums\StatutValidationProduit;
use Modules\Commerce\Exceptions\ProduitInvalideException;
use Modules\Commerce\Models\Produit;

/**
 * Point d'entrée unique des transitions du statut de validation d'un
 * produit (règle 14).
 *
 * **Habilitation vérifiée ici, pas seulement à l'écran.** Le `->visible()`
 * de `ProduitResource` masque les boutons pour un compte non habilité,
 * mais rien n'empêchait jusqu'ici un appel direct à
 * `Produit::changerStatut()` — depuis une commande, un tinker, ou un
 * futur écran qui oublierait la vérification. Le contrôle vit
 * maintenant ici, seul endroit que toute voie d'écriture doit
 * emprunter.
 *
 * **Traçabilité du validateur réel.** `valide_par` et `valide_le` ne
 * sont jamais saisis : ils constatent le compte connecté au moment où
 * la transition vers VALIDE a effectivement lieu, y compris quand ce
 * compte est celui du coordonnateur suppléant le chef de section
 * Production. Le journal d'audit garde par ailleurs la trace de
 * l'action, mais seule cette colonne permet de savoir, en consultant
 * le produit lui-même, qui l'a validé.
 */
class ServiceValidationProduit
{
    public function valider(Produit $produit): Produit
    {
        $this->verifierHabilitation('valider_produit', 'valider');

        $produit->statut_validation = StatutValidationProduit::VALIDE;
        $produit->valide_par = Auth::id();
        $produit->valide_le = now();
        $produit->save();

        return $produit;
    }

    public function exposer(Produit $produit): Produit
    {
        $this->verifierHabilitation('exposer_produit', 'exposer');

        $produit->changerStatut(StatutValidationProduit::EXPOSE);

        return $produit;
    }

    public function retirer(Produit $produit): Produit
    {
        $this->verifierHabilitation('retirer_produit', 'retirer');

        $produit->changerStatut(StatutValidationProduit::RETIRE);

        return $produit;
    }

    protected function verifierHabilitation(string $permission, string $action): void
    {
        if (! Auth::user()?->can($permission)) {
            throw ProduitInvalideException::actionNonAutorisee($action);
        }
    }
}
