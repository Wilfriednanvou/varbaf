<?php

namespace Modules\Portail\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Commerce\Models\Produit;
use Modules\Portail\Exceptions\PublicationPortailException;
use Modules\Socle\Models\Utilisateur;

/**
 * Fiche portail d'un produit (module 6 de `docs/modele-classes.md`).
 *
 * **La garde vit ici, pas dans l'écran.** Publier un produit qui n'est
 * pas exposé, ou dont l'artisan n'a pas donné son autorisation, lève une
 * exception — qu'on passe par le panneau, par un seeder ou par une
 * commande. Une règle appliquée seulement à l'écran est contournée dès
 * la première commande en console.
 *
 * **Deux verrous distincts, et c'est voulu.** `publie` est la décision
 * de mise en ligne ; `statut_validation = EXPOSE` est la porte d'entrée,
 * décidée par la section Promotion. Retirer un produit de la vitrine
 * (EXPOSE → VALIDE) le dépublie donc de fait, sans qu'aucune fiche
 * n'ait à être modifiée — c'est le scope `visible()` qui le constate à
 * chaque lecture.
 *
 * @property bool $publie
 * @property int $ordre_affichage
 */
class PublicationProduit extends Model
{
    protected $table = 'publications_produit';

    protected $fillable = [
        'produit_id',
        'publie',
        'photo',
        'description_commerciale',
        'ordre_affichage',
        'publie_par',
        'date_publication',
    ];

    /**
     * Non publié par défaut. Créer la fiche ne met rien en ligne :
     * c'est un brouillon qu'on prépare, puis qu'on publie.
     */
    protected $attributes = [
        'publie' => false,
        'ordre_affichage' => 0,
    ];

    protected function casts(): array
    {
        return [
            'publie' => 'boolean',
            'ordre_affichage' => 'integer',
            'date_publication' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $publication): void {
            if (! $publication->publie) {
                return;
            }

            $produit = $publication->produit;

            if (! $produit) {
                return;
            }

            // Le statut du produit est la porte : seul EXPOSE l'ouvre.
            if (! $produit->statut_validation?->estPubliable() || ! $produit->actif) {
                throw PublicationPortailException::produitNonExpose(
                    $produit->designation ?? "#{$publication->produit_id}"
                );
            }

            // L'autorisation appartient à l'artisan, et rien ne la
            // supplée : ni la mise en vitrine, ni la décision de
            // publier.
            if (! $produit->artisan?->autorisation_publication) {
                throw PublicationPortailException::artisanSansAutorisation(
                    $produit->artisan?->nom_complet ?? 'inconnu'
                );
            }
        });
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }

    public function publiePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'publie_par');
    }

    /**
     * Les fiches réellement visibles du public.
     *
     * Le drapeau ne suffit pas : le produit doit être exposé et actif,
     * et son artisan doit toujours autoriser la publication. Les trois
     * conditions sont revérifiées à chaque lecture, de sorte qu'un
     * retrait de vitrine ou une autorisation retirée prennent effet
     * immédiatement, sans passage de nettoyage.
     */
    public function scopeVisible(Builder $requete): Builder
    {
        return $requete
            ->where('publications_produit.publie', true)
            ->whereHas('produit', fn (Builder $sousRequete) => $sousRequete->publiable());
    }

    public function estVisible(): bool
    {
        return $this->publie
            && (bool) $this->produit?->estPubliable();
    }

    /**
     * La photo de la vitrine, à défaut celle du produit.
     */
    public function photoAffichee(): ?string
    {
        return $this->photo ?: $this->produit?->photo;
    }

    /**
     * Le texte de la vitrine, à défaut la description de gestion.
     */
    public function descriptionAffichee(): ?string
    {
        return filled($this->description_commerciale)
            ? $this->description_commerciale
            : $this->produit?->description;
    }
}
