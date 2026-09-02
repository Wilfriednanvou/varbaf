<?php

namespace Modules\Artisanat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Modules\Artisanat\Enums\StatutAttribution;
use Modules\Socle\Enums\Sexe;
use Modules\Socle\Models\VillageArtisanal;

/**
 * Artisan enregistré au village.
 *
 * Le matricule suit le même principe que celui de l'agent — produit à
 * la création, jamais saisi — avec un préfixe distinct : un matricule
 * lu à voix haute en soutenance ou sur un reçu doit dire de lui-même
 * s'il désigne un artisan ou un agent du village.
 *
 * L'enum Sexe est repris du Socle plutôt que redéfini : la règle de
 * dépendance descendante autorise Artisanat à s'appuyer sur le Socle,
 * et deux enums identiques divergeraient tôt ou tard.
 *
 * @property int $id
 * @property string $matricule
 * @property string $nom
 * @property string|null $prenom
 * @property Sexe|null $sexe
 * @property bool $actif
 * @property bool $autorisation_publication
 * @property int $corps_metier_id
 * @property int|null $entreprise_id
 * @property int $village_id
 */
class Artisan extends Model
{
    protected $table = 'artisans';

    /**
     * Le matricule est absent : il est calculé, pas saisi.
     */
    protected $fillable = [
        'nom',
        'prenom',
        'sexe',
        'telephone',
        'email',
        'adresse',
        'departement_origine',
        'numero_enregistrement',
        'photo',
        'actif',
        'autorisation_publication',
        'corps_metier_id',
        'entreprise_id',
        'village_id',
    ];

    protected function casts(): array
    {
        return [
            'sexe' => Sexe::class,
            'actif' => 'boolean',
            'autorisation_publication' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $artisan): void {
            if (blank($artisan->matricule)) {
                $artisan->matricule = static::genererMatricule();
            }
        });
    }

    /**
     * Produit le matricule suivant : ART-2026-0001, ART-2026-0002, etc.
     */
    public static function genererMatricule(): string
    {
        $prefixe = 'ART-'.now()->format('Y').'-';

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

    public function corpsMetier(): BelongsTo
    {
        return $this->belongsTo(CorpsMetier::class, 'corps_metier_id');
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(EntrepriseArtisanale::class, 'entreprise_id');
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(VillageArtisanal::class, 'village_id');
    }

    public function attributions(): HasMany
    {
        return $this->hasMany(AttributionEspace::class, 'artisan_id');
    }

    public function participationsExercices(): HasMany
    {
        return $this->hasMany(ArtisanExercice::class, 'artisan_id');
    }

    public function scopeActif(Builder $requete): Builder
    {
        return $requete->where('actif', true);
    }

    public function scopePubliable(Builder $requete): Builder
    {
        return $requete->where('actif', true)->where('autorisation_publication', true);
    }

    /**
     * Espace locatif occupé à la date du jour, s'il y en a un.
     */
    public function getAttributionActive(): ?AttributionEspace
    {
        return $this->attributions()
            ->where('statut', StatutAttribution::ACTIVE->value)
            ->whereDate('date_debut', '<=', now())
            ->where(fn (Builder $requete) => $requete
                ->whereNull('date_fin')
                ->orWhereDate('date_fin', '>=', now()))
            ->latest('date_debut')
            ->first();
    }

    /**
     * Toutes les occupations, en cours et passées.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, AttributionEspace>
     */
    public function getHistoriqueAttributions()
    {
        return $this->attributions()->latest('date_debut')->get();
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
