<?php

namespace Modules\Socle\Exceptions;

use Modules\Socle\Models\Exercice;
use RuntimeException;

/**
 * La clôture d'un exercice a été refusée.
 *
 * **Une exception, et non un `false`.** Le projet a déjà tranché ce
 * point pour le journal d'audit : un mécanisme de preuve qui se laisse
 * contourner poliment n'est pas un mécanisme de preuve. Un `false`
 * silencieux se serait fait ignorer par le premier appelant pressé, et
 * l'exercice serait resté ouvert sans que personne ne sache pourquoi.
 *
 * Un exercice **déjà** clôturé, lui, ne lève rien : redemander une
 * clôture faite n'est pas une faute, c'est un doublon sans effet.
 */
class ExerciceNonCloturableException extends RuntimeException
{
    /**
     * @param  array<int, string>  $obstacles
     */
    public function __construct(
        string $message,
        public readonly array $obstacles = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<int, string>  $obstacles
     */
    public static function pour(Exercice $exercice, array $obstacles): self
    {
        return new self(
            sprintf(
                'L\'exercice « %s » ne peut pas être clôturé : %s.',
                $exercice->libelle,
                implode(', ', $obstacles),
            ),
            $obstacles,
        );
    }
}
