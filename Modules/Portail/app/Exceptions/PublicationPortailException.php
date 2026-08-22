<?php

namespace Modules\Portail\Exceptions;

use RuntimeException;

/**
 * Levée quand une mise en ligne violerait les règles de publication du
 * portail.
 */
class PublicationPortailException extends RuntimeException
{
    public static function produitNonExpose(string $produit): self
    {
        return new self(
            "Le produit « {$produit} » n'est pas exposé : il ne peut pas être publié sur le portail. "
            .'La mise en vitrine relève de la section Promotion et Commercialisation.'
        );
    }

    public static function artisanSansAutorisation(string $artisan): self
    {
        return new self(
            "L'artisan « {$artisan} » n'a pas donné son autorisation de publication : "
            .'ni lui ni ses produits ne peuvent apparaître sur le portail.'
        );
    }

    public static function demandeContactFigee(): self
    {
        return new self(
            "Le contenu d'une demande de contact ne se modifie pas : seul son suivi est modifiable."
        );
    }
}
