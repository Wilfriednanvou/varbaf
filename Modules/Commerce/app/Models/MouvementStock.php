<?php

namespace Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Commerce\Enums\SensMouvementStock;
use Modules\Commerce\Enums\TypeMouvementStock;
use Modules\Commerce\Exceptions\MouvementStockImmuableException;
use Modules\Socle\Models\Utilisateur;

/**
 * Écriture du journal de stock.
 *
 * **Immuable dès l'enregistrement.** Les crochets « updating » et
 * « deleting » lèvent une exception : la correction passe par
 * `ServiceMouvementStock::contrepasser()`, qui crée un mouvement de
 * sens inverse référençant celui-ci. Le mouvement d'origine reste en
 * place.
 *
 * Le modèle ne s'écrit jamais directement depuis un autre module :
 * `ServiceMouvementStock` est le point d'entrée unique (règle 3), seul
 * capable de garantir la numérotation sans rupture et l'impossibilité
 * d'un stock négatif.
 *
 * @property int $numero_ordre
 * @property SensMouvementStock $sens
 * @property TypeMouvementStock $type
 * @property int $quantite
 * @property int $solde_apres
 */
class MouvementStock extends Model
{
    protected $table = 'mouvements_stock';

    public const UPDATED_AT = null;

    protected $fillable = [
        'numero_ordre',
        'date_mouvement',
        'produit_id',
        'sens',
        'type',
        'quantite',
        'solde_apres',
        'motif',
        'origine_type',
        'origine_id',
        'mouvement_contrepasse_id',
        'saisi_par',
    ];

    protected function casts(): array
    {
        return [
            'date_mouvement' => 'datetime',
            'sens' => SensMouvementStock::class,
            'type' => TypeMouvementStock::class,
            'quantite' => 'integer',
            'solde_apres' => 'integer',
            'numero_ordre' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $mouvement): void {
            throw MouvementStockImmuableException::modification();
        });

        static::deleting(function (self $mouvement): void {
            throw MouvementStockImmuableException::suppression();
        });
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'saisi_par');
    }

    /**
     * Le mouvement que celui-ci annule, s'il est une contre-passation.
     */
    public function mouvementContrepasse(): BelongsTo
    {
        return $this->belongsTo(self::class, 'mouvement_contrepasse_id');
    }

    /**
     * La contre-passation qui annule ce mouvement, s'il y en a une.
     */
    public function contrepassation(): HasOne
    {
        return $this->hasOne(self::class, 'mouvement_contrepasse_id');
    }

    public function estUneContrepassation(): bool
    {
        return $this->mouvement_contrepasse_id !== null;
    }

    public function estContrepasse(): bool
    {
        return $this->contrepassation()->exists();
    }

    public function scopeDuProduit(Builder $requete, int $produitId): Builder
    {
        return $requete->where('produit_id', $produitId)->orderBy('numero_ordre');
    }

    /**
     * Quantité signée, pour les cumuls et les états.
     */
    public function quantiteSignee(): int
    {
        return $this->sens->signe() * $this->quantite;
    }

    public function libelleOrigine(): ?string
    {
        if (blank($this->origine_type)) {
            return null;
        }

        return "{$this->origine_type} #{$this->origine_id}";
    }
}
