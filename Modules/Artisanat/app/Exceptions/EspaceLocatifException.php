<?php

namespace Modules\Artisanat\Exceptions;

use RuntimeException;

/**
 * Refus opposés par le modèle EspaceLocatif.
 *
 * Le code d'un espace se génère à la création à partir de la boutique
 * qui l'abrite, puis il ne bouge plus. Il figure sur le contrat
 * d'attribution signé par l'artisan et sur les états de redevance : le
 * réécrire rendrait fausses des pièces déjà remises.
 */
class EspaceLocatifException extends RuntimeException
{
    public static function codeFige(string $code): self
    {
        return new self(
            "Le code {$code} est figé : il identifie l'espace sur les contrats d'attribution déjà signés. "
            .'Un espace mal découpé se retire du parc et se recrée, il ne se renumérote pas.'
        );
    }

    public static function boutiqueFigee(string $code): self
    {
        return new self(
            "L'espace {$code} ne peut pas changer de boutique : son code est dérivé de la boutique qui l'abrite, "
            .'et un espace ne se déplace pas d\'un local à un autre.'
        );
    }

    public static function occupeParUneAttribution(string $code): self
    {
        return new self(
            "L'espace {$code} porte des attributions : il ne se supprime pas. "
            .'Passez-le en indisponible pour le retirer du parc sans effacer son historique d\'occupation.'
        );
    }
}
