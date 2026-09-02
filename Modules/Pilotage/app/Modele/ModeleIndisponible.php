<?php

namespace Modules\Pilotage\Modele;

use Illuminate\Support\Collection;
use Modules\Pilotage\Contracts\ModeleDeLangage;

/**
 * Le modèle qui n'est pas là — et qui se comporte comme un modèle.
 *
 * **Pourquoi un objet nul plutôt qu'un `null` dans le conteneur.** Sans
 * lui, chaque appelant porterait un `if ($modele !== null)`, et il
 * suffirait qu'un seul oublie le test pour qu'une installation sans clé
 * d'API tombe en erreur au lieu de répondre. Avec lui, le chemin dégradé
 * emprunte **exactement le même code** que le chemin nominal : on demande
 * une rédaction, on n'en reçoit pas, on compose mécaniquement. C'est la
 * seule manière d'être sûr que le repli fonctionne — il est parcouru à
 * chaque test de la suite, puisque aucun modèle ne tourne pendant les
 * tests.
 *
 * C'est le même raisonnement que pour la branche dense : un système
 * dégradé doit s'annoncer et continuer, jamais s'interrompre.
 */
class ModeleIndisponible implements ModeleDeLangage
{
    public function nom(): string
    {
        return 'Aucun modèle de rédaction';
    }

    public function estDisponible(): bool
    {
        return false;
    }

    public function redigerDepuisExtraits(string $question, Collection $extraits): ?string
    {
        return null;
    }

    public function accueillir(string $saisie): ?string
    {
        return null;
    }

    /**
     * Sans modèle, une question de suite reste ce qu'elle est.
     *
     * L'appelant emploie alors la saisie brute — « et en juillet ? » sera
     * mal comprise, et le refus le dira. C'est le comportement correct :
     * un système dégradé répond moins bien, il ne répond pas à côté.
     */
    public function reformuler(string $saisie, array $historique): ?string
    {
        return null;
    }
}
