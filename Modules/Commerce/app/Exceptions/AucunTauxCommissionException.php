<?php

namespace Modules\Commerce\Exceptions;

use RuntimeException;

/**
 * Levée quand aucun taux de commission n'est en vigueur à la date
 * demandée.
 *
 * Le réflexe serait de renvoyer zéro. Ce serait la pire des réponses :
 * une commission de 0 % ne lève aucune alerte à l'écran, la vente
 * s'enregistre normalement, et le village reverse à l'artisan
 * l'intégralité d'une recette sur laquelle il aurait dû prélever sa
 * part. L'erreur ne se découvrirait qu'au rapprochement comptable,
 * des semaines plus tard, sur des ventes déjà reversées et donc
 * irrattrapables.
 *
 * Une vente qu'on ne sait pas commissionner ne doit pas s'enregistrer.
 */
class AucunTauxCommissionException extends RuntimeException
{
    public static function aLaDate(string $date): self
    {
        return new self(
            "Aucun taux de commission n'est en vigueur au {$date}. "
            ."Enregistrez le taux applicable, avec sa date d'effet et sa référence de décision, "
            .'avant de saisir des ventes à cette date.'
        );
    }
}
