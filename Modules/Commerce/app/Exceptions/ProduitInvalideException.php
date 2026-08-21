<?php

namespace Modules\Commerce\Exceptions;

use RuntimeException;

/**
 * Regroupe les refus opposés par le modèle Produit : transition de
 * statut interdite, et tentative de réécriture de la référence.
 */
class ProduitInvalideException extends RuntimeException
{
    public static function transitionInterdite(string $depuis, string $vers): self
    {
        return new self(
            "Un produit ne peut pas passer de « {$depuis} » à « {$vers} »."
        );
    }

    /**
     * La référence est un identifiant, pas une adresse.
     *
     * Elle est apposée sur l'étiquette physique collée sur l'objet
     * déposé, et reprise sur le reçu de vente comme sur l'état de
     * reversement (RG-09). Si elle suivait les déplacements du produit
     * d'une boutique à l'autre, toutes les étiquettes déjà imprimées
     * deviendraient fausses, et les pièces justificatives passées
     * cesseraient d'être rapprochables.
     */
    public static function referenceFigee(string $reference): self
    {
        return new self(
            "La référence {$reference} ne peut pas être modifiée : elle identifie le produit "
            .'et figure sur son étiquette physique, même s\'il change de boutique.'
        );
    }
}
