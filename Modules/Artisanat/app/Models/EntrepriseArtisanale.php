<?php

namespace Modules\Artisanat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Socle\Models\VillageArtisanal;

/**
 * Structure formelle déclarée par un ou plusieurs artisans.
 *
 * Le rattachement d'un artisan à une entreprise reste facultatif :
 * la majorité des artisans du village exercent en nom propre, et
 * imposer une entreprise fictive à ceux-là fausserait les statistiques
 * de formalisation attendues par la coordination.
 *
 * @property int $id
 * @property string $raison_sociale
 * @property string|null $numero_contribuable
 * @property int $village_id
 */
class EntrepriseArtisanale extends Model
{
    protected $table = 'entreprises_artisanales';

    protected $fillable = [
        'raison_sociale',
        'numero_contribuable',
        'telephone',
        'adresse',
        'village_id',
    ];

    public function village(): BelongsTo
    {
        return $this->belongsTo(VillageArtisanal::class, 'village_id');
    }

    public function artisans(): HasMany
    {
        return $this->hasMany(Artisan::class, 'entreprise_id');
    }

    public function estFormalisee(): bool
    {
        return filled($this->numero_contribuable);
    }
}
