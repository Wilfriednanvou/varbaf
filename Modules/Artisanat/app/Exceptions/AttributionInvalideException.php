<?php

namespace Modules\Artisanat\Exceptions;

use RuntimeException;

/**
 * Levée lorsqu'une attribution active viole une des conditions
 * d'attribution : artisan désactivé, exercice clôturé, boutique
 * indisponible.
 *
 * Exception de domaine, au même titre que
 * AttributionChevauchanteException : elle remonte aussi bien depuis un
 * écran Filament que depuis un seeder, une commande artisan ou tinker.
 * C'est précisément l'intérêt de la placer dans le modèle plutôt que
 * dans la ressource — une règle qui ne vit que dans un formulaire
 * n'existe pas pour le reste du système.
 */
class AttributionInvalideException extends RuntimeException
{
    public static function artisanInactif(string $identiteArtisan): self
    {
        return new self(
            "L'artisan {$identiteArtisan} est désactivé : aucune boutique ne peut lui être attribuée."
        );
    }

    public static function exerciceCloture(string $libelleExercice): self
    {
        return new self(
            "L'exercice {$libelleExercice} est clôturé : plus aucune attribution ne peut y être rattachée."
        );
    }

    public static function boutiqueIndisponible(string $numeroBoutique): self
    {
        return new self(
            "La boutique {$numeroBoutique} est indisponible : elle ne peut pas être attribuée tant que la coordination ne l'a pas remise au parc."
        );
    }
}
