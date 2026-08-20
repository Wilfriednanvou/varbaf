<?php

namespace Modules\Socle\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Socle\Enums\CategorieVillage;

/**
 * Racine multi-structure du système.
 *
 * Le modèle est prévu dès l'origine pour plusieurs villages : toutes les
 * entités métier portent une clé étrangère vers cette table, ce qui rend
 * le système réplicable aux autres villages artisanaux sans reprise de
 * schéma.
 *
 * @property int $id
 * @property string $code
 * @property string $nom
 * @property CategorieVillage $categorie
 * @property string $region
 * @property bool $actif
 */
class VillageArtisanal extends Model
{
    protected $table = 'villages_artisanaux';

    protected $fillable = [
        'code',
        'nom',
        'categorie',
        'region',
        'adresse',
        'telephone',
        'email',
        'nombre_boutiques',
        'superficie',
        'actif',
    ];

    protected function casts(): array
    {
        return [
            'categorie' => CategorieVillage::class,
            'nombre_boutiques' => 'integer',
            'superficie' => 'decimal:2',
            'actif' => 'boolean',
        ];
    }

    public function exercices(): HasMany
    {
        return $this->hasMany(Exercice::class, 'village_id');
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class, 'village_id');
    }

    /**
     * Exercice actuellement en cours pour ce village, s'il en existe un.
     */
    public function exerciceEnCours(): ?Exercice
    {
        return $this->exercices()->where('en_cours', true)->first();
    }

    public function getNomCompletAttribute(): string
    {
        return "{$this->code} — {$this->nom}";
    }
}
