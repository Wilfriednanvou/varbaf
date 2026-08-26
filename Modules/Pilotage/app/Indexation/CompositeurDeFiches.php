<?php

namespace Modules\Pilotage\Indexation;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Modules\Artisanat\Models\Artisan;
use Modules\Commerce\Models\Produit;
use Modules\Pilotage\Enums\TypeFicheLexicale;

/**
 * Fabrique les fiches textuelles du corpus depuis les modèles métier.
 *
 * **Le seul endroit du Pilotage qui connaisse le Commerce et
 * l'Artisanat.** Tout ce qui suit — tokenisation, pondération, calcul
 * des poids, similarité — ne manipule plus que des `FicheComposee` et
 * ignore d'où elles viennent. C'est ce qui permettra à une branche dense
 * de réutiliser exactement le même corpus sans retoucher une ligne ici.
 *
 * La dépendance reste descendante : le Pilotage lit le Commerce et
 * l'Artisanat, aucun des deux ne le connaît.
 */
class CompositeurDeFiches
{
    /**
     * Fiches produit, en flux.
     *
     * `LazyCollection` et non `Collection` : le catalogue du village
     * compte huit cents produits aujourd'hui, mais rien ne garantit
     * qu'il en comptera huit cents demain, et une réindexation ne doit
     * pas dépendre de la taille du catalogue pour tenir en mémoire.
     *
     * @return LazyCollection<int, FicheComposee>
     */
    public function produits(): LazyCollection
    {
        return Produit::query()
            ->with(['categorie.parent', 'artisan.corpsMetier'])
            ->orderBy('id')
            ->lazy(200)
            ->map(fn (Produit $produit): FicheComposee => $this->pourProduit($produit));
    }

    /**
     * @return LazyCollection<int, FicheComposee>
     */
    public function artisans(): LazyCollection
    {
        return Artisan::query()
            ->with(['corpsMetier'])
            ->orderBy('id')
            ->lazy(200)
            ->map(fn (Artisan $artisan): FicheComposee => $this->pourArtisan($artisan));
    }

    /**
     * @return LazyCollection<int, FicheComposee>
     */
    public function pourType(TypeFicheLexicale $type): LazyCollection
    {
        return match ($type) {
            TypeFicheLexicale::PRODUIT => $this->produits(),
            TypeFicheLexicale::ARTISAN => $this->artisans(),
        };
    }

    public function pourProduit(Produit $produit): FicheComposee
    {
        return new FicheComposee(
            type: TypeFicheLexicale::PRODUIT,
            sourceId: (int) $produit->getKey(),
            titre: trim($produit->reference.' — '.$produit->designation, ' —'),
            champs: [
                'designation' => $produit->designation,
                'categorie' => $this->lignageDeCategorie($produit),
                'corps_metier' => $produit->artisan?->corpsMetier?->libelle,
                'description' => $produit->description,
                'artisan' => $produit->artisan?->nom_complet,
            ],
        );
    }

    public function pourArtisan(Artisan $artisan): FicheComposee
    {
        $produits = $this->produitsDe($artisan);

        return new FicheComposee(
            type: TypeFicheLexicale::ARTISAN,
            sourceId: (int) $artisan->getKey(),
            titre: trim($artisan->matricule.' — '.$artisan->nom_complet, ' —'),
            champs: [
                'identite' => $artisan->nom_complet,

                // La description du corps de métier accompagne son
                // libellé : « Vannerie » seul ne rapproche rien de
                // « panier », alors que sa description le fait.
                'corps_metier' => $this->libelleEtDescriptionDuMetier($artisan),

                'categories_produits' => $produits
                    ->pluck('categorie.libelle')
                    ->filter()
                    ->unique()
                    ->implode(' '),

                'designations_produits' => $produits
                    ->pluck('designation')
                    ->filter()
                    ->unique()
                    ->implode(' '),
            ],
        );
    }

    /**
     * La catégorie du produit et celle qui la porte.
     *
     * Remonter d'un cran fait qu'un produit rangé sous « Masques »
     * partage aussi les termes de « Sculpture », et se rapproche donc
     * d'un tabouret sculpté. Un seul cran : la hiérarchie du village
     * n'en compte pas davantage, et remonter jusqu'à la racine
     * rapprocherait tout de tout.
     */
    protected function lignageDeCategorie(Produit $produit): ?string
    {
        $libelles = array_filter([
            $produit->categorie?->libelle,
            $produit->categorie?->parent?->libelle,
        ]);

        return $libelles === [] ? null : implode(' ', $libelles);
    }

    protected function libelleEtDescriptionDuMetier(Artisan $artisan): ?string
    {
        $parties = array_filter([
            $artisan->corpsMetier?->libelle,
            $artisan->corpsMetier?->description,
        ]);

        return $parties === [] ? null : implode(' ', $parties);
    }

    /**
     * @return Collection<int, Produit>
     */
    protected function produitsDe(Artisan $artisan): Collection
    {
        return Produit::query()
            ->with('categorie')
            ->where('artisan_id', $artisan->getKey())
            ->get();
    }
}
