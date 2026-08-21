<?php

namespace Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\Boutique;
use Modules\Commerce\Enums\EtatVente;
use Modules\Commerce\Enums\ModeReglement;
use Modules\Commerce\Enums\ProvenanceClient;
use Modules\Commerce\Exceptions\VenteInvalideException;
use Modules\Socle\Models\Agent;
use Modules\Socle\Models\Exercice;

/**
 * Vente au comptoir.
 *
 * **Tout est figé à l'enregistrement (RG-10)** : montants, taux de
 * commission, boutique, artisan. Ces valeurs ne sont jamais recalculées
 * depuis les référentiels. Un reçu réimprimé dans deux ans doit montrer
 * l'opération telle qu'elle a eu lieu, pas telle qu'elle se lirait
 * aujourd'hui.
 *
 * Le modèle refuse toute modification d'une vente enregistrée : seul le
 * passage à ANNULEE est permis, et il passe par `ServiceVente` qui
 * contre-passe le stock et la caisse. La vente elle-même n'est jamais
 * supprimée.
 *
 * L'écriture d'une vente ne se fait pas par ce modèle : `ServiceVente`
 * est le point d'entrée unique, seul capable de garantir que les lignes,
 * les mouvements de stock et l'écriture au brouillard réussissent ou
 * échouent ensemble.
 *
 * @property string $numero
 * @property string $montant_total
 * @property string $taux_commission
 * @property string $montant_commission
 * @property string $part_artisan
 * @property EtatVente $etat
 */
class Vente extends Model
{
    protected $table = 'ventes';

    /**
     * `numero` et les montants sont inclus pour que le hook `updating`
     * puisse les détecter comme modifiés et lever l'exception adéquate.
     * Le service les écrit par affectation directe de propriétés (hors
     * fillable), donc les protéger ici n'empêche pas leur initialisation.
     */
    protected $fillable = [
        'date_vente',
        'mode_reglement',
        'nom_client',
        'contact_client',
        'accepte_notifications',
        'provenance_client',
        'boutique_id',
        'artisan_id',
        'exercice_id',
        // Champs figés : listés pour que l'updating hook les voit comme
        // dirty si quelqu'un tente de les modifier après enregistrement.
        'numero',
        'vendeur_id',
        'montant_total',
        'taux_commission',
        'montant_commission',
        'part_artisan',
    ];

    protected $attributes = [
        'etat' => 'VALIDEE',
        'mode_reglement' => 'ESPECES',
    ];

    protected function casts(): array
    {
        return [
            'date_vente' => 'datetime',
            'date_annulation' => 'datetime',
            'montant_total' => 'decimal:2',
            'taux_commission' => 'decimal:2',
            'montant_commission' => 'decimal:2',
            'part_artisan' => 'decimal:2',
            'accepte_notifications' => 'boolean',
            'mode_reglement' => ModeReglement::class,
            'provenance_client' => ProvenanceClient::class,
            'etat' => EtatVente::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $vente): void {
            if (blank($vente->numero)) {
                $vente->numero = static::genererNumero();
            }
        });

        static::updating(function (self $vente): void {
            // Une seule écriture est tolérée sur une vente
            // enregistrée : son annulation. Tout le reste est figé.
            $champsAutorises = ['etat', 'motif_annulation', 'date_annulation', 'campagne_reversement_id', 'section_caisse_id'];

            $champsTouches = array_keys($vente->getDirty());
            $champsInterdits = array_diff($champsTouches, $champsAutorises);

            if ($champsInterdits !== []) {
                throw VenteInvalideException::figee($vente->numero);
            }
        });

        static::deleting(function (self $vente): void {
            throw VenteInvalideException::figee($vente->numero);
        });
    }

    /**
     * Numéro de ticket, séquentiel par année : VTE-2026-000001.
     *
     * Six chiffres, et non quatre : le registre transcrit laisse
     * attendre plusieurs milliers de ventes par exercice.
     */
    public static function genererNumero(): string
    {
        $prefixe = 'VTE-'.now()->format('Y').'-';

        return DB::transaction(function () use ($prefixe): string {
            $dernier = static::query()
                ->where('numero', 'like', $prefixe.'%')
                ->lockForUpdate()
                ->orderByDesc('numero')
                ->value('numero');

            $rang = $dernier ? ((int) substr($dernier, strlen($prefixe))) + 1 : 1;

            return $prefixe.str_pad((string) $rang, 6, '0', STR_PAD_LEFT);
        });
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneVente::class, 'vente_id');
    }

    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class, 'boutique_id');
    }

    public function artisan(): BelongsTo
    {
        return $this->belongsTo(Artisan::class, 'artisan_id');
    }

    public function vendeur(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'vendeur_id');
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(Exercice::class, 'exercice_id');
    }

    public function scopeValidee(Builder $requete): Builder
    {
        return $requete->where('etat', EtatVente::VALIDEE->value);
    }

    /**
     * Ventes retenues par une campagne de reversement (RG-17) :
     * validées et non encore rattachées à une campagne validée.
     */
    public function scopeAReverser(Builder $requete): Builder
    {
        return $requete->validee()->whereNull('campagne_reversement_id');
    }

    public function estValidee(): bool
    {
        return $this->etat === EtatVente::VALIDEE;
    }

    public function estAnnulee(): bool
    {
        return $this->etat === EtatVente::ANNULEE;
    }

    public function nombreArticles(): int
    {
        return (int) $this->lignes()->sum('quantite');
    }

    public function libelleClient(): string
    {
        return filled($this->nom_client) ? $this->nom_client : 'Client de passage';
    }
}
