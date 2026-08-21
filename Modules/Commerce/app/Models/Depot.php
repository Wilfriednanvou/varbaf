<?php

namespace Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\Boutique;
use Modules\Commerce\Enums\StatutDepot;
use Modules\Commerce\Exceptions\DepotFigeException;
use Modules\Commerce\Services\ServiceMouvementStock;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;

/**
 * Document de dépôt : la décharge par laquelle le village reconnaît
 * détenir des biens qui ne lui appartiennent pas.
 *
 * Le journal de stock enregistre les quantités ; ce document engage les
 * deux parties. C'est la pièce qu'on produit en cas de contestation sur
 * un objet disparu — sans elle, ni l'artisan ni le village ne peuvent
 * établir ce qui avait été confié, ni quand.
 *
 * Deux temps :
 *
 * - **Brouillon** : la liste se corrige librement, rien n'est entré en
 *   stock, l'artisan n'a rien signé.
 * - **Validé** : les mouvements d'entrée sont écrits par le service,
 *   les lignes sont figées, le document est imprimable et signable. Le
 *   passage est irréversible — on ne revient pas sur une reconnaissance
 *   signée. Un écart constaté ensuite se corrige par un retrait ou une
 *   contre-passation, qui laissent chacun leur propre trace.
 *
 * @property string $numero
 * @property StatutDepot $statut
 */
class Depot extends Model
{
    protected $table = 'depots';

    /**
     * `numero`, `statut`, `valide_par` et `date_validation` sont
     * absents : le premier est calculé, les trois autres sont constatés
     * à la validation.
     */
    protected $fillable = [
        'date_depot',
        'observations',
        'artisan_id',
        'boutique_id',
        'exercice_id',
    ];

    protected $attributes = [
        'statut' => 'BROUILLON',
    ];

    protected function casts(): array
    {
        return [
            'date_depot' => 'date',
            'date_validation' => 'datetime',
            'statut' => StatutDepot::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $depot): void {
            if (blank($depot->numero)) {
                $depot->numero = static::genererNumero();
            }
        });

        static::updating(function (self $depot): void {
            // Le passage à VALIDE est la seule écriture autorisée sur
            // un dépôt déjà validé — et il n'a lieu qu'une fois.
            //
            // Laravel 12 retourne la valeur castée (l'enum) depuis
            // getOriginal() quand un cast est déclaré. On normalise
            // donc la comparaison pour couvrir les deux cas : enum
            // object et chaîne brute.
            $original = $depot->getOriginal('statut');
            $etaitValide = $original === StatutDepot::VALIDE
                || $original === StatutDepot::VALIDE->value;

            if ($etaitValide) {
                throw DepotFigeException::modification($depot->numero);
            }
        });

        static::deleting(function (self $depot): void {
            if ($depot->estValide()) {
                throw DepotFigeException::suppression($depot->numero);
            }
        });
    }

    /**
     * Numérote les dépôts par année civile : DEP-2026-0001.
     */
    public static function genererNumero(): string
    {
        $prefixe = 'DEP-'.now()->format('Y').'-';

        return DB::transaction(function () use ($prefixe): string {
            $dernier = static::query()
                ->where('numero', 'like', $prefixe.'%')
                ->lockForUpdate()
                ->orderByDesc('numero')
                ->value('numero');

            $rang = $dernier ? ((int) substr($dernier, strlen($prefixe))) + 1 : 1;

            return $prefixe.str_pad((string) $rang, 4, '0', STR_PAD_LEFT);
        });
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneDepot::class, 'depot_id');
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

    public function validePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'valide_par');
    }

    public function scopeValide(Builder $requete): Builder
    {
        return $requete->where('statut', StatutDepot::VALIDE->value);
    }

    public function estValide(): bool
    {
        return $this->statut === StatutDepot::VALIDE;
    }

    public function estModifiable(): bool
    {
        return ! $this->estValide();
    }

    public function nombreArticles(): int
    {
        return (int) $this->lignes()->sum('quantite');
    }

    /**
     * Valide le dépôt : fige les lignes, écrit les entrées au journal
     * de stock, et constate qui a reçu les biens.
     *
     * L'ensemble tient dans une transaction : un dépôt à demi validé —
     * document figé mais stock incomplet — serait pire que pas de
     * dépôt du tout, puisqu'il aurait l'apparence d'une pièce
     * régulière.
     *
     * @throws DepotFigeException
     */
    public function valider(): bool
    {
        if ($this->estValide()) {
            throw DepotFigeException::dejaValide($this->numero);
        }

        if ($this->lignes()->count() === 0) {
            throw DepotFigeException::sansLigne($this->numero);
        }

        return DB::transaction(function (): bool {
            $service = app(ServiceMouvementStock::class);

            foreach ($this->lignes()->with('produit')->get() as $ligne) {
                if (! $ligne->produit) {
                    continue;
                }

                $service->deposer(
                    $ligne->produit,
                    $ligne->quantite,
                    $this,
                    "Dépôt {$this->numero}",
                );
            }

            $this->statut = StatutDepot::VALIDE;
            $this->valide_par = Auth::id();
            $this->date_validation = now();

            return $this->save();
        });
    }
}
