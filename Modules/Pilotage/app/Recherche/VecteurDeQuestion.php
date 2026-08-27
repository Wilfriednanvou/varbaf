<?php

namespace Modules\Pilotage\Recherche;

use Modules\Pilotage\Indexation\Normalisateur;
use Modules\Pilotage\Models\TermeVocabulaire;

/**
 * Une question, transformée en vecteur comparable au corpus.
 *
 * **La même tokenisation que l'indexation, sans exception.** Si la
 * question était découpée autrement — accents conservés, pluriels non
 * ramenés, mots vides gardés — ses termes ne rencontreraient jamais
 * ceux de l'index et la recherche rendrait systématiquement vide. C'est
 * pourquoi cette classe n'a pas de règle de découpe propre : elle
 * délègue au `Normalisateur` du chantier 1.
 *
 * L'IDF vient de `vocabulaire_lexical`, calculé à l'indexation sur le
 * corpus entier. Le recalculer ici donnerait à la question un IDF issu
 * d'un seul document, ce qui n'aurait aucun sens : l'IDF mesure la
 * rareté d'un terme **dans le corpus**, pas dans la question.
 *
 * Un terme absent du vocabulaire n'a pas d'entrée : il est ignoré
 * plutôt que traité comme infiniment rare. Un mot que personne ne porte
 * ne discrimine rien — il ne peut rapprocher d'aucune fiche.
 *
 * **Une seule divergence avec l'indexation, et elle est assumée** :
 * l'échafaudage de la question — « produits », « liste », « objets » —
 * est élagué par `MotsDeQuestion` avant la pondération. Le corpus, lui,
 * conserve ces mots. Ce n'est pas une entorse à la règle du même
 * découpage : les termes restants sont découpés exactement comme à
 * l'indexation, et c'est ce qui compte pour qu'ils se rencontrent. On
 * retire des mots, on n'en fabrique pas d'autres.
 */
final readonly class VecteurDeQuestion
{
    /**
     * @param  array<int, string>  $termes  les termes retenus, avec répétitions
     * @param  array<string, float>  $poids  terme => tf × idf, termes hors vocabulaire exclus
     */
    private function __construct(
        public string $question,
        public array $termes,
        public array $poids,
        public float $norme,
    ) {}

    public static function depuis(string $question, ?Normalisateur $normalisateur = null): self
    {
        $normalisateur ??= Normalisateur::depuisLaConfiguration();

        $termes = $normalisateur->decouper($question);

        if ($termes === []) {
            return new self($question, [], [], 0.0);
        }

        // L'échafaudage de la question tombe ici, et **seulement ici** :
        // le corpus n'est pas touché, donc rien n'est à réindexer et une
        // fiche dont la désignation d'origine est « Produit » reste
        // trouvable par ce mot. Voir `MotsDeQuestion` pour le motif, qui
        // n'est pas celui de `MotsVides`.
        $termes = MotsDeQuestion::elaguer($termes);

        $frequences = array_count_values($termes);
        $idf = TermeVocabulaire::idfDe(array_keys($frequences));

        $poids = [];

        foreach ($frequences as $terme => $frequence) {
            if (! isset($idf[$terme])) {
                continue;
            }

            $poids[$terme] = $frequence * $idf[$terme];
        }

        $norme = 0.0;

        foreach ($poids as $valeur) {
            $norme += $valeur ** 2;
        }

        return new self($question, array_values($termes), $poids, sqrt($norme));
    }

    /**
     * La question porte-t-elle au moins un terme que le corpus connaît ?
     *
     * Une question dont aucun mot n'est au vocabulaire n'est pas une
     * question sans réponse : c'est une question sur laquelle le corpus
     * n'a rien à dire. La distinction compte pour le refus.
     */
    public function estExploitable(): bool
    {
        return $this->poids !== [] && $this->norme > 0;
    }

    /**
     * @return array<int, string>
     */
    public function termesRetenus(): array
    {
        return array_keys($this->poids);
    }

    /**
     * Les termes découpés mais absents du vocabulaire du corpus.
     *
     * @return array<int, string>
     */
    public function termesInconnus(): array
    {
        return array_values(array_diff(array_unique($this->termes), $this->termesRetenus()));
    }
}
