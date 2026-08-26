<?php

namespace Modules\Pilotage\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Enums\StatutValidationProduit;
use Modules\Pilotage\Enums\TypeFicheLexicale;
use Modules\Pilotage\Models\FicheLexicale;

/**
 * La même mesure de similarité, retournée : ce que le catalogue dit de
 * lui-même.
 *
 * La recommandation regarde un produit et cherche ses proches. Ces deux
 * indicateurs regardent le catalogue entier et cherchent ses **formes** :
 * ce qui n'a de voisin nulle part, et ce qui en a trop. Les deux
 * intéressent des sections différentes du village.
 *
 * - Un **produit isolé** ne ressemble à rien d'autre. Ce n'est pas un
 *   défaut : c'est une pièce unique, une niche, ou un savoir-faire que
 *   personne d'autre ne porte. La section Promotion y trouve ses
 *   candidats naturels à une mise en avant, précisément parce qu'ils ne
 *   se noient pas dans un rayon.
 * - Un **segment saturé** rassemble des produits très proches portés par
 *   plusieurs artisans différents. La section Production y lit un
 *   segment où l'offre se concentre — matière à conseil aux artisans et
 *   à orientation des formations, deux missions de la structure.
 *
 * **Le coût.** Ces deux mesures parcourent les paires de fiches partageant
 * un terme, là où la recommandation part d'une fiche unique. C'est
 * quadratique dans le pire des cas, borné en pratique par le fait que
 * deux fiches sans terme commun ne se rencontrent jamais. À l'échelle du
 * village — quelques centaines de produits — la requête tient largement ;
 * à dix mille, elle demanderait une table de paires matérialisée à
 * l'indexation. Les résultats sont bornés par `limite` : ce sont des
 * indicateurs de tableau de bord, pas des inventaires.
 */
class ServiceAnalyseCatalogue
{
    /**
     * Les produits dont aucun voisin n'atteint le seuil.
     *
     * Un produit qui ne partage aucun terme avec quiconque n'apparaît
     * dans aucune paire : il est isolé au sens le plus fort, et sa
     * meilleure similarité vaut zéro. C'est pourquoi la liste part des
     * fiches et non des paires — partir des paires ferait disparaître
     * exactement les produits les plus isolés.
     *
     * @return Collection<int, array{produit_id: int, reference: string, designation: string, artisan: string, meilleure: float}>
     */
    public function produitsIsoles(?float $seuil = null, ?int $limite = null): Collection
    {
        $seuil ??= (float) config('pilotage.analyse.seuil_isolement', 0.15);
        $limite ??= (int) config('pilotage.analyse.limite', 8);

        $meilleures = $this->meilleuresSimilarites();

        $candidats = DB::table('fiches_lexicales as f')
            ->join('produits as p', 'p.id', '=', 'f.source_id')
            ->join('artisans as a', 'a.id', '=', 'p.artisan_id')
            ->where('f.type', TypeFicheLexicale::PRODUIT->value)
            ->where('f.norme', '>', 0)
            ->where('p.actif', true)
            ->whereIn('p.statut_validation', $this->statutsVendables())
            ->select([
                'f.source_id as produit_id',
                'p.reference',
                'p.designation',
                DB::raw("trim(concat(a.nom, ' ', coalesce(a.prenom, ''))) as artisan"),
            ])
            ->get();

        return $candidats
            ->map(function (object $ligne) use ($meilleures): array {
                $produitId = (int) $ligne->produit_id;

                return [
                    'produit_id' => $produitId,
                    'reference' => (string) $ligne->reference,
                    'designation' => (string) $ligne->designation,
                    'artisan' => (string) $ligne->artisan,
                    'meilleure' => (float) ($meilleures[$produitId] ?? 0.0),
                ];
            })
            ->filter(fn (array $ligne): bool => $ligne['meilleure'] < $seuil)
            ->sortBy(['meilleure', 'reference'])
            ->take($limite)
            ->values();
    }

    /**
     * Combien de produits sont isolés, tous confondus.
     *
     * Le décompte ne passe pas par `produitsIsoles()` : celle-ci est
     * bornée pour l'affichage, et compter une liste tronquée donnerait
     * toujours le nombre affiché.
     */
    public function nombreDeProduitsIsoles(?float $seuil = null): int
    {
        return $this->produitsIsoles($seuil, PHP_INT_MAX)->count();
    }

    /**
     * Les produits autour desquels plusieurs artisans distincts
     * proposent des articles très proches.
     *
     * @return Collection<int, array{produit_id: int, reference: string, designation: string, artisan: string, concurrents: int, similarite_moyenne: float}>
     */
    public function segmentsSatures(?float $seuil = null, ?int $minimumArtisans = null, ?int $limite = null): Collection
    {
        $seuil ??= (float) config('pilotage.analyse.seuil_saturation', 0.45);
        $minimumArtisans ??= (int) config('pilotage.analyse.artisans_minimum', 2);
        $limite ??= (int) config('pilotage.analyse.limite', 8);

        $lignes = DB::query()
            ->fromSub($this->pairesEntreArtisansDifferents(), 'paires')
            ->where('paires.similarite', '>=', $seuil)
            ->groupBy('paires.produit_id', 'paires.reference', 'paires.designation', 'paires.artisan')
            ->havingRaw('count(distinct paires.autre_artisan) >= ?', [$minimumArtisans])
            ->select([
                'paires.produit_id',
                'paires.reference',
                'paires.designation',
                'paires.artisan',
                DB::raw('count(distinct paires.autre_artisan) as concurrents'),
                DB::raw('avg(paires.similarite) as similarite_moyenne'),
            ])
            ->orderByDesc('concurrents')
            ->orderByDesc('similarite_moyenne')
            ->orderBy('paires.reference')
            ->limit($limite)
            ->get();

        return $lignes->map(fn (object $ligne): array => [
            'produit_id' => (int) $ligne->produit_id,
            'reference' => (string) $ligne->reference,
            'designation' => (string) $ligne->designation,
            'artisan' => (string) $ligne->artisan,
            'concurrents' => (int) $ligne->concurrents,
            'similarite_moyenne' => round((float) $ligne->similarite_moyenne, 4),
        ]);
    }

    /**
     * La meilleure similarité de chaque produit, produit_id => cosinus.
     *
     * @return array<int, float>
     */
    protected function meilleuresSimilarites(): array
    {
        if (! $this->corpusComparable()) {
            return [];
        }

        return DB::query()
            ->fromSub($this->pairesDeProduits(), 'paires')
            ->groupBy('paires.produit_id')
            ->select(['paires.produit_id', DB::raw('max(paires.similarite) as meilleure')])
            ->get()
            ->mapWithKeys(fn (object $ligne): array => [
                (int) $ligne->produit_id => (float) $ligne->meilleure,
            ])
            ->all();
    }

    /**
     * Les paires de fiches produit partageant au moins un terme, avec
     * leur cosinus.
     *
     * La jointure sur `t2.terme = t1.terme` est ce qui borne le calcul :
     * deux produits sans mot commun ne se rencontrent jamais, et la
     * paire n'existe pas.
     */
    protected function pairesDeProduits(): \Illuminate\Database\Query\Builder
    {
        return DB::table('termes_lexicaux as t1')
            ->join('fiches_lexicales as fa', function ($jointure): void {
                $jointure->on('fa.id', '=', 't1.fiche_id')
                    ->where('fa.type', '=', TypeFicheLexicale::PRODUIT->value)
                    ->where('fa.norme', '>', 0);
            })
            ->join('termes_lexicaux as t2', function ($jointure): void {
                $jointure->on('t2.terme', '=', 't1.terme')
                    ->whereColumn('t2.fiche_id', '!=', 't1.fiche_id');
            })
            ->join('fiches_lexicales as fb', function ($jointure): void {
                $jointure->on('fb.id', '=', 't2.fiche_id')
                    ->where('fb.type', '=', TypeFicheLexicale::PRODUIT->value)
                    ->where('fb.norme', '>', 0);
            })
            ->groupBy('fa.id', 'fa.source_id', 'fa.norme', 'fb.id', 'fb.norme')
            ->select([
                'fa.source_id as produit_id',
                DB::raw('sum(t1.poids * t2.poids) / (fa.norme * fb.norme) as similarite'),
            ]);
    }

    /**
     * Les mêmes paires, restreintes à deux artisans différents et
     * enrichies de quoi nommer le produit.
     */
    protected function pairesEntreArtisansDifferents(): \Illuminate\Database\Query\Builder
    {
        return DB::table('termes_lexicaux as t1')
            ->join('fiches_lexicales as fa', function ($jointure): void {
                $jointure->on('fa.id', '=', 't1.fiche_id')
                    ->where('fa.type', '=', TypeFicheLexicale::PRODUIT->value)
                    ->where('fa.norme', '>', 0);
            })
            ->join('produits as pa', 'pa.id', '=', 'fa.source_id')
            ->join('artisans as aa', 'aa.id', '=', 'pa.artisan_id')
            ->join('termes_lexicaux as t2', function ($jointure): void {
                $jointure->on('t2.terme', '=', 't1.terme')
                    ->whereColumn('t2.fiche_id', '!=', 't1.fiche_id');
            })
            ->join('fiches_lexicales as fb', function ($jointure): void {
                $jointure->on('fb.id', '=', 't2.fiche_id')
                    ->where('fb.type', '=', TypeFicheLexicale::PRODUIT->value)
                    ->where('fb.norme', '>', 0);
            })
            ->join('produits as pb', 'pb.id', '=', 'fb.source_id')
            ->where('pa.actif', true)
            ->where('pb.actif', true)
            ->whereIn('pa.statut_validation', $this->statutsVendables())
            ->whereIn('pb.statut_validation', $this->statutsVendables())

            // La saturation n'a de sens qu'entre artisans distincts :
            // deux articles proches d'un même artisan sont une gamme,
            // pas une concurrence.
            ->whereColumn('pa.artisan_id', '!=', 'pb.artisan_id')

            ->groupBy(
                'fa.id', 'fa.source_id', 'fa.norme', 'fb.id', 'fb.norme',
                'pa.reference', 'pa.designation', 'pa.artisan_id', 'pb.artisan_id',
                'aa.nom', 'aa.prenom',
            )
            ->select([
                'fa.source_id as produit_id',
                'pa.reference as reference',
                'pa.designation as designation',
                'pb.artisan_id as autre_artisan',
                DB::raw("trim(concat(aa.nom, ' ', coalesce(aa.prenom, ''))) as artisan"),
                DB::raw('sum(t1.poids * t2.poids) / (fa.norme * fb.norme) as similarite'),
            ]);
    }

    protected function corpusComparable(): bool
    {
        return FicheLexicale::query()
            ->deType(TypeFicheLexicale::PRODUIT)
            ->comparable()
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    protected function statutsVendables(): array
    {
        return [
            StatutValidationProduit::VALIDE->value,
            StatutValidationProduit::EXPOSE->value,
        ];
    }
}
