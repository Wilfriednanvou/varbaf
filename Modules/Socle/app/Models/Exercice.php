<?php

namespace Modules\Socle\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Socle\Exceptions\ExerciceNonCloturableException;
use Modules\Socle\Services\VerrousDeCloture;

/**
 * Période comptable et fonctionnelle du village.
 *
 * Règle imposée au niveau du modèle : un seul exercice en cours par
 * village à tout instant. L'invariant est tenu à deux niveaux —
 * un crochet « saving » qui bascule automatiquement l'exercice
 * précédent, et un index unique partiel PostgreSQL qui interdit
 * physiquement un second enregistrement en cours (voir la migration).
 * Le crochet garantit le confort d'usage, l'index garantit
 * l'intégrité même si une écriture contourne l'ORM.
 *
 * @property int $id
 * @property string $libelle
 * @property \Illuminate\Support\Carbon $date_debut
 * @property \Illuminate\Support\Carbon $date_fin
 * @property bool $en_cours
 * @property bool $cloture
 * @property int $village_id
 */
class Exercice extends Model
{
    protected $table = 'exercices';

    protected $fillable = [
        'libelle',
        'date_debut',
        'date_fin',
        'en_cours',
        'cloture',
        'village_id',
    ];

    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin' => 'date',
            'en_cours' => 'boolean',
            'cloture' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $exercice): void {
            // Un exercice clôturé ne peut plus être l'exercice en cours.
            if ($exercice->cloture) {
                $exercice->en_cours = false;
            }

            if (! $exercice->en_cours) {
                return;
            }

            // Bascule les autres exercices du même village avant
            // l'écriture, afin que l'index unique partiel ne soit
            // jamais violé, même le temps d'une transaction.
            static::query()
                ->where('village_id', $exercice->village_id)
                ->where('en_cours', true)
                ->when($exercice->exists, fn (Builder $requete) => $requete->whereKeyNot($exercice->getKey()))
                ->update(['en_cours' => false]);
        });
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(VillageArtisanal::class, 'village_id');
    }

    public function scopeEnCours(Builder $requete): Builder
    {
        return $requete->where('en_cours', true);
    }

    /**
     * Exercice en cours, tous villages confondus.
     *
     * Point d'entrée unique des autres modules : aucun d'eux ne
     * requête directement la table des exercices.
     */
    public static function courant(): ?self
    {
        return static::query()->enCours()->first();
    }

    /**
     * Active cet exercice et désactive le précédent.
     */
    public function activer(): bool
    {
        if ($this->cloture) {
            return false;
        }

        $this->en_cours = true;

        return $this->save();
    }

    /**
     * Clôture l'exercice. Action irréversible.
     *
     * **Ce que la clôture vérifie, et comment.** Un exercice ne se
     * clôture pas tant qu'une section de caisse reste ouverte ou qu'une
     * campagne de reversement attend sa validation — sans quoi on
     * refermerait une période en laissant de l'argent en caisse et des
     * artisans impayés. Ces deux notions appartiennent au module
     * Trésorerie, que le Socle n'a pas le droit de connaître : il
     * interroge donc le registre des verrous, où chaque module vient
     * déclarer ce qui, chez lui, s'oppose à une clôture.
     *
     * Le registre est résolu au conteneur plutôt qu'injecté, parce
     * qu'un modèle Eloquent s'instancie sans constructeur maîtrisé.
     * Vide — Socle seul —, il n'empêche rien, ce qui est le
     * comportement juste : sans caisse, il n'y a rien à protéger.
     *
     * @throws ExerciceNonCloturableException si un module s'y oppose
     */
    public function cloturer(): bool
    {
        if ($this->cloture) {
            return false;
        }

        $obstacles = app(VerrousDeCloture::class)->obstacles($this);

        if ($obstacles !== []) {
            throw ExerciceNonCloturableException::pour($this, $obstacles);
        }

        $this->cloture = true;
        $this->en_cours = false;

        return $this->save();
    }

    public function estModifiable(): bool
    {
        return ! $this->cloture;
    }
}
