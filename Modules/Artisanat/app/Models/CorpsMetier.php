<?php

namespace Modules\Artisanat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Secteur d'activité artisanale : sculpture, bronze, textile, vannerie,
 * agroalimentaire, etc.
 *
 * La nomenclature n'est pas une description libre des filières de
 * l'Ouest : ce sont les quatorze secteurs sous lesquels la structure
 * s'organise réellement, et sous lesquels la coordination lit ses états.
 * Le seeder en est la source, et il fait autorité.
 *
 * Référentiel commun au village, non rattaché à un village précis : le
 * découpage sectoriel ne varie pas d'un village artisanal à l'autre,
 * contrairement au parc de boutiques.
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
