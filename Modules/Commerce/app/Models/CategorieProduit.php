<?php

namespace Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Famille de produits : bronze, poterie, tissage, mobilier en bambou.
 *
 * Auto-référencée pour permettre une nomenclature à deux niveaux —
 * une famille et ses sous-familles — sans imposer de profondeur.
 *
 * Le référentiel n'est pas rattaché à un village : comme les corps de
 * métier, la nomenclature des produits artisanaux ne varie pas d'un
 * village à l'autre.
 *
 * @property int $id
 * @property string $code
 * @property string $libelle
 * @property int|null $categorie_parent_id
 */
class CategorieProduit extends Model
{
    protected $table = 'categories_produits';

    protected $fillable = [
        'code',
        'libelle',
        'categorie_parent_id',
    ];

    protected static function booted(): void
    {
        static::saving(fn (self $categorie) => $categorie->garantirAbsenceDeCycle());
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'categorie_parent_id');
    }

    public function enfants(): HasMany
    {
        return $this->hasMany(self::class, 'categorie_parent_id');
    }

    public function produits(): HasMany
    {
        return $this->hasMany(Produit::class, 'categorie_id');
    }

    public function scopeRacines(Builder $requete): Builder
    {
        return $requete->whereNull('categorie_parent_id');
    }

    /**
     * Interdit qu'une catégorie devienne sa propre ancêtre.
     *
     * Une hiérarchie circulaire ne se voit pas à la saisie : elle se
     * découvre le jour où un parcours d'arbre — un menu, un état par
     * famille — tourne indéfiniment. Le contrôle remonte la chaîne des
     * parents et refuse dès qu'il revient sur l'enregistrement courant.
     */
    public function garantirAbsenceDeCycle(): void
    {
        if (blank($this->categorie_parent_id)) {
            return;
        }

        if ($this->exists && (int) $this->categorie_parent_id === (int) $this->getKey()) {
            throw new RuntimeException('Une catégorie ne peut pas être sa propre catégorie parente.');
        }

        if (! $this->exists) {
            return;
        }

        $identifiantCourant = (int) $this->getKey();
        $ancetre = self::find($this->categorie_parent_id);
        $garde = 0;

        while ($ancetre !== null) {
            if ((int) $ancetre->getKey() === $identifiantCourant) {
                throw new RuntimeException(
                    "La catégorie « {$this->libelle} » ne peut pas être rattachée à « {$ancetre->libelle} » : "
                    .'cela formerait une boucle dans la nomenclature.'
                );
            }

            // Filet de sécurité : si une boucle préexiste en base, on
            // s'arrête au lieu de boucler pour de bon.
            if (++$garde > 50) {
                break;
            }

            $ancetre = $ancetre->categorie_parent_id ? self::find($ancetre->categorie_parent_id) : null;
        }
    }

    public function getCheminAttribute(): string
    {
        return $this->parent
            ? "{$this->parent->libelle} › {$this->libelle}"
            : $this->libelle;
    }
}
