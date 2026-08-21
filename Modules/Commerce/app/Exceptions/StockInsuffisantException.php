<?php

namespace Modules\Commerce\Exceptions;

use RuntimeException;

/**
 * Levée quand une sortie dépasserait le solde en stock.
 *
 * Un artisan ne peut reprendre que ce qu'il a effectivement laissé en
 * dépôt, et le village ne peut vendre que ce qu'il détient. Un stock
 * négatif n'a aucun sens physique : il signifierait que le village a
 * fait sortir des objets qu'il n'avait pas reçus, et rendrait
 * incalculable la part due à l'artisan.
 *
 * Le contrôle appartient au service d'écriture au journal, jamais à
 * l'écran : il doit valoir pour la vente, le retrait, la perte et
 * toute reprise de données par commande.
 */
class StockInsuffisantException extends RuntimeException
{
    public static function pour(string $produit, int $demande, int $disponible): self
    {
        return new self(
            "Stock insuffisant pour {$produit} : {$demande} demandé(s), {$disponible} en dépôt."
        );
    }

    public static function quantiteInvalide(int $quantite): self
    {
        return new self(
            "La quantité d'un mouvement de stock doit être strictement positive ; {$quantite} a été reçu. "
            .'Le sens du mouvement porte la direction, jamais le signe de la quantité.'
        );
    }
}
