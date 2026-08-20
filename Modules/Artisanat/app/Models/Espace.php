<?php

namespace Modules\Artisanat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Artisanat\Enums\TypeEspace;
use Modules\Socle\Models\VillageArtisanal;

/**
 * Salle de réunion, salle d'apprentissage, stand ou parking mis à
 * disposition par le village.
 *
 * Le référentiel est livré en version 1 ; la réservation d'espaces
 * relève de la version 2 selon docs/retroplanning.md. Enregistrer les
 * espaces dès maintenant permet de saisir leur mise à disposition comme
 * une simple recette de caisse, sans attendre le module de réservation.
 *
 * @property int $id
 * @property string $nom
 * @property TypeEspace $type
 * @property bool $disponible
 * @property int $village_id
 */
class Espace extends Model
{
    protected $table = 'espaces';

    protected $fillable = [
        'nom',
        'type',
        'capacite',
        'tarif_journalier',
        'disponible',
        'village_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeEspace::class,
            'capacite' => 'integer',
            'tarif_journalier' => 'decimal:2',
            'disponible' => 'boolean',
        ];
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(VillageArtisanal::class, 'village_id');
    }
}
