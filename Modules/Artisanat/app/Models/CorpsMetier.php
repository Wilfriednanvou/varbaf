<?php

namespace Modules\Artisanat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Corps de métier de l'artisanat : vannerie, poterie, sculpture sur
 * bois, tissage, fonderie du bronze, etc.
 *
 * Référentiel commun au village, non rattaché à un village précis :
 * la nomenclature des métiers ne varie pas d'un village artisanal à
 * l'autre, contrairement au parc de boutiques.
 *
 * @property int $id
 * @property string $code
 * @property string $libelle
 */
class CorpsMetier extends Model
{
    protected $table = 'corps_metiers';

    protected $fillable = [
        'code',
        'libelle',
        'description',
    ];

    public function artisans(): HasMany
    {
        return $this->hasMany(Artisan::class, 'corps_metier_id');
    }
}
