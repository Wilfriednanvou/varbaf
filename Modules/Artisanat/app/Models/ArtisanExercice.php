<?php

namespace Modules\Artisanat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Artisanat\Enums\StatutParticipationArtisan;
use Modules\Socle\Models\Exercice;

/**
 * Participation d'un artisan a un exercice.
 *
 * **Pourquoi cette table existe a cote d'`Artisan`.** L'identite d'un
 * artisan est permanente : nom, coordonnees, corps de metier. Sa
 * participation ne l'est pas — un artisan peut etre actif cette annee
 * et ne pas etre reconduit la suivante, sans que rien de lui-meme ne
 * change. Confondre les deux forcerait a choisir entre perdre
 * l'historique (en supprimant l'artisan) ou perdre la distinction par
 * exercice (en gardant un seul `actif` global) — les deux sont
 * mauvais, cette table separe les deux questions.
 *
 * @property int $id
 * @property int $artisan_id
 * @property int $exercice_id
 * @property StatutParticipationArtisan $statut
 * @property \Illuminate\Support\Carbon $date_activation
 * @property \Illuminate\Support\Carbon|null $date_desactivation
 * @property string|null $motif_desactivation
 */
class ArtisanExercice extends Model
{
    protected $table = 'artisan_exercices';

    protected $fillable = [
        'artisan_id',
        'exercice_id',
        'statut',
        'date_activation',
        'date_desactivation',
        'motif_desactivation',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutParticipationArtisan::class,
            'date_activation' => 'date',
            'date_desactivation' => 'date',
        ];
    }

    public function artisan(): BelongsTo
    {
        return $this->belongsTo(Artisan::class, 'artisan_id');
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(Exercice::class, 'exercice_id');
    }
}
