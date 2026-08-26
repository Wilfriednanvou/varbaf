<?php

namespace Modules\Pilotage\Indexation;

/**
 * Ce qu'une réindexation a fait.
 *
 * Rendu par le service et affiché par la commande. Les compteurs
 * distinguent ce qui a été **recomposé** de ce qui a été **repondéré** :
 * la seconde opération touche toujours tout le corpus, la première
 * seulement ce qui a bougé. Confondre les deux donnerait à croire qu'une
 * réindexation partielle produit un index partiel, ce qui serait faux.
 */
final readonly class RapportIndexation
{
    public function __construct(
        public int $fichesLues = 0,
        public int $fichesRecomposees = 0,
        public int $fichesInchangees = 0,
        public int $fichesSupprimees = 0,
        public int $fichesSansTerme = 0,
        public int $termesEcrits = 0,
        public int $termesDistincts = 0,
        public int $tailleCorpus = 0,
    ) {}

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public function enLignes(): array
    {
        return [
            ['Fiches lues depuis les modèles', (string) $this->fichesLues],
            ['Fiches recomposées et retokenisées', (string) $this->fichesRecomposees],
            ['Fiches inchangées, termes conservés', (string) $this->fichesInchangees],
            ['Fiches retirées (source disparue)', (string) $this->fichesSupprimees],
            ['Fiches sans aucun terme indexable', (string) $this->fichesSansTerme],
            ['Entrées écrites dans l\'index inversé', (string) $this->termesEcrits],
            ['Termes distincts du vocabulaire', (string) $this->termesDistincts],
            ['Taille du corpus après indexation', (string) $this->tailleCorpus],
        ];
    }
}
