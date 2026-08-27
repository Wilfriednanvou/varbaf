<?php

namespace Modules\Pilotage\Recherche;

use Illuminate\Support\Collection;
use Modules\Pilotage\Contracts\MoteurDeRecherche;
use Modules\Pilotage\Contracts\MoteurSemantique;
use Modules\Pilotage\Recommandation\CriteresDeVoisinage;
use Modules\Pilotage\Recommandation\MoteurLexical;
use Modules\Pilotage\Recommandation\ProduitVoisin;

/**
 * Le moteur hybride : deux façons de chercher, une seule réponse.
 *
 * **Ce que chacune sait faire, et pas l'autre.** Le lexical exige un mot
 * commun : il est précis, il ne rapproche jamais deux textes étrangers,
 * et il est aveugle à toute reformulation — « objets pour la cuisine »
 * ne trouve pas « marmite en terre cuite ». Le dense ignore les mots et
 * lit le sens : il retrouve la marmite, et il rapproche aussi, avec un
 * aplomb égal, des choses qui n'ont rien à voir. La précision de l'un
 * corrige la complaisance de l'autre, et c'est la seule raison de les
 * faire cohabiter.
 *
 * **Un passage trouvé par les deux passe devant un passage trouvé par
 * un seul**, même si ce dernier était premier chez lui. C'est l'effet
 * recherché : l'accord de deux techniques indépendantes est un signal
 * plus fort que l'enthousiasme d'une seule. `FusionReciproque` porte le
 * détail du calcul.
 *
 * **Ce moteur ne tombe pas en panne.** Si le fournisseur d'embeddings
 * est arrêté, il ne reste que la branche lexicale, et l'hybride répond
 * exactement ce que le lexical aurait répondu — en le disant. Couper
 * Ollama devant un jury change le nom affiché sous la réponse, pas la
 * disponibilité du système. C'est la propriété que `MoteurSemantique`
 * annonçait depuis le premier jour ; elle a maintenant deux branches à
 * arbitrer plutôt qu'une seule à servir.
 *
 * **Le voisinage de produits reste lexical, et c'est délibéré.**
 * `voisins()` porte des exclusions métier — produit actif, statut validé
 * ou exposé, stock épuisé, restrictions de la surface appelante —
 * exprimées en SQL, dans la requête qui calcule la similarité. Les
 * rejouer sur un index dense comparé en mémoire signifierait réécrire
 * ces règles une seconde fois, à un autre endroit, dans un autre
 * langage. Une règle métier dupliquée finit toujours par diverger : le
 * jour où « exposé » cesse d'être publiable, une des deux copies
 * l'apprendra et pas l'autre. La recherche gagne le dense, la
 * recommandation attendra qu'on sache exprimer ces exclusions une seule
 * fois.
 */
class MoteurHybride implements MoteurSemantique
{
    public function __construct(
        protected MoteurLexical $lexical,
        protected MoteurDense $dense,
    ) {}

    /**
     * Le nom dit la composition **réelle** du moment.
     *
     * Un nom figé — « Hybride » — laisserait croire que les deux
     * branches ont répondu alors qu'une seule était debout. L'écran doit
     * porter ce qui s'est passé, pas ce qui était configuré.
     */
    public function nom(): string
    {
        $branches = $this->branches();

        if ($branches === []) {
            return 'Hybride — aucune branche disponible';
        }

        if (count($branches) === 1) {
            $seule = array_key_first($branches);

            return 'Hybride (branche '.$seule.' seule) — '.$branches[$seule]->nom();
        }

        return 'Hybride — '.implode(' ⊕ ', array_map(
            static fn (MoteurDeRecherche $moteur): string => $moteur->nom(),
            array_values($branches),
        ));
    }

    /**
     * Le voisinage est lexical, donc il se nomme lexical.
     *
     * `voisins()` délègue au seul lexical — pas faute de dense, mais par
     * conception, et le commentaire de tête dit pourquoi. Reprendre ici
     * le nom composite ferait annoncer sous les suggestions du portail
     * une branche qui ne les a pas calculées. Le défaut ne se verrait
     * même pas aujourd'hui, où le dense est absent de la plupart des
     * postes : il apparaîtrait le jour où Ollama tourne, c'est-à-dire le
     * jour de la démonstration.
     */
    public function nomDuVoisinage(): string
    {
        return $this->lexical->nom();
    }

    public function cle(): string
    {
        return 'hybride';
    }

    /**
     * Une branche suffit.
     *
     * Se déclarer indisponible parce qu'il en manque une reviendrait à
     * refuser de chercher alors qu'on sait le faire — et à faire
     * dépendre la recherche du village d'un service qui n'a jamais été
     * une condition de son fonctionnement.
     */
    public function estDisponible(): bool
    {
        return $this->branches() !== [];
    }

    /**
     * @return Collection<int, SegmentTrouve>
     */
    public function rechercher(string $question, int $limite, ?float $seuil = null): Collection
    {
        $branches = $this->branches();

        if ($branches === []) {
            return new Collection();
        }

        // Chaque branche remonte plus de candidats qu'on n'en affichera :
        // un passage classé huitième par le lexical et deuxième par le
        // dense mérite d'être vu, et il ne le serait pas si chacune
        // s'arrêtait aux cinq premiers avant la fusion.
        $candidats = max($limite, (int) config('pilotage.fusion.candidats', 10));

        /** @var array<string, Collection<int, SegmentTrouve>> $resultats */
        $resultats = [];

        foreach ($branches as $cle => $moteur) {
            // `$seuil` est passé tel quel : `null` laisse chaque branche
            // appliquer le sien, et un seuil imposé par l'appelant — la
            // commande d'évaluation mesure à seuil nul, pour voir tout
            // ce que chaque branche remonte — s'applique aux deux. Ces
            // deux nombres ne mesurent pas la même chose et n'ont, en
            // marche normale, aucune raison de se ressembler.
            $resultats[$cle] = $moteur->rechercher($question, $candidats, $seuil);
        }

        return FusionReciproque::fusionner(
            $resultats,
            (array) config('pilotage.fusion.poids', []),
            (float) config('pilotage.fusion.k', 60),
            $limite,
        );
    }

    /**
     * @return Collection<int, ProduitVoisin>
     */
    public function voisins(int $produitId, CriteresDeVoisinage $criteres): Collection
    {
        return $this->lexical->voisins($produitId, $criteres);
    }

    // =================================================================

    /**
     * Les branches debout, dans l'ordre où on les interroge.
     *
     * @return array<string, MoteurDeRecherche>
     */
    protected function branches(): array
    {
        $branches = [];

        if ($this->lexical->estDisponible()) {
            $branches['lexical'] = $this->lexical;
        }

        if ($this->dense->estDisponible()) {
            $branches['dense'] = $this->dense;
        }

        return $branches;
    }
}
