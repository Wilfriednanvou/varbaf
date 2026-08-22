<?php

namespace Modules\Tresorerie\Exceptions;

use Illuminate\Support\Carbon;
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

    /**
     * RG-07 : une section ne se clôture pas tant qu'une de ses journées
     * n'a pas été arrêtée.
     *
     * Le message nomme les journées en cause — jusqu'à cinq — parce
     * qu'un refus qui n'indique pas quoi corriger oblige le caissier à
     * chercher lui-même, et qu'il cherchera mal.
     *
     * @param  array<int, string>  $journees
     */
    public static function journeesNonArretees(string $libelle, array $journees): self
    {
        $apercu = collect($journees)
            ->take(5)
            ->map(fn (string $jour) => Carbon::parse($jour)->format('d/m/Y'))
            ->implode(', ');

        $reste = count($journees) > 5
            ? ' et '.(count($journees) - 5).' autre(s)'
            : '';

        return new self(
            "La section « {$libelle} » ne peut pas être clôturée : "
            ."certaines journées n'ont pas été arrêtées ({$apercu}{$reste}). "
            .'Arrêtez-les avant de clôturer (RG-07).'
        );
    }
}
