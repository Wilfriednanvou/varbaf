<?php

namespace Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\Boutique;
use Modules\Commerce\Enums\StatutParticipationProduit;
use Modules\Commerce\Enums\StatutValidationProduit;
use Modules\Commerce\Exceptions\ProduitInvalideException;
use Modules\Commerce\Services\ServiceMouvementStock;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;

/**
 * Produit artisanal déposé au village.
 *
 * **Aucune quantité stockée.** La règle 3 de CLAUDE.md fait du stock un
 * solde calculé depuis `mouvements_stock`. `getQuantiteEnStock()`
 * délègue au service du journal, qui recalcule le cumul à chaque
 * appel : aucune colonne ne peut donc diverger silencieusement du
 * journal, faute d'exister.
 *
 * **Référence figée (RG-09).** Produite à la création à partir du
 * numéro de boutique et d'un compteur, puis immuable, y compris si le
 * produit change de boutique. C'est un identifiant, pas une adresse :
 * elle figure sur l'étiquette physique collée sur l'objet, sur le reçu
 * de vente et sur l'état de reversement. Si elle suivait les
 * déplacements, toutes les étiquettes déjà imprimées deviendraient
 * fausses et les pièces justificatives passées cesseraient d'être
 * rapprochables.
 *
 * @property int $id
 * @property string $reference
 * @property string $designation
 * @property string $prix_unitaire
 * @property int|null $seuil_alerte
 * @property array<int, array{rubrique: string, contenu: string}>|null $caracteristiques
 * @property string|null $fiche_technique
 * @property StatutValidationProduit $statut_validation
 * @property int|null $valide_par
 * @property \Illuminate\Support\Carbon|null $valide_le
 * @property bool $piece_unique
 * @property bool $actif
 */
class Produit extends Model
{
    protected $table = 'produits';

    /**
     * `reference`, `statut_validation`, `valide_par` et `valide_le` sont
     * absents : la référence et le statut n'évoluent que par les
     * actions dédiées, et l'identité du validateur se constate — via
     * `ServiceValidationProduit` — elle ne se saisit jamais.
     */
    protected $fillable = [
        'designation',
        'description',

        // Les rubriques de la fiche technique, telles que l'artisan les
        // a ecrites. La forme varie d'un metier a l'autre : la colonne
        // est un jsonb, pas un jeu de colonnes. Voir la migration.
        'caracteristiques',

        'prix_unitaire',
        'seuil_alerte',
        'piece_unique',
        'photo',
        'fiche_technique',
        'actif',
        'categorie_id',
        'artisan_id',
        'boutique_id',
    ];

    /**
     * `actif` vaut `true` par défaut en base, mais reste `null` en
     * mémoire pour un attribut absent du tableau de création — voir le
     * même motif sur `Artisan`, dont dépend `created()` ci-dessous.
     */
    protected $attributes = [
        'statut_validation' => 'SOUMIS',
        'actif' => true,
    ];

    protected function casts(): array
    {
        return [
            'prix_unitaire' => 'decimal:2',
            'caracteristiques' => 'array',
            'seuil_alerte' => 'integer',
            'statut_validation' => StatutValidationProduit::class,
            'valide_le' => 'datetime',
            'piece_unique' => 'boolean',
            'actif' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $produit): void {
            if (blank($produit->reference)) {
                $produit->reference = static::genererReference((int) $produit->boutique_id);
            }
        });

        static::updating(function (self $produit): void {
            if ($produit->isDirty('reference')) {
                throw ProduitInvalideException::referenceFigee((string) $produit->getOriginal('reference'));
            }

            $produit->garantirTransitionValide();
        });

        // Même motif que sur Artisan::created() — voir son commentaire.
        static::created(function (self $produit): void {
            if ($exercice = Exercice::courant()) {
                ProduitExercice::create([
                    'produit_id' => $produit->id,
                    'exercice_id' => $exercice->id,
                    'statut' => $produit->actif
                        ? StatutParticipationProduit::ACTIF
                        : StatutParticipationProduit::DESACTIVE,
                ]);
            }
        });
    }

    /**
     * Produit la référence suivante pour une boutique : BTQ12-0043.
     *
     * Le préfixe est tiré du numéro de boutique — « B-12 » donne
     * « BTQ12 » — et le compteur est propre à la boutique, ce qui rend
     * la référence lisible à l'œil sur une étagère.
     */
    public static function genererReference(int $boutiqueId): string
    {
        $boutique = Boutique::find($boutiqueId);
        $numero = $boutique?->numero ?? '';

        // On ne garde que les chiffres du numéro de boutique, pour que
        // « B-01 », « B01 » ou « 1 » produisent tous « BTQ01 ».
        $chiffres = preg_replace('/\D/', '', $numero) ?: '00';
        $prefixe = 'BTQ'.str_pad($chiffres, 2, '0', STR_PAD_LEFT).'-';

        return DB::transaction(function () use ($prefixe): string {
            $derniere = static::query()
                ->where('reference', 'like', $prefixe.'%')
                ->lockForUpdate()
                ->orderByDesc('reference')
                ->value('reference');

            $rang = $derniere ? ((int) substr($derniere, strlen($prefixe))) + 1 : 1;

            return $prefixe.str_pad((string) $rang, 4, '0', STR_PAD_LEFT);
        });
    }

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(CategorieProduit::class, 'categorie_id');
    }

    public function artisan(): BelongsTo
    {
        return $this->belongsTo(Artisan::class, 'artisan_id');
    }

    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class, 'boutique_id');
    }

    public function validePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'valide_par');
    }

    /**
     * Produits proposables au comptoir d'une boutique : c'est la
     * requête que l'écran de vente utilisera (RG-09 bis).
     */
    public function scopeVendable(Builder $requete): Builder
    {
        return $requete
            ->where('actif', true)
            ->whereIn('statut_validation', [
                StatutValidationProduit::VALIDE->value,
                StatutValidationProduit::EXPOSE->value,
            ]);
    }

    /**
     * Produits publiables sur le portail public.
     *
     * Double condition : le produit doit être exposé **et** l'artisan
     * doit avoir donné son autorisation de publication. Le consentement
     * appartient à l'artisan, la mise en vitrine à la section
     * Promotion — l'un ne vaut pas l'autre.
     */
    public function scopePubliable(Builder $requete): Builder
    {
        return $requete
            ->where('actif', true)
            ->where('statut_validation', StatutValidationProduit::EXPOSE->value)
            ->whereHas('artisan', fn (Builder $sousRequete) => $sousRequete->where('autorisation_publication', true));
    }

    public function estVendable(): bool
    {
        return $this->actif && $this->statut_validation->estVendable();
    }

    public function estPubliable(): bool
    {
        return $this->actif
            && $this->statut_validation->estPubliable()
            && (bool) $this->artisan?->autorisation_publication;
    }

    /**
     * @throws ProduitInvalideException
     */
    public function garantirTransitionValide(): void
    {
        if (! $this->isDirty('statut_validation')) {
            return;
        }

        $origine = $this->getOriginal('statut_validation');
        $depuis = $origine instanceof StatutValidationProduit
            ? $origine
            : StatutValidationProduit::tryFrom((string) $origine);

        $vers = $this->statut_validation;

        if (! $depuis || ! $vers) {
            return;
        }

        if (! $depuis->peutDevenir($vers)) {
            throw ProduitInvalideException::transitionInterdite($depuis->getLabel(), $vers->getLabel());
        }
    }

    public function changerStatut(StatutValidationProduit $cible): bool
    {
        $this->statut_validation = $cible;

        return $this->save();
    }

    public function mouvementsStock(): HasMany
    {
        return $this->hasMany(MouvementStock::class, 'produit_id');
    }

    public function lignesDepot(): HasMany
    {
        return $this->hasMany(LigneDepot::class, 'produit_id');
    }

    public function participationsExercices(): HasMany
    {
        return $this->hasMany(ProduitExercice::class, 'produit_id');
    }

    /**
     * Quantité en stock : cumul du journal, jamais une colonne.
     *
     * Recalculée à chaque appel. C'est volontairement le chemin le plus
     * coûteux et le plus sûr : sur un catalogue de quelques centaines
     * d'articles, la dépense est nulle, et aucune valeur mémorisée ne
     * peut prendre le pas sur le journal.
     */
    public function getQuantiteEnStock(): int
    {
        return app(ServiceMouvementStock::class)->solde($this);
    }

    /**
     * Le produit est-il disponible dans la quantité demandée ?
     *
     * Deux conditions distinctes : être vendable — actif et validé — et
     * être physiquement présent. Un produit validé dont le stock est
     * épuisé n'est pas disponible ; un produit en stock mais non validé
     * ne l'est pas davantage.
     */
    public function estDisponible(int $quantite = 1): bool
    {
        return $this->estVendable() && $this->getQuantiteEnStock() >= $quantite;
    }

    public function estSousLeSeuil(): bool
    {
        return $this->seuil_alerte !== null
            && $this->getQuantiteEnStock() <= $this->seuil_alerte;
    }

    /**
     * Produits surveillés dont le stock est retombé au niveau du seuil.
     *
     * Le solde est recalculé en SQL par une sous-requête : c'est le
     * même cumul que `getQuantiteEnStock()`, exprimé de façon à rester
     * filtrable et triable par la base — ce dont l'écran du catalogue
     * et le compteur de navigation ont besoin.
     */
    public function scopeSousLeSeuil(Builder $requete): Builder
    {
        $cumul = 'coalesce((select sum(case when sens = \'ENTREE\' then quantite else -quantite end)'
            .' from mouvements_stock where mouvements_stock.produit_id = produits.id), 0)';

        return $requete
            ->where('actif', true)
            ->whereNotNull('seuil_alerte')
            ->whereExists(function ($sousRequete): void {
                $sousRequete->selectRaw('1')
                    ->from('mouvements_stock')
                    ->whereColumn('mouvements_stock.produit_id', 'produits.id');
            })
            ->whereRaw("{$cumul} <= produits.seuil_alerte");
    }

    public function getIdentiteAttribute(): string
    {
        return "{$this->reference} — {$this->designation}";
    }
}
