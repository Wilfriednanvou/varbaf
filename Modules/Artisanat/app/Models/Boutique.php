<?php

namespace Modules\Artisanat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Artisanat\Enums\EtatBoutique;
use Modules\Artisanat\Enums\StatutAttribution;
use Modules\Socle\Models\VillageArtisanal;

/**
 * Local commercial du village, attribué à un artisan pour une période.
 *
 * L'état DISPONIBLE / OCCUPEE n'est pas saisi : il est recalculé par
 * AttributionBoutique à chaque mouvement de contrat. Seul INDISPONIBLE
 * relève d'une décision de la coordination, et il est le seul à bloquer
 * une nouvelle attribution — une boutique occupée peut parfaitement
 * recevoir une attribution future qui ne chevauche pas la courante.
 *
 * @property int $id
 * @property string $numero
 * @property EtatBoutique $etat
 * @property int $village_id
 */
class Boutique extends Model
{
    protected $table = 'boutiques';

    protected $fillable = [
        'numero',
        'superficie',
        'emplacement',
        'redevance_mensuelle',
        'etat',
        'village_id',
    ];

    /**
     * Une boutique naît disponible. Le défaut est porté par le modèle
     * en plus de la base pour que l'état soit lisible sur une instance
     * neuve, avant même l'écriture.
     */
    protected $attributes = [
        'etat' => 'DISPONIBLE',
    ];

    protected function casts(): array
    {
        return [
            'superficie' => 'decimal:2',
            'redevance_mensuelle' => 'decimal:2',
            'etat' => EtatBoutique::class,
        ];
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(VillageArtisanal::class, 'village_id');
    }

    public function attributions(): HasMany
    {
        return $this->hasMany(AttributionBoutique::class, 'boutique_id');
    }

    public function attributionsActives(): HasMany
    {
        return $this->attributions()->where('statut', StatutAttribution::ACTIVE->value);
    }

    public function scopeAttribuable(Builder $requete): Builder
    {
        return $requete->where('etat', '!=', EtatBoutique::INDISPONIBLE->value);
    }

    /**
     * Artisan attributaire à la date du jour.
     */
    public function getOccupantActuel(): ?Artisan
    {
        return $this->attributionsActives()
            ->whereDate('date_debut', '<=', now())
            ->where(fn (Builder $requete) => $requete
                ->whereNull('date_fin')
                ->orWhereDate('date_fin', '>=', now()))
            ->latest('date_debut')
            ->first()
            ?->artisan;
    }

    /**
     * Vérifie l'absence d'attribution active sur la période.
     *
     * Une date de fin nulle vaut « sans terme » : la période court
     * jusqu'à l'infini et recouvre donc toute attribution ultérieure.
     */
    public function estDisponible(string $dateDebut, ?string $dateFin = null, ?int $ignorerAttributionId = null): bool
    {
        if ($this->etat === EtatBoutique::INDISPONIBLE) {
            return false;
        }

        return ! AttributionBoutique::existeChevauchement(
            $this->getKey(),
            $dateDebut,
            $dateFin,
            $ignorerAttributionId,
        );
    }

    /**
     * Réaligne l'état sur la réalité des contrats.
     *
     * Appelée par AttributionBoutique à chaque création, modification
     * ou résiliation. INDISPONIBLE n'est jamais écrasé : c'est une
     * décision administrative que le système n'a pas à défaire.
     */
    public function synchroniserEtat(): void
    {
        if ($this->etat === EtatBoutique::INDISPONIBLE) {
            return;
        }

        $occupee = $this->attributionsActives()
            ->whereDate('date_debut', '<=', now())
            ->where(fn (Builder $requete) => $requete
                ->whereNull('date_fin')
                ->orWhereDate('date_fin', '>=', now()))
            ->exists();

        $etat = $occupee ? EtatBoutique::OCCUPEE : EtatBoutique::DISPONIBLE;

        if ($this->etat !== $etat) {
            $this->etat = $etat;
            $this->save();
        }
    }
}
