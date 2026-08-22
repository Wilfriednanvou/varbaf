<?php

namespace Modules\Tresorerie\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Commerce\Models\Vente;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Modules\Tresorerie\Enums\StatutCampagneReversement;
use Modules\Tresorerie\Exceptions\CampagneReversementException;

/**
 * Campagne de reversement mensuelle (RG-16 à RG-21).
 *
 * **Deux états, une transition.** En préparation, la campagne est un
 * brouillon : on la recalcule autant de fois qu'on veut, on l'abandonne
 * si elle a été ouverte par erreur. Validée, elle a rattaché ses ventes
 * et décaissé de l'argent — les crochets `updating` et `deleting` la
 * figent, et RG-21 renvoie toute correction sur la campagne suivante.
 *
 * L'écriture ne se fait pas par ce modèle : `ServiceCampagneReversement`
 * est le point d'entrée unique, seul capable de garantir que les
 * rattachements de ventes et les décaissements au brouillard réussissent
 * ou échouent ensemble.
 *
 * @property \Illuminate\Support\Carbon $periode
 * @property \Illuminate\Support\Carbon $date_arrete
 * @property StatutCampagneReversement $statut
 * @property int $montant_total
 * @property int $nombre_beneficiaires
 */
class CampagneReversement extends Model
{
    protected $table = 'campagnes_reversement';

    protected $fillable = [
        'periode',
        'date_arrete',
        'date_generation',
        'montant_total',
        'nombre_beneficiaires',
        'statut',
        'exercice_id',
        'generee_par',
        'validee_par',
        'date_validation',
    ];

    /**
     * L'état de départ est porté par le modèle et pas seulement par le
     * défaut de la colonne : sans cela, une campagne fraîchement créée
     * aurait un `statut` nul en mémoire jusqu'au premier `refresh()`,
     * et les crochets liraient `null` là où ils attendent un état.
     */
    protected $attributes = [
        'statut' => 'EN_PREPARATION',
    ];

    protected function casts(): array
    {
        return [
            'periode' => 'date',
            'date_arrete' => 'date',
            'date_generation' => 'datetime',
            'date_validation' => 'datetime',
            'montant_total' => 'integer',
            'nombre_beneficiaires' => 'integer',
            'statut' => StatutCampagneReversement::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $campagne): void {
            // Le mois se porte toujours par son premier jour. Sans cette
            // normalisation, « août 2026 » saisi le 5 et « août 2026 »
            // saisi le 20 cohabiteraient au lieu d'entrer en collision
            // sur l'index unique — et se disputeraient les mêmes ventes.
            if (filled($campagne->periode)) {
                $campagne->periode = Carbon::parse($campagne->periode)->startOfMonth()->toDateString();
            }
        });

        static::updating(function (self $campagne): void {
            // La comparaison porte sur l'état d'origine, pas sur l'état
            // courant : c'est la validation elle-même qui écrit
            // `statut = VALIDEE`, et elle doit pouvoir aboutir.
            if (static::statutOrigine($campagne) === StatutCampagneReversement::VALIDEE) {
                throw CampagneReversementException::campagneFigee($campagne->libellePeriode());
            }
        });

        static::deleting(function (self $campagne): void {
            if ($campagne->statut === StatutCampagneReversement::VALIDEE) {
                throw CampagneReversementException::campagneFigee($campagne->libellePeriode());
            }
        });
    }

    /**
     * L'état tel qu'il était avant l'écriture en cours. Selon la version
     * de Laravel, `getOriginal()` rend l'enum ou sa valeur brute : les
     * deux sont traités.
     */
    protected static function statutOrigine(self $campagne): ?StatutCampagneReversement
    {
        $origine = $campagne->getOriginal('statut');

        if ($origine instanceof StatutCampagneReversement) {
            return $origine;
        }

        return $origine === null ? null : StatutCampagneReversement::tryFrom((string) $origine);
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(Exercice::class, 'exercice_id');
    }

    public function reversements(): HasMany
    {
        return $this->hasMany(Reversement::class, 'campagne_id');
    }

    /**
     * Les ventes définitivement rattachées par la validation (RG-21).
     * Vide tant que la campagne est en préparation.
     */
    public function ventes(): HasMany
    {
        return $this->hasMany(Vente::class, 'campagne_reversement_id');
    }

    public function genereePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'generee_par');
    }

    public function valideePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'validee_par');
    }

    public function scopeValidee(Builder $requete): Builder
    {
        return $requete->where('statut', StatutCampagneReversement::VALIDEE->value);
    }

    public function estEnPreparation(): bool
    {
        return $this->statut === StatutCampagneReversement::EN_PREPARATION;
    }

    public function estValidee(): bool
    {
        return $this->statut === StatutCampagneReversement::VALIDEE;
    }

    /**
     * « août 2026 » — le mois en toutes lettres, pour les écrans, les
     * messages d'erreur et les états imprimés.
     */
    public function libellePeriode(): string
    {
        return $this->periode
            ? $this->periode->translatedFormat('F Y')
            : '—';
    }

    /**
     * Premier et dernier jour du mois couvert. Une vente située dans cet
     * intervalle relève de la période ; une vente antérieure relève de
     * la régularisation (RG-19).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function bornesDeLaPeriode(): array
    {
        $debut = $this->periode->copy()->startOfMonth();

        return [$debut, $debut->copy()->endOfMonth()];
    }

    public function getIdentiteAttribute(): string
    {
        return "Campagne {$this->libellePeriode()}";
    }
}
