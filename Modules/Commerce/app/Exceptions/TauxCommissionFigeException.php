<?php

namespace Modules\Commerce\Exceptions;

use RuntimeException;

/**
 * Levée dès qu'on tente de modifier ou de supprimer un taux dont la
 * date d'effet est passée.
 *
 * Un taux entré par erreur se corrige tant qu'il n'est pas entré en
 * vigueur. Passé sa date d'effet, il a pu servir de base à des ventes :
 * le modifier réécrirait rétroactivement des commissions déjà
 * calculées, déjà encaissées, parfois déjà reversées. La correction
 * passe alors par un nouveau taux portant une nouvelle date d'effet —
 * jamais par la retouche de l'ancien.
 */
class TauxCommissionFigeException extends RuntimeException
{
    public static function modification(string $libelle): self
    {
        return new self(
            "Le taux {$libelle} est entré en vigueur : il ne peut plus être modifié. "
            .'Enregistrez un nouveau taux avec sa propre date d\'effet.'
        );
    }

    public static function suppression(string $libelle): self
    {
        return new self(
            "Le taux {$libelle} est entré en vigueur : il ne peut plus être supprimé. "
            .'L\'historique des taux est ce qui permet de rejouer le calcul d\'une commission ancienne.'
        );
    }
}
