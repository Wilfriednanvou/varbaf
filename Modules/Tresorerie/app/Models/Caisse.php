<?php

namespace Modules\Tresorerie\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Socle\Models\Agent;
use Modules\Socle\Models\VillageArtisanal;
use Modules\Tresorerie\Enums\EtatCaisse;
use Modules\Tresorerie\Enums\EtatSectionCaisse;

/**
 * Caisse du village artisanal (RG-22).
 *
 * Chaque caisse est rattachée à un caissier responsable et à un
 * village. En pratique le village n'a probablement qu'une seule caisse,
 * mais le modèle ne l'impose pas.
 *
 * @property int $id
 * @property string $code
 * @property string $libelle
 * @property EtatCaisse $etat
 * @property int $village_id
 * @property int|null $caissier_responsable_id
 */
class Caisse extends Model
{
    protected $table = 'caisses';

    protected $fillable = [
        'code',
        'libelle',
        'caissier_responsable_id',
        'etat',
        'village_id',
    ];

    protected function casts(): array
    {
        return [
            'etat' => EtatCaisse::class,
        ];
    }

    public function caissierResponsable(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'caissier_responsable_id');
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(VillageArtisanal::class, 'village_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(SectionCaisse::class, 'caisse_id');
    }

    /**
     * La section actuellement ouverte, s'il y en a une (RG-01).
     */
    public function sectionOuverte(): HasOne
    {
        return $this->hasOne(SectionCaisse::class, 'caisse_id')
            ->where('etat', EtatSectionCaisse::OUVERTE);
    }

    public function estActive(): bool
    {
        return $this->etat === EtatCaisse::ACTIVE;
    }

    public function getIdentiteAttribute(): string
    {
        return "{$this->code} — {$this->libelle}";
    }
}
