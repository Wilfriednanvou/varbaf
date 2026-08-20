<?php

namespace Modules\Artisanat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Artisanat\Enums\PeriodiciteRedevance;
use Modules\Artisanat\Enums\StatutAttribution;
use Modules\Artisanat\Exceptions\AttributionChevauchanteException;
use Modules\Socle\Models\Exercice;

/**
 * Occupation d'une boutique par un artisan sur une période donnée.
 *
 * **Règle imposée au niveau du modèle : une boutique ne peut porter
 * deux attributions actives qui se chevauchent.** Le contrôle est
 * placé dans le crochet « saving » et non dans la ressource Filament,
 * afin qu'il s'applique aussi aux seeders, aux commandes artisan et à
 * tinker. La ressource ajoute par-dessus une règle de formulaire qui
 * produit le même verdict, pour que l'utilisateur voie un message sous
 * le champ au lieu d'une exception.
 *
 * Deux attributions se chevauchent lorsque chacune commence avant que
 * l'autre ne finisse. Une date de fin nulle vaut « sans terme » et
 * repousse la borne à l'infini : c'est le cas le plus fréquent au
 * village, et celui qui piège les contrôles naïfs.
 *
 * Les attributions RESILIEE et TERMINEE ne bloquent rien : une
 * boutique libérée doit pouvoir être réattribuée sur la même période
 * que le contrat rompu.
 *
 * @property int $id
 * @property Carbon $date_debut
 * @property Carbon|null $date_fin
 * @property string $redevance_convenue
 * @property PeriodiciteRedevance $periodicite
 * @property StatutAttribution $statut
 * @property int $artisan_id
 * @property int $boutique_id
 * @property int $exercice_id
 */
class AttributionBoutique extends Model
{
    protected $table = 'attributions_boutiques';

    protected $fillable = [
        'date_debut',
        'date_fin',
        'redevance_convenue',
        'periodicite',
        'statut',
        'motif_resiliation',
        'artisan_id',
        'boutique_id',
        'exercice_id',
    ];

    /**
     * Valeurs par défaut portées par le modèle et non seulement par la
     * base.
     *
     * Ce n'est pas une redondance : la ressource Filament n'envoie pas
     * le statut à la création — il n'évolue que par les actions
     * « Résilier » et « Terminer ». Sans ce défaut, une attribution
     * neuve arriverait dans le crochet « saving » avec un statut nul,
     * et le contrôle de chevauchement, qui ne s'applique qu'aux
     * attributions actives, serait purement et simplement sauté.
     */
    protected $attributes = [
        'statut' => 'ACTIVE',
        'periodicite' => 'MENSUELLE',
    ];

    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin' => 'date',
            'redevance_convenue' => 'decimal:2',
            'periodicite' => PeriodiciteRedevance::class,
            'statut' => StatutAttribution::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $attribution): void {
            $attribution->garantirAbsenceDeChevauchement();
        });

        // La synchronisation vit dans « saved » et non dans « saving » :
        // tant que la ligne n'est pas écrite, la requête d'occupation
        // ne la verrait pas et l'état calculé serait faux.
        static::saved(fn (self $attribution) => $attribution->synchroniserBoutiquesImpactees());

        static::deleted(fn (self $attribution) => $attribution->synchroniserBoutiquesImpactees());
    }

    public function artisan(): BelongsTo
    {
        return $this->belongsTo(Artisan::class, 'artisan_id');
    }

    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class, 'boutique_id');
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(Exercice::class, 'exercice_id');
    }

    public function scopeActive(Builder $requete): Builder
    {
        return $requete->where('statut', StatutAttribution::ACTIVE->value);
    }

    /**
     * Cœur de la règle : existe-t-il une attribution active de la même
     * boutique dont la période recouvre celle proposée ?
     *
     * @param  int|null  $ignorerId  Attribution à exclure — la ligne
     *                               en cours de modification, qui se
     *                               chevaucherait sinon elle-même.
     */
    public static function existeChevauchement(
        int $boutiqueId,
        string|Carbon $dateDebut,
        string|Carbon|null $dateFin = null,
        ?int $ignorerId = null,
    ): bool {
        return static::requeteChevauchement($boutiqueId, $dateDebut, $dateFin, $ignorerId)->exists();
    }

    public static function requeteChevauchement(
        int $boutiqueId,
        string|Carbon $dateDebut,
        string|Carbon|null $dateFin = null,
        ?int $ignorerId = null,
    ): Builder {
        $debut = Carbon::parse($dateDebut)->toDateString();
        $fin = $dateFin ? Carbon::parse($dateFin)->toDateString() : null;

        return static::query()
            ->where('boutique_id', $boutiqueId)
            ->where('statut', StatutAttribution::ACTIVE->value)
            ->when($ignorerId, fn (Builder $requete) => $requete->whereKeyNot($ignorerId))
            // L'existante commence avant la fin de la nouvelle.
            // Si la nouvelle n'a pas de terme, la condition est
            // toujours vraie : rien à filtrer.
            ->when($fin, fn (Builder $requete) => $requete->whereDate('date_debut', '<=', $fin))
            // L'existante finit après le début de la nouvelle, ou
            // n'a pas de terme du tout.
            ->where(fn (Builder $requete) => $requete
                ->whereNull('date_fin')
                ->orWhereDate('date_fin', '>=', $debut));
    }

    /**
     * @throws AttributionChevauchanteException
     */
    public function garantirAbsenceDeChevauchement(): void
    {
        // Une attribution résiliée ou terminée ne réserve plus rien :
        // inutile de la confronter aux autres.
        if ($this->statut !== StatutAttribution::ACTIVE) {
            return;
        }

        // Boutique ou date manquante : la validation du formulaire ou
        // la contrainte de non-nullité s'en chargera. Comparer des
        // périodes incomplètes ne produirait qu'un faux verdict.
        if (blank($this->boutique_id) || blank($this->date_debut)) {
            return;
        }

        $chevauchement = static::requeteChevauchement(
            (int) $this->boutique_id,
            $this->date_debut,
            $this->date_fin,
            $this->exists ? (int) $this->getKey() : null,
        )->first();

        if (! $chevauchement) {
            return;
        }

        throw AttributionChevauchanteException::pour(
            $this->boutique?->numero ?? (string) $this->boutique_id,
            $chevauchement->libellePeriode(),
        );
    }

    /**
     * Réaligne l'état des boutiques touchées par ce mouvement.
     *
     * Deux boutiques peuvent être concernées quand l'attribution a été
     * déplacée d'un local à un autre : celle qu'elle quitte doit
     * redevenir disponible, sinon elle resterait occupée à vide.
     * L'événement « saved » se déclenche avant syncOriginal(), la
     * valeur d'origine est donc encore lisible.
     */
    protected function synchroniserBoutiquesImpactees(): void
    {
        $identifiants = array_filter(array_unique([
            (int) $this->boutique_id,
            (int) $this->getOriginal('boutique_id'),
        ]));

        Boutique::query()
            ->whereKey($identifiants)
            ->get()
            ->each(fn (Boutique $boutique) => $boutique->synchroniserEtat());
    }

    public function libellePeriode(): string
    {
        $debut = $this->date_debut?->format('d/m/Y') ?? '?';
        $fin = $this->date_fin?->format('d/m/Y') ?? 'sans terme';

        return "{$debut} → {$fin}";
    }

    /**
     * L'attribution est-elle active ET la date du jour tombe-t-elle
     * dans sa période ? Une attribution future est active sans être
     * en cours.
     */
    public function estEnCours(): bool
    {
        if ($this->statut !== StatutAttribution::ACTIVE || $this->date_debut === null) {
            return false;
        }

        $aujourdhui = now()->startOfDay();

        return $this->date_debut->lessThanOrEqualTo($aujourdhui)
            && ($this->date_fin === null || $this->date_fin->greaterThanOrEqualTo($aujourdhui));
    }

    /**
     * Rupture avant terme : clôture l'attribution et libère la boutique.
     */
    public function resilier(string $motif): bool
    {
        if ($this->statut !== StatutAttribution::ACTIVE) {
            return false;
        }

        $this->statut = StatutAttribution::RESILIEE;
        $this->motif_resiliation = $motif;
        $this->date_fin = $this->date_fin ?? now()->toDateString();

        return $this->save();
    }

    /**
     * Arrivée normale au terme convenu.
     */
    public function terminer(): bool
    {
        if ($this->statut !== StatutAttribution::ACTIVE) {
            return false;
        }

        $this->statut = StatutAttribution::TERMINEE;
        $this->date_fin = $this->date_fin ?? now()->toDateString();

        return $this->save();
    }
}
