<?php

namespace Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Commerce\Exceptions\VenteInvalideException;

/**
 * Ligne de vente, figée à l'enregistrement (RG-10).
 *
 * Aucune de ces valeurs n'est relue depuis le référentiel après coup :
 * un reçu réimprimé dans deux ans doit afficher le prix effectivement
 * payé, pas le tarif du jour.
 *
 * @property string $reference_produit
 * @property string $designation
 * @property int $prix_unitaire
 * @property int $quantite
 * @property int $montant_ligne
 */
class LigneVente extends Model
{
    protected $table = 'lignes_vente';

    protected $fillable = [
        'vente_id',
        'produit_id',
        'reference_produit',
        'designation',
        'prix_unitaire',
        'quantite',
        'montant_ligne',
    ];

    protected function casts(): array
    {
        return [
            'prix_unitaire' => 'integer',
            'montant_ligne' => 'integer',
            'quantite' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Une ligne de vente ne se retouche pas : elle fait partie du
        // figement. La correction passe par l'annulation de la vente.
        static::updating(function (self $ligne): void {
            throw VenteInvalideException::figee($ligne->vente?->numero ?? '');
        });

        static::deleting(function (self $ligne): void {
            // La suppression en cascade depuis la vente reste possible
            // — mais une vente enregistrée ne se supprime pas non plus.
            if ($ligne->vente?->exists) {
                throw VenteInvalideException::figee($ligne->vente->numero);
            }
        });
    }

    public function vente(): BelongsTo
    {
        return $this->belongsTo(Vente::class, 'vente_id');
    }

    /**
     * Référence technique vers le produit — pour les rapprochements et
     * le journal de stock. Ce n'est pas elle qui fait foi sur le reçu.
     */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }
}
