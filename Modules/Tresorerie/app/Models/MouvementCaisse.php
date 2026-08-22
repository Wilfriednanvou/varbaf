<?php

namespace Modules\Tresorerie\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Socle\Models\Utilisateur;
use Modules\Tresorerie\Enums\NatureMouvementCaisse;
use Modules\Tresorerie\Enums\SensMouvementCaisse;
use Modules\Tresorerie\Exceptions\MouvementCaisseImmuableException;

/**
 * Écriture du brouillard de caisse (RG-04, RG-05, RG-06).
 *
 * **Immuable dès l'enregistrement.** Les crochets « updating » et
 * « deleting » lèvent une exception : la correction passe par
 * `ServiceTresorerie::contrepasser()`, qui crée un mouvement de sens
 * inverse référençant celui-ci. Le mouvement d'origine reste en place.
 *
 * Le modèle ne s'écrit jamais directement depuis un autre module :
 * `ServiceTresorerie` est le point d'entrée unique (RG-06), seul
 * capable de garantir la numérotation sans rupture et le calcul du
 * solde progressif.
 *
 * @property int $numero_ordre
 * @property NatureMouvementCaisse $nature
 * @property SensMouvementCaisse $sens
 * @property int $montant
 * @property int $solde_apres
 * @property string $libelle
 * @property int $section_id
 * @property int|null $libelle_mouvement_id
 */
class MouvementCaisse extends Model
{
    protected $table = 'mouvements_caisse';

    public const UPDATED_AT = null;

    protected $fillable = [
        'numero_ordre',
        'date_operation',
        'section_id',
        'nature',
        'libelle_mouvement_id',
        'sens',
        'montant',
        'solde_apres',
        'libelle',
        'piece_justificative',
        'origine_type',
        'origine_id',
        'mouvement_contrepasse_id',
        'saisi_par',
    ];

    protected function casts(): array
    {
        return [
            'date_operation' => 'datetime',
            'nature' => NatureMouvementCaisse::class,
            'sens' => SensMouvementCaisse::class,
            'montant' => 'integer',
            'solde_apres' => 'integer',
            'numero_ordre' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $mouvement): void {
            throw MouvementCaisseImmuableException::modification();
        });

        static::deleting(function (self $mouvement): void {
            throw MouvementCaisseImmuableException::suppression();
        });
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(SectionCaisse::class, 'section_id');
    }

    public function libelleMouvement(): BelongsTo
    {
        return $this->belongsTo(LibelleMouvement::class, 'libelle_mouvement_id');
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'saisi_par');
    }

    /**
     * Le mouvement que celui-ci annule, s'il est une contre-passation.
     */
    public function mouvementContrepasse(): BelongsTo
    {
        return $this->belongsTo(self::class, 'mouvement_contrepasse_id');
    }

    /**
     * La contre-passation qui annule ce mouvement, s'il y en a une.
     */
    public function contrepassation(): HasOne
    {
        return $this->hasOne(self::class, 'mouvement_contrepasse_id');
    }

    public function estUneContrepassation(): bool
    {
        return $this->mouvement_contrepasse_id !== null;
    }

    public function estContrepasse(): bool
    {
        return $this->contrepassation()->exists();
    }

    /**
     * Montant signé, pour les cumuls et les états.
     */
    public function montantSigne(): int
    {
        return $this->sens->signe() * $this->montant;
    }

    public function libelleOrigine(): ?string
    {
        if (blank($this->origine_type)) {
            return null;
        }

        return "{$this->origine_type} #{$this->origine_id}";
    }
}
