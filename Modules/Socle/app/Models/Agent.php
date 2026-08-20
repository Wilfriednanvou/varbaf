<?php

namespace Modules\Socle\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Modules\Socle\Enums\Sexe;

/**
 * Personnel du village : coordonnateur, caissier, agent commercial.
 *
 * Le matricule n'est jamais saisi : il est produit à la création selon
 * le gabarit AG-AAAA-NNNN, séquentiel par année civile. L'unicité est
 * garantie en dernier ressort par la contrainte unique portée par la
 * colonne ; le calcul du rang suivant est fait dans une transaction
 * avec verrou sur les lignes lues, ce qui suffit à l'usage mono-poste
 * du village.
 *
 * @property int $id
 * @property string $matricule
 * @property string $nom
 * @property string $prenom
 * @property Sexe $sexe
 * @property bool $actif
 * @property int $village_id
 */
class Agent extends Model
{
    protected $table = 'agents';

    /**
     * Le matricule est volontairement absent : il est calculé, pas saisi.
     */
    protected $fillable = [
        'nom',
        'prenom',
        'sexe',
        'telephone',
        'email',
        'fonction',
        'date_prise_service',
        'actif',
        'village_id',
    ];

    protected function casts(): array
    {
        return [
            'sexe' => Sexe::class,
            'date_prise_service' => 'date',
            'actif' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $agent): void {
            if (blank($agent->matricule)) {
                $agent->matricule = static::genererMatricule();
            }
        });
    }

    /**
     * Produit le matricule suivant : AG-2026-0001, AG-2026-0002, etc.
     *
     * Le compteur repart à 1 à chaque année civile. Le préfixe de
     * l'année est extrait du matricule lui-même plutôt que de la date
     * de création, afin que la reprise d'un historique ne casse pas la
     * séquence.
     */
    public static function genererMatricule(): string
    {
        $annee = now()->format('Y');
        $prefixe = "AG-{$annee}-";

        return DB::transaction(function () use ($prefixe): string {
            $dernier = static::query()
                ->where('matricule', 'like', $prefixe.'%')
                ->lockForUpdate()
                ->orderByDesc('matricule')
                ->value('matricule');

            $rang = $dernier ? ((int) substr($dernier, strlen($prefixe))) + 1 : 1;

            return $prefixe.str_pad((string) $rang, 4, '0', STR_PAD_LEFT);
        });
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(VillageArtisanal::class, 'village_id');
    }

    public function utilisateur(): HasOne
    {
        return $this->hasOne(Utilisateur::class, 'agent_id');
    }

    public function getNomCompletAttribute(): string
    {
        return trim("{$this->nom} {$this->prenom}");
    }

    public function getIdentiteAttribute(): string
    {
        return "{$this->matricule} — {$this->nom_complet}";
    }
}
