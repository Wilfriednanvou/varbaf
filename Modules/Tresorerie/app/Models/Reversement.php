<?php

namespace Modules\Tresorerie\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Artisanat\Models\Artisan;
use Modules\Tresorerie\Enums\StatutReversement;
use Modules\Tresorerie\Exceptions\CampagneReversementException;

/**
 * Ce qui revient à un artisan au titre d'une campagne (RG-18 à RG-20).
 *
 * **Le solde net peut être négatif ; le montant payé, jamais.** Une
 * vente annulée après avoir été payée revient en reprise, et RG-20 est
 * explicite : aucun décaissement, la dette est reportée sur la campagne
 * suivante jusqu'à absorption. On ne réclame pas d'argent à un artisan
 * au guichet — on retient sur ce qu'il touchera ensuite.
 *
 * **Immuable une fois définitif.** `PAYE` et `REPORTE` ne s'écrivent
 * qu'à la validation de la campagne ; passé ce point, le crochet
 * `updating` refuse toute retouche. La garde porte sur le statut du
 * reversement lui-même et non sur celui de sa campagne : elle reste
 * ainsi vraie quel que soit l'ordre dans lequel le service écrit.
 *
 * @property int $montant_periode
 * @property int $montant_regularisation
 * @property int $montant_paye
 * @property int $solde_reporte
 * @property StatutReversement $statut
 */
class Reversement extends Model
{
    protected $table = 'reversements';

    protected $fillable = [
        'campagne_id',
        'artisan_id',
        'montant_periode',
        'montant_regularisation',
        'montant_paye',
        'solde_reporte',
        'date_paiement',
        'statut',
        'mouvement_caisse_id',
    ];

    protected $attributes = [
        'statut' => 'A_PAYER',
    ];

    protected function casts(): array
    {
        return [
            'montant_periode' => 'integer',
            'montant_regularisation' => 'integer',
            'montant_paye' => 'integer',
            'solde_reporte' => 'integer',
            'date_paiement' => 'datetime',
            'statut' => StatutReversement::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $reversement): void {
            // L'état d'origine, pas l'état courant : c'est la validation
            // qui écrit PAYE ou REPORTE, et elle doit pouvoir aboutir.
            if (static::statutOrigine($reversement)?->estDefinitif()) {
                throw CampagneReversementException::reversementFige();
            }
        });

        static::deleting(function (self $reversement): void {
            // Une préparation se jette ; un décaissement, non.
            //
            // À noter : supprimer la campagne efface ses reversements par
            // cascade en base, sans passer par ce crochet. Ce n'est pas
            // une faille — `CampagneReversement` refuse de supprimer une
            // campagne validée, donc seules des lignes encore à payer
            // peuvent être atteintes par cette cascade.
            if ($reversement->statut->estDefinitif()) {
                throw CampagneReversementException::reversementFige();
            }
        });
    }

    protected static function statutOrigine(self $reversement): ?StatutReversement
    {
        $origine = $reversement->getOriginal('statut');

        if ($origine instanceof StatutReversement) {
            return $origine;
        }

        return $origine === null ? null : StatutReversement::tryFrom((string) $origine);
    }

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(CampagneReversement::class, 'campagne_id');
    }

    public function artisan(): BelongsTo
    {
        return $this->belongsTo(Artisan::class, 'artisan_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneReversement::class, 'reversement_id');
    }

    public function mouvementCaisse(): BelongsTo
    {
        return $this->belongsTo(MouvementCaisse::class, 'mouvement_caisse_id');
    }

    /**
     * Ce que l'artisan aurait dû toucher, report et reprises compris.
     * Négatif quand les annulations dépassent les ventes : c'est le cas
     * que RG-20 traite en ne décaissant rien.
     */
    public function soldeNet(): int
    {
        return $this->montant_periode + $this->montant_regularisation;
    }

    public function estPaye(): bool
    {
        return $this->statut === StatutReversement::PAYE;
    }

    public function estReporte(): bool
    {
        return $this->statut === StatutReversement::REPORTE;
    }
}
