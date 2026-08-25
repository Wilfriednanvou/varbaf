<?php

namespace Modules\Artisanat\Exceptions;

use RuntimeException;

/**
 * Levée lorsqu'une attribution active viole une des conditions
 * d'attribution : artisan désactivé, exercice clôturé, espace locatif
 * indisponible, redevance hors barème.
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
            "L'artisan {$identiteArtisan} est désactivé : aucun espace ne peut lui être attribué."
        );
    }

    public static function exerciceCloture(string $libelleExercice): self
    {
        return new self(
            "L'exercice {$libelleExercice} est clôturé : plus aucune attribution ne peut y être rattachée."
        );
    }

    public static function espaceIndisponible(string $codeEspace): self
    {
        return new self(
            "L'espace {$codeEspace} est indisponible : il ne peut pas être attribué tant que la coordination ne l'a pas remis au parc."
        );
    }

    /**
     * La redevance est un montant convenu, pas un calcul : rien ne la
     * corrigerait après coup puisqu'elle est figée sur le contrat. Le
     * barème est donc la seule barrière contre la faute de frappe.
     */
    public static function redevanceHorsBareme(int $montant, int $minimum, int $maximum): self
    {
        $montantLisible = number_format($montant, 0, ',', ' ');
        $minimumLisible = number_format($minimum, 0, ',', ' ');
        $maximumLisible = number_format($maximum, 0, ',', ' ');

        return new self(
            "La redevance convenue de {$montantLisible} FCFA sort du barème du village : "
            ."elle doit se situer entre {$minimumLisible} et {$maximumLisible} FCFA par mois."
        );
    }
}
