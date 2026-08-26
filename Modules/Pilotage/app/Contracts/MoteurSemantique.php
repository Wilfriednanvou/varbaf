<?php

namespace Modules\Pilotage\Contracts;

use Illuminate\Support\Collection;
use Modules\Pilotage\Recommandation\CriteresDeVoisinage;
use Modules\Pilotage\Recommandation\ProduitVoisin;

/**
 * Ce qu'un moteur sémantique sait faire, quelle que soit sa technique.
 *
 * **L'intérêt de cette interface n'est pas l'abstraction pour
 * elle-même : c'est le repli.** Une branche dense — vecteurs denses,
 * pgvector, service d'embeddings — donne de meilleurs rapprochements
 * mais dépend d'un réseau et d'un service tiers. La branche lexicale ne
 * dépend de rien. Les deux répondent au même contrat, le résolveur
 * choisit la première disponible, et les appelants ne savent pas
 * laquelle a répondu — sauf l'interface, qui l'affiche, parce qu'un
 * lecteur doit pouvoir savoir sur quoi repose ce qu'on lui montre.
 *
 * Depuis le chantier 3, le contrat se lit en deux temps : savoir
 * chercher un passage à partir d'une question — c'est
 * `MoteurDeRecherche`, que le moteur témoin par mots-clés remplit
 * aussi — et savoir calculer un voisinage de produits, qui reste
 * propre aux moteurs sémantiques.
 */
interface MoteurSemantique extends MoteurDeRecherche
{
    /**
     * Les produits les plus proches d'un produit donné.
     *
     * @return Collection<int, ProduitVoisin> triés par score décroissant
     */
    public function voisins(int $produitId, CriteresDeVoisinage $criteres): Collection;
}
