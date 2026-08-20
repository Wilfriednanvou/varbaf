<?php

namespace Modules\Artisanat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Modules\Artisanat\Enums\EtatBoutique;
use Modules\Artisanat\Enums\PeriodiciteRedevance;
use Modules\Artisanat\Enums\StatutAttribution;
use Modules\Artisanat\Exceptions\AttributionChevauchanteException;
use Modules\Artisanat\Exceptions\AttributionInvalideException;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;

/**
 * Occupation d'une boutique par un artisan sur une période donnée.
 *
 * **Quatre règles sont imposées au niveau du modèle**, toutes dans le
 * crochet « saving » et toutes conditionnées au statut ACTIVE :
 *
 * 1. une boutique ne peut porter deux attributions actives qui se
 *    chevauchent ;
 * 2. l'artisan attributaire doit être actif ;
 * 3. l'exercice de rattachement ne doit pas être clôturé ;
 * 4. la boutique ne doit pas être indisponible.
 *
 * Les règles 2 à 4 vivaient dans la ressource Filament — filtre sur le
 * `Select` des artisans, options des `Select` boutique et exercice.
 * Une règle qui ne vit que dans un formulaire n'existe pas pour un
 * seeder, une commande artisan ou tinker : elles ont été déplacées ici.
 *
 * Le conditionnement au statut ACTIVE n'est pas un détail. Sans lui,
 * `resilier()` échouerait sur un contrat dont l'artisan vient d'être
 * désactivé — c'est-à-dire exactement dans le cas où il faut pouvoir
 * résilier.
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

    /**
     * `date_debut_facturation` et `validee_par` sont absentes : la
     * première est calculée, la seconde est constatée à la validation
     * du dossier. Ni l'une ni l'autre ne se saisit.
     */
    protected $fillable = [
        'date_debut',
        'date_fin',
        'redevance_convenue',
        'periodicite',
        'dossier_complet',
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
        'dossier_complet' => false,
    ];

    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_debut_facturation' => 'date',
            'date_fin' => 'date',
            'redevance_convenue' => 'decimal:2',
            'periodicite' => PeriodiciteRedevance::class,
            'dossier_complet' => 'boolean',
            'statut' => StatutAttribution::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $attribution): void {
            $attribution->calculerDateDebutFacturation();
            $attribution->constaterValidationDuDossier();
            $attribution->garantirConditionsDAttribution();
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

    public function valideePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'validee_par');
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
     * Le premier mois d'occupation est gratuit : la facturation ne
     * commence qu'un mois après la date d'entrée (règle 12 de
     * CLAUDE.md).
     *
     * La date est recalculée à chaque écriture plutôt que figée une
     * fois : si la date d'entrée est corrigée après coup — cas courant
     * quand le contrat est saisi en retard — la date de facturation
     * doit suivre, sans quoi le village facturerait un mois qu'il
     * s'était engagé à offrir.
     */
    public function calculerDateDebutFacturation(): void
    {
        $this->date_debut_facturation = $this->date_debut
            ? Carbon::parse($this->date_debut)->copy()->addMonth()->toDateString()
            : null;
    }

    /**
     * Constate qui a validé la complétude du dossier.
     *
     * Le validateur n'est pas choisi dans une liste : il est constaté.
     * C'est l'utilisateur qui coche la case qui engage sa
     * responsabilité, et laisser désigner un tiers ôterait toute
     * valeur à la trace. Décocher la case efface le validateur — le
     * journal d'audit, lui, garde l'historique des deux mouvements.
     *
     * Hors contexte authentifié — seeder, commande artisan — le champ
     * reste nul : personne n'a validé.
     */
    public function constaterValidationDuDossier(): void
    {
        if (! $this->dossier_complet) {
            $this->validee_par = null;

            return;
        }

        if (blank($this->validee_par)) {
            $this->validee_par = Auth::id();
        }
    }

    /**
     * Artisan actif, exercice ouvert, boutique disponible.
     *
     * Les trois entités sont relues par leur clé plutôt que par la
     * relation : après un changement de `artisan_id`, `boutique_id` ou
     * `exercice_id` sur un enregistrement déjà chargé, Eloquent
     * conserve la relation mise en cache et contrôlerait alors
     * l'ancienne valeur.
     *
     * Une entité introuvable ne déclenche rien : la contrainte de clé
     * étrangère de la base refusera l'écriture avec un message plus
     * juste que celui qu'on inventerait ici.
     *
     * @throws AttributionInvalideException
     */
    public function garantirConditionsDAttribution(): void
    {
        if ($this->statut !== StatutAttribution::ACTIVE) {
            return;
        }

        $artisan = filled($this->artisan_id) ? Artisan::find($this->artisan_id) : null;

        if ($artisan && ! $artisan->actif) {
            throw AttributionInvalideException::artisanInactif($artisan->identite);
        }

        $exercice = filled($this->exercice_id) ? Exercice::find($this->exercice_id) : null;

        if ($exercice && $exercice->cloture) {
            throw AttributionInvalideException::exerciceCloture($exercice->libelle);
        }

        $boutique = filled($this->boutique_id) ? Boutique::find($this->boutique_id) : null;

        if ($boutique && $boutique->etat === EtatBoutique::INDISPONIBLE) {
            throw AttributionInvalideException::boutiqueIndisponible($boutique->numero);
        }
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
