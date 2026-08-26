<?php

namespace Modules\Pilotage\Contracts;

use Illuminate\Support\Collection;
use Modules\Pilotage\Recherche\SegmentTrouve;

/**
 * Retrouver des passages du corpus à partir d'une question.
 *
 * **Séparée de `MoteurSemantique` pour une raison précise :** le moteur
 * témoin par mots-clés sait chercher, mais ne sait pas — et n'a pas à
 * savoir — calculer un voisinage de produits. Lui imposer `voisins()`
 * l'obligerait à porter une implémentation vide dont personne ne veut,
 * et brouillerait la comparaison que l'hypothèse H3 cherche à établir
 * entre pondération TF-IDF et correspondance simple.
 *
 * Deux implémentations aujourd'hui, comparables terme à terme parce
 * qu'elles partagent la même tokenisation : seule la pondération les
 * distingue.
 */
interface MoteurDeRecherche
{
    /**
     * Le nom affiché à côté d'une réponse.
     */
    public function nom(): string;

    /**
     * Clé de configuration du moteur — celle qu'emploient
     * `pilotage.moteur.ordre` et la commande d'évaluation.
     */
    public function cle(): string;

    public function estDisponible(): bool;

    /**
     * Les passages du corpus les plus proches d'une question.
     *
     * @return Collection<int, SegmentTrouve> triés par similarité décroissante
     */
    public function rechercher(string $question, int $limite, ?float $seuil = null): Collection;
}
