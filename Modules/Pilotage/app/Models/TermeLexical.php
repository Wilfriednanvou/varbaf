<?php

namespace Modules\Pilotage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une entrée de l'index inversé.
 *
 * Sans horodatage : la table est réécrite en bloc à chaque
 * réindexation, et la date qui compte est celle de la fiche.
 *
 * @property int $id
 * @property int $fiche_id
 * @property string $terme
 * @property int $frequence
 * @property float $poids
 */
class TermeLexical extends Model
{
    protected $table = 'termes_lexicaux';

    public $timestamps = false;

    protected $fillable = [
        'fiche_id',
        'terme',
        'frequence',
        'poids',
    ];

    protected function casts(): array
    {
        return [
            'fiche_id' => 'integer',
            'frequence' => 'integer',
            'poids' => 'float',
        ];
    }

    public function fiche(): BelongsTo
    {
        return $this->belongsTo(FicheLexicale::class, 'fiche_id');
    }
}
