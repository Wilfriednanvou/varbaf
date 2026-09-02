<?php

namespace Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Commerce\Enums\StatutParticipationProduit;
use Modules\Socle\Models\Exercice;

/**
 * Participation d'un produit a un exercice — meme principe que
 * `ArtisanExercice`, voir son commentaire pour le motif.
 *
 * Distincte de `Produit.statut_validation` : la validation decrit le
 * produit lui-meme (soumis, valide, expose, retire) et ne depend
 * d'aucun exercice ; cette table decrit si le produit est propose
 * *cette annee-la*, independamment d'ou il en est dans son cycle de
 * validation.
 *
 * @property int $id
 * @property int $produit_id
 * @property int $exercice_id
 * @property StatutParticipationProduit $statut
 */
class ProduitExercice extends Model
{
    protected $table = 'produit_exercices';

    protected $fillable = [
        'produit_id',
        'exercice_id',
        'statut',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutParticipationProduit::class,
        ];
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(Exercice::class, 'exercice_id');
    }
}
