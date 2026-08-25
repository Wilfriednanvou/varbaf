<?php

namespace Modules\Artisanat\Exceptions;

use RuntimeException;

/**
 * Levée lorsqu'une attribution recouvrirait une attribution active déjà
 * en place sur le même espace locatif.
 *
 * La règle regarde l'espace et non la boutique : deux artisans installés
 * dans deux espaces d'un même local sont la situation ordinaire du
 * village, et c'est le partage d'un même espace qui constitue la faute.
 *
 * Exception de domaine et non exception de validation : elle doit
 * remonter aussi bien depuis un écran Filament que depuis un seeder, une
 * commande artisan ou tinker. La ressource Filament double le contrôle
 * par une règle de formulaire, afin que l'utilisateur voie un message
 * sous le champ plutôt qu'une page d'erreur.
 */
class AttributionChevauchanteException extends RuntimeException
{
    public static function pour(string $codeEspace, string $periode): self
    {
        return new self(
            "L'espace {$codeEspace} porte déjà une attribution active sur la période {$periode}."
        );
    }
}
