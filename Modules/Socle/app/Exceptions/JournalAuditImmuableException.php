<?php

namespace Modules\Socle\Exceptions;

use RuntimeException;

/**
 * Levée dès qu'une écriture du journal d'audit est modifiée ou
 * supprimée.
 *
 * L'exception est préférée à un simple `return false` dans le crochet
 * Eloquent : un `false` annule l'opération en silence, et un appelant
 * qui ne teste pas la valeur de retour croirait sa suppression
 * effectuée. Une piste d'audit qui se laisse effacer sans bruit ne
 * prouve rien.
 */
class JournalAuditImmuableException extends RuntimeException
{
    public static function modification(): self
    {
        return new self(
            'Une écriture du journal d\'audit ne peut pas être modifiée : le journal est en écriture seule.'
        );
    }

    public static function suppression(): self
    {
        return new self(
            'Une écriture du journal d\'audit ne peut pas être supprimée : le journal est en écriture seule.'
        );
    }
}
