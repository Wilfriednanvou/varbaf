<?php

namespace Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Modules\Commerce\Exceptions\AucunTauxCommissionException;
use Modules\Commerce\Exceptions\TauxCommissionFigeException;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;

/**
 * Taux de commission prélevé par le village sur les ventes (RG-11).
 *
 * Uniforme pour tous les artisans, historisé par date d'effet. Ce n'est
 * pas un paramètre que l'on écrase : c'est une suite d'actes datés, qui
 * doit permettre de rejouer des années plus tard le calcul d'une
 * commission ancienne.
 *
 * Deux verrous portés par le modèle :
 *
 * 1. Un taux **entré en vigueur** ne se modifie ni ne se supprime. Le
 *    retoucher réécrirait rétroactivement des commissions déjà
 *    calculées, encaissées, parfois reversées. La correction passe par
 *    un nouveau taux daté, jamais par la retouche de l'ancien. Tant que
 *    la date d'effet est future, en revanche, une erreur de saisie se
 *    corrige librement.
 * 2. `getTauxEnVigueur()` **lève une exception** quand la table est
 *    vide à la date demandée, au lieu de renvoyer zéro. Voir
 *    AucunTauxCommissionException pour le raisonnement.
 *
 * @property int $id
 * @property string $taux Pourcentage : 15.00 vaut quinze pour cent
 * @property Carbon $date_effet
 * @property string|null $reference_decision
 * @property int|null $saisi_par
 * @property int $village_id
 */
class TauxCommission extends Model
{
    protected $table = 'taux_commissions';

    /**
     * `saisi_par` est absent : il est constaté, pas choisi.
     */
    protected $fillable = [
        'taux',
        'date_effet',
        'reference_decision',
        'village_id',
    ];

    protected function casts(): array
    {
        return [
            'taux' => 'decimal:2',
            'date_effet' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $tauxCommission): void {
            if (blank($tauxCommission->saisi_par)) {
                $tauxCommission->saisi_par = Auth::id();
            }
        });

        static::updating(function (self $tauxCommission): void {
            // On compare la date d'effet d'origine, pas la nouvelle :
            // sinon il suffirait de repousser la date dans le futur
            // pour déverrouiller un taux déjà appliqué.
            if (self::dateEstEntreeEnVigueur($tauxCommission->getOriginal('date_effet'))) {
                throw TauxCommissionFigeException::modification($tauxCommission->libelle());
            }
        });

        static::deleting(function (self $tauxCommission): void {
            if (self::dateEstEntreeEnVigueur($tauxCommission->getOriginal('date_effet'))) {
                throw TauxCommissionFigeException::suppression($tauxCommission->libelle());
            }
        });
    }

    protected static function dateEstEntreeEnVigueur(mixed $dateEffet): bool
    {
        if (blank($dateEffet)) {
            return false;
        }

        return Carbon::parse($dateEffet)->startOfDay()->lessThanOrEqualTo(now()->startOfDay());
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(VillageArtisanal::class, 'village_id');
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'saisi_par');
    }

    public function scopeEnVigueurAu(Builder $requete, string|Carbon $date): Builder
    {
        return $requete
            ->whereDate('date_effet', '<=', Carbon::parse($date)->toDateString())
            ->orderByDesc('date_effet')
            ->orderByDesc('id');
    }

    /**
     * Taux applicable à une date donnée.
     *
     * Point d'entrée unique du calcul de commission : aucun module ne
     * requête cette table directement.
     *
     * @throws AucunTauxCommissionException si aucun taux n'a pris effet
     *                                      à cette date
     */
    public static function getTauxEnVigueur(string|Carbon|null $date = null, ?int $villageId = null): string
    {
        return static::enregistrementEnVigueur($date, $villageId)->taux;
    }

    /**
     * @throws AucunTauxCommissionException
     */
    public static function enregistrementEnVigueur(string|Carbon|null $date = null, ?int $villageId = null): self
    {
        $dateReference = $date ? Carbon::parse($date) : now();

        $taux = static::query()
            ->when($villageId, fn (Builder $requete) => $requete->where('village_id', $villageId))
            ->enVigueurAu($dateReference)
            ->first();

        if (! $taux) {
            throw AucunTauxCommissionException::aLaDate($dateReference->format('d/m/Y'));
        }

        return $taux;
    }

    /**
     * Y a-t-il un taux applicable ? À utiliser pour afficher un
     * avertissement, jamais pour se passer du taux.
     */
    public static function existeUnTauxEnVigueur(string|Carbon|null $date = null, ?int $villageId = null): bool
    {
        try {
            static::enregistrementEnVigueur($date, $villageId);

            return true;
        } catch (AucunTauxCommissionException) {
            return false;
        }
    }

    /**
     * Applique le taux à un montant : `montant × taux / 100` (RG-12).
     */
    public function commissionSur(float|string $montant): float
    {
        return round((float) $montant * (float) $this->taux / 100, 2);
    }

    public function estEntreEnVigueur(): bool
    {
        return self::dateEstEntreeEnVigueur($this->date_effet);
    }

    public function estModifiable(): bool
    {
        return ! $this->estEntreEnVigueur();
    }

    /**
     * Vrai si ce taux est celui actuellement utilisé pour les nouvelles ventes du village.
     */
    public function estLeTauxActuel(): bool
    {
        if (! $this->estEntreEnVigueur()) {
            return false;
        }

        try {
            return static::enregistrementEnVigueur(null, $this->village_id)->id === $this->id;
        } catch (AucunTauxCommissionException) {
            return false;
        }
    }

    public function libelle(): string
    {
        $taux = rtrim(rtrim(number_format((float) $this->taux, 2, ',', ' '), '0'), ',');

        return "{$taux} % au ".($this->date_effet?->format('d/m/Y') ?? '?');
    }
}
