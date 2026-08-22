<?php

namespace Modules\Tresorerie\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Commerce\Models\Vente;
use Modules\Tresorerie\Enums\TypeLigneReversement;
use Modules\Tresorerie\Exceptions\CampagneReversementException;

/**
 * Détail d'un reversement, vente par vente.
 *
 * La ligne porte la trace qui rend RG-20 applicable : sans elle, une
 * vente annulée après avoir été payée serait reprise à chaque campagne
 * suivante, indéfiniment. Une ligne `REPRISE` dit « c'est fait ».
 *
 * Elle porte aussi `date_origine`, exigée par RG-19 : une vente
 * rattrapée deux mois plus tard doit apparaître sur le reçu avec la date
 * qu'elle avait, pas celle de la campagne qui la paie.
 *
 * Écrite une fois par la préparation, jamais retouchée : recalculer une
 * campagne en préparation efface ses reversements et leurs lignes, puis
 * les reconstruit.
 *
 * @property TypeLigneReversement $type
 * @property int $montant
 * @property \Illuminate\Support\Carbon $date_origine
 */
class LigneReversement extends Model
{
    protected $table = 'lignes_reversement';

    protected $fillable = [
        'reversement_id',
        'vente_id',
        'type',
        'montant',
        'date_origine',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeLigneReversement::class,
            'montant' => 'integer',
            'date_origine' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw CampagneReversementException::ligneFigee();
        });
    }

    public function reversement(): BelongsTo
    {
        return $this->belongsTo(Reversement::class, 'reversement_id');
    }

    public function vente(): BelongsTo
    {
        return $this->belongsTo(Vente::class, 'vente_id');
    }

    public function scopeReprises(Builder $requete): Builder
    {
        return $requete->where('type', TypeLigneReversement::REPRISE->value);
    }

    public function estUneReprise(): bool
    {
        return $this->type === TypeLigneReversement::REPRISE;
    }
}
