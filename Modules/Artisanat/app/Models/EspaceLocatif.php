<?php

namespace Modules\Artisanat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Modules\Artisanat\Enums\EtatEspaceLocatif;
use Modules\Artisanat\Enums\StatutAttribution;
use Modules\Artisanat\Exceptions\EspaceLocatifException;

/**
 * Unité réellement louée du village : une place de vente à l'intérieur
 * d'une boutique.
 *
 * **Ce que le relevé du village a corrigé.** Le parc compte dix-sept
 * boutiques, mais bien plus d'artisans installés : plusieurs d'entre eux
 * se partagent le même local. Tant que l'attribution portait sur la
 * boutique, deux artisans voisins produisaient un chevauchement et le
 * système refusait la seconde attribution — c'est-à-dire qu'il refusait
 * la situation réelle. L'espace locatif est la maille qui manquait :
 * c'est lui qui se loue, lui qui est libre ou pris, lui que la règle de
 * non-chevauchement protège. La boutique redevient ce qu'elle est
 * physiquement, un contenant.
 *
 * **Code dérivé et figé.** B01 abrite B0101, B0102… Le code se compose à
 * la création à partir du numéro de la boutique et d'un rang, et ne
 * bouge plus : il figure sur les contrats signés.
 *
 * @property int $id
 * @property string $code
 * @property string|null $libelle
 * @property EtatEspaceLocatif $etat
 * @property int $boutique_id
 */
class EspaceLocatif extends Model
{
    protected $table = 'espaces_locatifs';

    /**
     * `code` est volontairement absent : il se dérive de la boutique,
     * il ne se saisit pas.
     */
    protected $fillable = [
        'libelle',
        'etat',
        'boutique_id',
    ];

    /**
     * Un espace naît disponible. Le défaut est porté par le modèle en
     * plus de la base pour que l'état soit lisible sur une instance
     * neuve, avant même l'écriture.
     */
    protected $attributes = [
        'etat' => 'DISPONIBLE',
    ];

    protected function casts(): array
    {
        return [
            'etat' => EtatEspaceLocatif::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $espace): void {
            if (blank($espace->code)) {
                $espace->code = static::genererCode((int) $espace->boutique_id);
            }
        });

        static::updating(function (self $espace): void {
            if ($espace->isDirty('code')) {
                throw EspaceLocatifException::codeFige((string) $espace->getOriginal('code'));
            }

            // Le code encode la boutique : les laisser diverger
            // produirait un B0103 rattaché à B02.
            if ($espace->isDirty('boutique_id')) {
                throw EspaceLocatifException::boutiqueFigee((string) $espace->code);
            }
        });

        static::deleting(function (self $espace): void {
            if ($espace->attributions()->exists()) {
                throw EspaceLocatifException::occupeParUneAttribution((string) $espace->code);
            }
        });
    }

    /**
     * Produit le code suivant pour une boutique : B01 donne B0101, puis
     * B0102.
     *
     * Le préfixe est tiré du numéro de la boutique — « B01 », « B-01 »
     * ou « 1 » produisent tous « B01 » — et le rang est propre à la
     * boutique, ce qui rend le code lisible à l'œil sur un plan du
     * bâtiment.
     */
    public static function genererCode(int $boutiqueId): string
    {
        $numero = Boutique::query()->whereKey($boutiqueId)->value('numero');

        $chiffres = preg_replace('/\D/', '', (string) $numero) ?: '00';
        $prefixe = 'B'.str_pad($chiffres, 2, '0', STR_PAD_LEFT);

        return DB::transaction(function () use ($boutiqueId, $prefixe): string {
            // Le rang se cherche numériquement, et non par tri du code.
            //
            // « B0099 » et « B00100 » ne se comparent pas comme les
            // nombres qu'ils encodent : lexicographiquement, le premier
            // est le plus grand. Un `orderByDesc('code')` retournerait
            // donc B0099 alors que B00100 existe, et la boutique
            // reproposerait au centième espace un code déjà pris — que
            // l'index unique refuserait, sans qu'on comprenne pourquoi.
            //
            // Le cas n'est pas théorique : la reprise du registre
            // transcrit rattache plus de cent emplacements hors parc à
            // une même boutique technique.
            $rangs = static::query()
                ->where('boutique_id', $boutiqueId)
                ->lockForUpdate()
                ->pluck('code')
                ->map(fn (string $code) => (int) substr($code, strlen($prefixe)))
                ->all();

            $rang = ($rangs === [] ? 0 : max($rangs)) + 1;

            return $prefixe.str_pad((string) $rang, 2, '0', STR_PAD_LEFT);
        });
    }

    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class, 'boutique_id');
    }

    public function attributions(): HasMany
    {
        return $this->hasMany(AttributionEspace::class, 'espace_locatif_id');
    }

    public function attributionsActives(): HasMany
    {
        return $this->attributions()->where('statut', StatutAttribution::ACTIVE->value);
    }

    public function scopeAttribuable(Builder $requete): Builder
    {
        return $requete->where('etat', '!=', EtatEspaceLocatif::INDISPONIBLE->value);
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
        if ($this->etat === EtatEspaceLocatif::INDISPONIBLE) {
            return false;
        }

        return ! AttributionEspace::existeChevauchement(
            $this->getKey(),
            $dateDebut,
            $dateFin,
            $ignorerAttributionId,
        );
    }

    /**
     * Réaligne l'état sur la réalité des contrats.
     *
     * Appelée par AttributionEspace à chaque création, modification ou
     * résiliation. INDISPONIBLE n'est jamais écrasé : c'est une décision
     * administrative que le système n'a pas à défaire.
     */
    public function synchroniserEtat(): void
    {
        if ($this->etat === EtatEspaceLocatif::INDISPONIBLE) {
            return;
        }

        $occupe = $this->attributionsActives()
            ->whereDate('date_debut', '<=', now())
            ->where(fn (Builder $requete) => $requete
                ->whereNull('date_fin')
                ->orWhereDate('date_fin', '>=', now()))
            ->exists();

        $etat = $occupe ? EtatEspaceLocatif::OCCUPE : EtatEspaceLocatif::DISPONIBLE;

        if ($this->etat !== $etat) {
            $this->etat = $etat;
            $this->save();
        }
    }

    public function getIdentiteAttribute(): string
    {
        return filled($this->libelle)
            ? "{$this->code} — {$this->libelle}"
            : (string) $this->code;
    }
}
