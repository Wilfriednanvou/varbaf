<?php

namespace Modules\Tresorerie\Exceptions;

use RuntimeException;

/**
 * Levée quand une opération viole les règles des campagnes de
 * reversement (RG-16 à RG-21).
 */
class CampagneReversementException extends RuntimeException
{
    public static function campagneFigee(string $periode): self
    {
        return new self(
            "La campagne de {$periode} est validée : elle ne se modifie ni ne se supprime. "
            .'Les corrections passent par la campagne suivante (RG-21).'
        );
    }

    public static function reversementFige(): self
    {
        return new self(
            "Ce reversement appartient à une campagne validée : il est définitif. "
            .'Une annulation ultérieure se reprend sur la campagne suivante (RG-20).'
        );
    }

    public static function ligneFigee(): self
    {
        return new self(
            "Une ligne de reversement se recalcule en repréparant la campagne, elle ne se retouche pas."
        );
    }

    public static function dejaValidee(string $periode): self
    {
        return new self(
            "La campagne de {$periode} est déjà validée : ses ventes sont rattachées définitivement (RG-21). "
            .'Les ventes retenues trop tard partiront sur la campagne suivante.'
        );
    }

    public static function aucunReversementAValider(string $periode): self
    {
        return new self(
            "La campagne de {$periode} n'a rien à reverser. Préparez-la d'abord : "
            .'sans reversement calculé, la valider ne rattacherait aucune vente.'
        );
    }
}
