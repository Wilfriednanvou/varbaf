<?php

namespace Modules\Artisanat\Exceptions;

use RuntimeException;

/**
 * Levée lorsqu'une attribution recouvrirait une attribution active
 * déjà en place sur la même boutique.
 *
 * Exception de domaine et non exception de validation : elle doit
 * remonter aussi bien depuis un écran Filament que depuis un seeder,
 * une commande artisan ou tinker. La ressource Filament double le
 * contrôle par une règle de formulaire, afin que l'utilisateur voie un
 * message sous le champ plutôt qu'une page d'erreur.
 */
class AttributionChevauchanteException extends RuntimeException
{
    public static function pour(string $numeroBoutique, string $periode): self
    {
        return new self(
            "La boutique {$numeroBoutique} porte déjà une attribution active sur la période {$periode}."
        );
    }
}
