<?php

namespace Modules\Tresorerie\Exceptions;

use RuntimeException;

/**
 * Levée quand une opération viole les règles de l'arrêté de caisse
 * journalier (RG-25 à RG-27).
 */
class ArreteCaisseException extends RuntimeException
{
    public static function ecartNonJustifie(): self
    {
        return new self(
            'Un écart non nul exige un commentaire de justification (RG-26).'
        );
    }

    public static function dejaArrete(string $date): self
    {
        return new self(
            "La caisse a déjà fait l'objet d'un arrêté pour le {$date}. "
            .'Un seul arrêté par caisse et par jour (RG-25).'
        );
    }

    public static function immuable(): self
    {
        return new self(
            "Un arrêté de caisse validé ne se modifie ni ne se supprime : c'est un constat, pas une saisie."
        );
    }
}
