<?php

namespace Modules\Pilotage\Recherche;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Pilotage\Contracts\MoteurDeRecherche;
use Modules\Pilotage\Indexation\Normalisateur;
use Modules\Pilotage\Models\FicheLexicale;

/**
 * Le moteur témoin : correspondance de mots, sans pondération.
 *
 * **Il existe pour être battu, et pour que la victoire soit mesurable.**
 * L'hypothèse H3 compare recherche pondérée et recherche par mots-clés ;
 * une comparaison n'a de sens que si les deux moteurs jouent à armes
 * égales sur tout sauf sur ce qui les distingue. Celui-ci partage donc
 * exactement la tokenisation et l'index du moteur lexical : mêmes
 * accents dépliés, mêmes pluriels ramenés, mêmes mots vides écartés.
 * Seule la pondération manque.
 *
 * **Ce qu'il ignore, et ce que cela coûte.** Le score est la proportion
 * de mots de la question présents dans la fiche — le coefficient de
 * recouvrement. Un mot rare et un mot banal y pèsent pareil : une
 * question sur « miel d'acacia » trouvera aussi bien une fiche qui ne
 * partage que « miel » qu'une fiche portant les deux, dès lors qu'elle
 * est plus courte. C'est précisément l'effet que l'IDF corrige, et ce
 * que la mesure doit faire apparaître.
 *
 * Il n'est pas dans `pilotage.moteur.ordre` : ce n'est pas un moteur de
 * repli, c'est un instrument de mesure. Le mettre en production
 * dégraderait volontairement la recherche.
 */
class MoteurMotsCles implements MoteurDeRecherche
{
    use ComposeDesExtraits;

    public function nom(): string
    {
        return 'Correspondance par mots-clés (témoin)';
    }

    public function cle(): string
    {
        return 'mots_cles';
    }

    public function estDisponible(): bool
    {
        return FicheLexicale::query()->where('nombre_termes', '>', 0)->exists();
    }

    /**
     * @return Collection<int, SegmentTrouve>
     */
    public function rechercher(string $question, int $limite, ?float $seuil = null): Collection
    {
        $normalisateur = Normalisateur::depuisLaConfiguration();
        $termes = $this->termesSurs(array_unique($normalisateur->decouper($question)));

        if ($termes === []) {
            return new Collection();
        }

        // Le seuil s'entend ici comme une proportion de mots retrouvés,
        // non comme un cosinus. Les deux vivent dans [0, 1] et se
        // comparent donc à la même échelle — c'est ce qui rend la
        // mesure d'ensemble lisible d'un moteur à l'autre.
        $seuil ??= (float) config('pilotage.recherche.seuil', 0.10);
        $attendus = count($termes);

        $score = sprintf('count(distinct t.terme)::float / %d', $attendus);

        $lignes = DB::table('termes_lexicaux as t')
            ->join('fiches_lexicales as f', 'f.id', '=', 't.fiche_id')
            ->whereIn('t.terme', $termes)
            ->groupBy('f.id', 'f.type', 'f.source_id', 'f.titre', 'f.texte')
            ->selectRaw(
                'f.id as fiche_id, f.type as type, f.source_id as source_id, '
                ."f.titre as titre, f.texte as texte, {$score} as similarite"
            )
            ->havingRaw("{$score} >= ?", [$seuil])
            ->orderByRaw("{$score} desc")
            ->orderBy('f.id')
            ->limit($limite)
            ->get();

        return $lignes->map(
            fn (object $ligne): SegmentTrouve => $this->composerSegment($ligne, $termes, $normalisateur),
        );
    }
}
