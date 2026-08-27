<?php

namespace Modules\Pilotage\Recommandation;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Enums\StatutValidationProduit;
use Modules\Pilotage\Contracts\MoteurSemantique;
use Modules\Pilotage\Enums\TypeFicheLexicale;
use Modules\Pilotage\Indexation\Normalisateur;
use Modules\Pilotage\Models\FicheLexicale;
use Modules\Pilotage\Recherche\ComposeDesExtraits;
use Modules\Pilotage\Recherche\SegmentTrouve;
use Modules\Pilotage\Recherche\VecteurDeQuestion;

/**
 * Le moteur qui ne peut pas tomber en panne.
 *
 * Similarité cosinus entre fiches produit, calculée **par l'index
 * inversé** : on part des termes de la fiche de référence et on ne
 * remonte que les fiches qui en partagent au moins un. Le corpus n'est
 * jamais chargé — c'est la différence entre une requête bornée par le
 * voisinage lexical et un parcours de huit cents vecteurs à chaque
 * affichage de fiche produit.
 *
 * **Le cosinus se réduit à une division.** `fiches_lexicales.norme`
 * porte déjà la racine de la somme des carrés des poids, calculée à
 * l'indexation. Il ne reste donc, à la lecture, que le produit scalaire
 * des termes communs divisé par le produit de deux normes connues :
 *
 *     cos(a, b) = Σ (poids_a × poids_b) / (norme_a × norme_b)
 *
 * La somme est faite par PostgreSQL, groupée par fiche voisine.
 *
 * **Seuil et majoration ne portent pas sur la même chose.** Le seuil
 * filtre la similarité brute : c'est un plancher de qualité du
 * rapprochement. La majoration du même artisan n'entre que dans le
 * classement. Un produit du même artisan n'est pas plus ressemblant
 * parce qu'il est du même artisan ; à ressemblance comparable, il est
 * préférable — c'est une préférence, pas une mesure, et elle ne doit
 * pas pouvoir repêcher un rapprochement que le seuil a écarté.
 */
class MoteurLexical implements MoteurSemantique
{
    use ComposeDesExtraits;

    public function nom(): string
    {
        return 'Similarité lexicale (TF-IDF)';
    }

    /**
     * Le même : ce moteur cherche et calcule un voisinage par la même
     * mesure. La distinction n'a de sens que pour un composite.
     */
    public function nomDuVoisinage(): string
    {
        return $this->nom();
    }

    public function cle(): string
    {
        return 'lexical';
    }

    /**
     * Le moteur répond dès qu'il existe un corpus comparable.
     *
     * Un index vide n'est pas une panne : c'est une base qui n'a pas
     * encore été indexée. Mais un moteur qui répondrait « aucun voisin »
     * sur un index vide serait indiscernable d'un moteur qui a
     * réellement cherché, et le résolveur ne pourrait pas se rabattre.
     */
    public function estDisponible(): bool
    {
        // Toutes natures confondues : la recherche interroge aussi les
        // fiches artisan, là où la recommandation ne compare que des
        // produits. Restreindre la disponibilité aux seuls produits
        // ferait déclarer le moteur en panne sur un corpus qui ne
        // contiendrait que des artisans.
        return FicheLexicale::query()->comparable()->exists();
    }

    /**
     * @return Collection<int, ProduitVoisin>
     */
    public function voisins(int $produitId, CriteresDeVoisinage $criteres): Collection
    {
        $fiche = $this->ficheDuProduit($produitId);

        // Sans fiche, ou avec une fiche sans terme, il n'y a rien à
        // comparer : un produit dont tous les champs sont vides n'est
        // proche de rien, et diviser par sa norme nulle n'aurait pas
        // de sens. On rend une collection vide, pas une erreur —
        // l'appelant affichera simplement aucune suggestion.
        if ($fiche === null || ! $fiche->estComparable()) {
            return new Collection();
        }

        $artisanId = $this->artisanDuProduit($produitId);

        $similarite = $this->expressionDeSimilarite((float) $fiche->norme);
        $score = $this->expressionDeScore($similarite, $artisanId, $criteres->bonusMemeArtisan);

        $lignes = $this->requeteDesVoisins($fiche, $criteres)
            ->selectRaw("f.source_id as produit_id, p.artisan_id as artisan_id, {$similarite} as similarite, {$score} as score")
            ->groupBy('f.id', 'f.source_id', 'f.norme', 'p.artisan_id')
            ->havingRaw("{$similarite} >= ?", [$criteres->seuil])
            ->orderByRaw("{$score} desc")
            // Départage stable : sans lui, deux produits au même score
            // sortiraient dans l'ordre que PostgreSQL veut, qui peut
            // changer d'une exécution à l'autre.
            ->orderBy('f.source_id')
            ->limit($criteres->limite)
            ->get();

        return $lignes->map(fn (object $ligne): ProduitVoisin => new ProduitVoisin(
            produitId: (int) $ligne->produit_id,
            artisanId: (int) $ligne->artisan_id,
            similarite: (float) $ligne->similarite,
            score: (float) $ligne->score,
            memeArtisan: $artisanId !== null && (int) $ligne->artisan_id === $artisanId,
        ));
    }


    /**
     * Les passages du corpus les plus proches d'une question.
     *
     * Même mécanique que `voisins()`, mais le vecteur de référence n'est
     * plus une fiche : c'est la question, pondérée par l'IDF que
     * l'indexation a déjà calculé sur le corpus. La jointure sur
     * `t.terme in (...)` borne le calcul aux seules fiches qui portent
     * un mot de la question — le corpus n'est pas parcouru.
     *
     * Le poids de chaque terme de la question entre dans la requête par
     * un `case`, faute de pouvoir joindre une table qui n'existe pas :
     * la question est éphémère, elle n'est pas indexée.
     *
     * @return Collection<int, SegmentTrouve>
     */
    public function rechercher(string $question, int $limite, ?float $seuil = null): Collection
    {
        $normalisateur = Normalisateur::depuisLaConfiguration();
        $vecteur = VecteurDeQuestion::depuis($question, $normalisateur);

        // Aucun mot de la question n'est au vocabulaire : il n'y a rien
        // à chercher, et surtout rien à approcher. On rend vide, ce que
        // l'assistant traduira en refus explicite.
        if (! $vecteur->estExploitable()) {
            return new Collection();
        }

        $termes = $this->termesSurs($vecteur->termesRetenus());

        if ($termes === []) {
            return new Collection();
        }

        $seuil ??= (float) config('pilotage.recherche.seuil', 0.10);
        $similarite = $this->expressionDeSimilariteDeQuestion($vecteur, $termes);

        $lignes = DB::table('termes_lexicaux as t')
            ->join('fiches_lexicales as f', 'f.id', '=', 't.fiche_id')
            ->whereIn('t.terme', $termes)
            ->where('f.norme', '>', 0)
            ->groupBy('f.id', 'f.type', 'f.source_id', 'f.titre', 'f.texte', 'f.norme')
            ->selectRaw(
                'f.id as fiche_id, f.type as type, f.source_id as source_id, '
                ."f.titre as titre, f.texte as texte, {$similarite} as similarite"
            )
            ->havingRaw("{$similarite} >= ?", [$seuil])
            ->orderByRaw("{$similarite} desc")
            ->orderBy('f.id')
            ->limit($limite)
            ->get();

        return $lignes->map(
            fn (object $ligne): SegmentTrouve => $this->composerSegment($ligne, $termes, $normalisateur),
        );
    }

    /**
     * Le cosinus entre la question et une fiche, en SQL.
     *
     * Les poids de la question sont interpolés : ce sont des flottants
     * que l'on vient de calculer, et les termes ont franchi
     * `termesSurs()`. Les lier ferait dépendre le résultat de l'ordre
     * d'assemblage des clauses `select`, `having` et `order by`, qui
     * portent toutes les trois la même expression.
     *
     * @param  array<int, string>  $termes
     */
    protected function expressionDeSimilariteDeQuestion(VecteurDeQuestion $vecteur, array $termes): string
    {
        $cas = '';

        foreach ($termes as $terme) {
            $cas .= sprintf(" when '%s' then %s", $terme, $this->flottant($vecteur->poids[$terme]));
        }

        return sprintf(
            'sum(t.poids * (case t.terme%s else 0 end)) / (%s * f.norme)',
            $cas,
            $this->flottant($vecteur->norme),
        );
    }

    /**
     * La requête, avant projection : index inversé, fiches voisines,
     * produits, et les exclusions.
     */
    protected function requeteDesVoisins(FicheLexicale $fiche, CriteresDeVoisinage $criteres): Builder
    {
        $requete = DB::table('termes_lexicaux as t1')
            // Le cœur de l'affaire : on ne rejoint que les entrées
            // portant un terme que la fiche de référence porte aussi.
            ->join('termes_lexicaux as t2', 't2.terme', '=', 't1.terme')
            ->join('fiches_lexicales as f', 'f.id', '=', 't2.fiche_id')
            ->join('produits as p', 'p.id', '=', 'f.source_id')
            ->where('t1.fiche_id', $fiche->getKey())

            // Exclusion du produit courant : il est son propre voisin
            // parfait, et le proposer serait absurde.
            ->where('t2.fiche_id', '!=', $fiche->getKey())

            ->where('f.type', TypeFicheLexicale::PRODUIT->value)
            ->where('f.norme', '>', 0)

            // Exclusions systématiques, qui ne dépendent d'aucune
            // surface : un produit retiré du catalogue ou jamais validé
            // n'est proposé nulle part.
            ->where('p.actif', true)
            ->whereIn('p.statut_validation', [
                StatutValidationProduit::VALIDE->value,
                StatutValidationProduit::EXPOSE->value,
            ]);

        if ($criteres->exclureStockEpuise) {
            $requete->whereRaw('('.$this->expressionDeStock().') > 0');
        }

        if ($criteres->restreindre !== null) {
            ($criteres->restreindre)($requete);
        }

        return $requete;
    }

    /**
     * Le cosinus, en SQL.
     *
     * La norme de la fiche de référence est interpolée plutôt que liée :
     * c'est un flottant que le moteur vient de lire en base, jamais une
     * saisie, et l'interpoler évite d'avoir à raisonner sur l'ordre des
     * liaisons entre `select`, `having` et `order by` — trois clauses
     * qui portent ici la même expression.
     */
    protected function expressionDeSimilarite(float $normeSource): string
    {
        return sprintf(
            'sum(t1.poids * t2.poids) / (%s * f.norme)',
            $this->flottant($normeSource),
        );
    }

    protected function expressionDeScore(string $similarite, ?int $artisanId, float $bonus): string
    {
        if ($artisanId === null) {
            return $similarite;
        }

        return sprintf(
            '(%s) * (case when p.artisan_id = %d then %s else 1 end)',
            $similarite,
            $artisanId,
            $this->flottant($bonus),
        );
    }

    /**
     * Le solde de stock d'un produit, repris de `Produit::scopeSousLeSeuil()`.
     *
     * La quantité en stock est un solde calculé, jamais une colonne
     * (règle 3) : il n'y a donc rien à lire, seulement à sommer.
     */
    protected function expressionDeStock(): string
    {
        return 'coalesce((select sum(case when sens = \'ENTREE\' then quantite else -quantite end)'
            .' from mouvements_stock where mouvements_stock.produit_id = p.id), 0)';
    }

    /**
     * Un flottant écrit sans notation exponentielle ni dépendance à la
     * locale — `%F` et non `%f`, sans quoi une locale française
     * produirait une virgule décimale que PostgreSQL refuserait.
     */
    protected function flottant(float $valeur): string
    {
        return sprintf('%.12F', $valeur);
    }

    protected function ficheDuProduit(int $produitId): ?FicheLexicale
    {
        return FicheLexicale::query()
            ->deType(TypeFicheLexicale::PRODUIT)
            ->where('source_id', $produitId)
            ->first();
    }

    protected function artisanDuProduit(int $produitId): ?int
    {
        $artisanId = DB::table('produits')->where('id', $produitId)->value('artisan_id');

        return $artisanId === null ? null : (int) $artisanId;
    }
}
