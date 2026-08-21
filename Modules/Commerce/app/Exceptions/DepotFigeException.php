<?php

namespace Modules\Commerce\Exceptions;

use RuntimeException;

/**
 * Levée dès qu'on touche à un dépôt déjà validé, ou à ses lignes.
 *
 * Un dépôt validé vaut décharge : l'artisan a signé, le village
 * reconnaît détenir les biens listés, et les mouvements de stock
 * correspondants existent. Modifier la liste après signature
 * reviendrait à réécrire un document contradictoire — l'exemplaire
 * papier détenu par l'artisan ne correspondrait plus à celui du
 * village, et aucune des deux parties ne pourrait prouver laquelle a
 * raison.
 */
class DepotFigeException extends RuntimeException
{
    public static function modification(string $numero): self
    {
        return new self(
            "Le dépôt {$numero} est validé : il vaut décharge signée et ne peut plus être modifié. "
            .'Un écart constaté se corrige par un retrait ou une contre-passation du mouvement de stock.'
        );
    }

    public static function suppression(string $numero): self
    {
        return new self(
            "Le dépôt {$numero} est validé : il ne peut plus être supprimé. "
            .'C\'est la pièce qui justifie la détention des biens par le village.'
        );
    }

    public static function sansLigne(string $numero): self
    {
        return new self(
            "Le dépôt {$numero} ne contient aucun article : il n'y a rien à décharger."
        );
    }

    public static function dejaValide(string $numero): self
    {
        return new self("Le dépôt {$numero} est déjà validé.");
    }
}
