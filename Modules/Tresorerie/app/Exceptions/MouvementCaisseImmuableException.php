<?php

namespace Modules\Tresorerie\Exceptions;

use RuntimeException;

/**
 * Levée dès qu'une écriture du brouillard de caisse est modifiée ou
 * supprimée.
 *
 * Même patron que le journal de stock (RG-05) : une écriture passée
 * ne se retouche pas, elle se contre-passe. Le mouvement d'origine
 * reste en place, un mouvement de sens inverse le référence, et le
 * solde redevient juste sans que l'historique ait menti entre-temps.
 */
class MouvementCaisseImmuableException extends RuntimeException
{
    public static function modification(): self
    {
        return new self(
            'Un mouvement de caisse ne peut pas être modifié. '
            .'Corrigez-le par une contre-passation : elle crée un mouvement de sens inverse qui référence celui-ci.'
        );
    }

    public static function suppression(): self
    {
        return new self(
            'Un mouvement de caisse ne peut pas être supprimé. '
            .'Le brouillard doit rester continu : corrigez par une contre-passation.'
        );
    }

    public static function dejaContrepasse(int $numeroOrdre): self
    {
        return new self(
            "Le mouvement n° {$numeroOrdre} a déjà été contre-passé. "
            .'Une seconde contre-passation rétablirait la situation initiale sans le dire.'
        );
    }

    public static function contrepassationDeContrepassation(): self
    {
        return new self(
            'Une contre-passation ne se contre-passe pas : enregistrez le mouvement qui rétablit la réalité, '
            .'avec son motif propre.'
        );
    }
}
