<?php

namespace Modules\Tresorerie\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Tresorerie\Enums\NatureMouvementCaisse;

/**
 * Référentiel de libellés de mouvement de caisse.
 *
 * Table de paramétrage : les libellés prédéfinis alimentent la liste
 * de saisie et les rapports. Supprimable — c'est un libellé de
 * référentiel, pas un enregistrement porteur d'histoire.
 *
 * @property int $id
 * @property string $code
 * @property string $libelle
 * @property string $sens
 * @property bool $actif
 */
class LibelleMouvement extends Model
{
    protected $table = 'libelles_mouvement';

    protected $fillable = [
        'code',
        'libelle',
        'sens',
        'actif',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
        ];
    }

    /**
     * Libellés proposables à la saisie manuelle : actifs, et dont le
     * code correspond à une nature de mouvement connue autre que la
     * vente et la contre-passation — toutes deux hors de portée d'une
     * saisie manuelle (la vente a son propre écran, la contre-passation
     * n'est jamais une écriture nouvelle mais la correction d'une
     * existante).
     */
    public function scopeSaisissables(Builder $query): Builder
    {
        $codes = collect(NatureMouvementCaisse::cases())
            ->reject(fn (NatureMouvementCaisse $n) => in_array($n, [
                NatureMouvementCaisse::VENTE,
                NatureMouvementCaisse::CONTREPASSATION,
            ], true))
            ->map(fn (NatureMouvementCaisse $n) => $n->value)
            ->all();

        return $query->where('actif', true)->whereIn('code', $codes);
    }
}
