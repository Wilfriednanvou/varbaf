<?php

namespace Modules\Commerce\Exceptions;

use RuntimeException;

/**
 * Levée dès qu'une écriture du journal de stock est modifiée ou
 * supprimée.
 *
 * Même patron que le journal d'audit, et surtout même patron que le
 * brouillard de caisse à venir (RG-05) : une écriture passée ne se
 * retouche pas, elle se contre-passe. Le mouvement d'origine reste en
 * place, un mouvement de sens inverse le référence, et le solde
 * redevient juste sans que l'historique ait menti entre-temps.
 *
 * La raison est la même que pour l'argent : le solde est un cumul.
 * Corriger une écriture ancienne ferait sauter tous les soldes
 * intermédiaires déjà imprimés sur des états, sans laisser de trace de
 * l'écart.
 */
class MouvementStockImmuableException extends RuntimeException
{
    public static function modification(): self
    {
        return new self(
            'Un mouvement de stock ne peut pas être modifié. '
            .'Corrigez-le par une contre-passation : elle crée un mouvement de sens inverse qui référence celui-ci.'
        );
    }

    public static function suppression(): self
    {
        return new self(
            'Un mouvement de stock ne peut pas être supprimé. '
            .'Le journal doit rester continu : corrigez par une contre-passation.'
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
