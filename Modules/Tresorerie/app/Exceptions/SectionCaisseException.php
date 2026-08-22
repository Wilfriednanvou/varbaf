<?php

namespace Modules\Tresorerie\Exceptions;

use RuntimeException;

/**
 * Levée quand une opération viole les règles de gestion d'une
 * section de caisse : ouverture, clôture, ou tentative d'écriture
 * hors section.
 */
class SectionCaisseException extends RuntimeException
{
    public static function aucuneSectionOuverte(): self
    {
        return new self(
            'Aucune section de caisse n\'est ouverte. '
            .'Ouvrez une section avant d\'enregistrer une opération (RG-03).'
        );
    }

    public static function dejaUneOuverte(string $libelle): self
    {
        return new self(
            "La section « {$libelle} » est déjà ouverte sur cette caisse. "
            .'Clôturez-la avant d\'en ouvrir une autre (RG-01).'
        );
    }

    public static function dejaCloturee(string $libelle): self
    {
        return new self(
            "La section « {$libelle} » est déjà clôturée. "
            .'La clôture est irréversible (RG-07).'
        );
    }

    public static function caisseInactive(string $code): self
    {
        return new self(
            "La caisse « {$code} » est inactive. "
            .'Aucune section ne peut y être ouverte.'
        );
    }

    public static function sectionFigee(string $libelle): self
    {
        return new self(
            "La section « {$libelle} » est clôturée et ne peut plus être modifiée."
        );
    }
}
