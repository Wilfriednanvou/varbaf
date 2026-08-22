<?php

namespace Modules\Portail\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Artisanat\Models\Artisan;
use Modules\Portail\Exceptions\PublicationPortailException;
use Modules\Socle\Models\Utilisateur;

/**
 * Mise en avant d'un artisan sur le portail, pour une période.
 *
 * Une période plutôt qu'un drapeau : la coordination prépare ses mises
 * en avant à l'avance et veut qu'elles s'éteignent seules. Un drapeau
 * obligerait quelqu'un à penser à le retirer, et personne n'y pense.
 *
 * L'autorisation de l'artisan est vérifiée à l'écriture **et** à la
 * lecture : une autorisation retirée après coup doit faire disparaître
 * la mise en avant sans qu'on ait à repasser sur les enregistrements.
 */
class ArtisanVedette extends Model
{
    protected $table = 'artisans_vedettes';

    protected $fillable = [
        'artisan_id',
        'date_debut',
        'date_fin',
        'texte',
        'ordre_affichage',
        'cree_par',
    ];

    protected $attributes = [
        'ordre_affichage' => 0,
    ];

    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin' => 'date',
            'ordre_affichage' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $vedette): void {
            $artisan = $vedette->artisan;

            if (! $artisan) {
                return;
            }

            if (! $artisan->autorisation_publication) {
                throw PublicationPortailException::artisanSansAutorisation($artisan->nom_complet);
            }
        });
    }

    public function artisan(): BelongsTo
    {
        return $this->belongsTo(Artisan::class, 'artisan_id');
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'cree_par');
    }

    /**
     * Les mises en avant actives aujourd'hui.
     *
     * `date_fin` nulle vaut « sans terme » — même convention que les
     * attributions de boutique.
     */
    public function scopeEnCours(Builder $requete): Builder
    {
        return $requete
            ->whereDate('date_debut', '<=', now())
            ->where(fn (Builder $sousRequete) => $sousRequete
                ->whereNull('date_fin')
                ->orWhereDate('date_fin', '>=', now()))
            ->whereHas('artisan', fn (Builder $sousRequete) => $sousRequete->publiable());
    }

    public function estEnCours(): bool
    {
        $aujourdhui = now()->startOfDay();

        return $this->date_debut?->lte($aujourdhui)
            && ($this->date_fin === null || $this->date_fin->gte($aujourdhui))
            && (bool) $this->artisan?->autorisation_publication
            && (bool) $this->artisan?->actif;
    }
}
