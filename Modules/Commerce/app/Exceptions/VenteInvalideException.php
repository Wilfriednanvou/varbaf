<?php

namespace Modules\Commerce\Exceptions;

use RuntimeException;

/**
 * Refus opposés par le service de vente.
 *
 * Toutes ces conditions sont vérifiées **avant** la moindre écriture :
 * une vente qu'on ne sait pas enregistrer complètement ne doit pas
 * l'être partiellement.
 */
class VenteInvalideException extends RuntimeException
{
    public static function sansLigne(): self
    {
        return new self('Une vente doit porter au moins une ligne.');
    }

    /**
     * RG-08 : un achat couvrant plusieurs boutiques donne autant de
     * ventes distinctes.
     */
    public static function plusieursBoutiques(): self
    {
        return new self(
            'Une vente ne porte que sur une seule boutique. '
            .'Un achat couvrant plusieurs boutiques donne lieu à autant de ventes distinctes.'
        );
    }

    public static function produitNonVendable(string $produit): self
    {
        return new self(
            "Le produit {$produit} n'est pas vendable : il doit être actif et validé par la section Production."
        );
    }

    /**
     * RG-14 : la vente est réalisée par un agent du village, dont
     * l'identifiant est enregistré sur la vente. L'agent n'est pas
     * choisi dans une liste — il est celui du compte connecté.
     */
    public static function vendeurInconnu(): self
    {
        return new self(
            'Le compte connecté n\'est rattaché à aucun agent du village : la vente ne peut pas être imputée. '
            .'Rattachez le compte à un agent depuis l\'écran des utilisateurs.'
        );
    }

    public static function exerciceIndisponible(): self
    {
        return new self(
            'Aucun exercice n\'est en cours : aucune vente ne peut y être rattachée.'
        );
    }

    public static function dejaAnnulee(string $numero): self
    {
        return new self("La vente {$numero} est déjà annulée.");
    }

    public static function figee(string $numero): self
    {
        return new self(
            "La vente {$numero} est enregistrée : ses montants et ses lignes sont figés (RG-10). "
            .'Une erreur se corrige par annulation, qui contre-passe le stock et la caisse.'
        );
    }
}
