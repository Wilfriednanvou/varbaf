<?php

namespace Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Commerce\Exceptions\DepotFigeException;

/**
 * Article confié au village dans le cadre d'un dépôt.
 *
 * La référence et la désignation sont recopiées depuis le produit au
 * moment de la validation, puis figées : la décharge signée par
 * l'artisan doit rester relisable même si le produit est renommé
 * ensuite. Même principe de figement que la ligne de vente (RG-10).
 *
 * @property string $reference_produit
 * @property string $designation
 * @property int $quantite
 */
class LigneDepot extends Model
{
    protected $table = 'lignes_depot';

    protected $fillable = [
        'depot_id',
        'produit_id',
        'reference_produit',
        'designation',
        'quantite',
    ];

    protected function casts(): array
    {
        return [
            'quantite' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Une ligne recopie l'état du produit à l'instant du dépôt.
        // Tant que le dépôt est en brouillon, elle se rafraîchit ;
        // après validation, plus rien ne bouge.
        static::saving(function (self $ligne): void {
            if ($ligne->depot?->estValide()) {
                throw DepotFigeException::modification($ligne->depot->numero);
            }

            $produit = $ligne->produit;

            if ($produit) {
                $ligne->reference_produit = $produit->reference;
                $ligne->designation = $produit->designation;
            }
        });

        static::deleting(function (self $ligne): void {
            if ($ligne->depot?->estValide()) {
                throw DepotFigeException::modification($ligne->depot->numero);
            }
        });
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class, 'depot_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }
}
