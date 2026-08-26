<?php

namespace Modules\Pilotage\Assistant;

use Modules\Pilotage\Enums\CategorieQuestion;

/**
 * Ce que le routeur a décidé, et pourquoi.
 *
 * `scores` n'est pas de la dette de journalisation : c'est ce qui rend
 * la décision explicable en soutenance. Montrer que « chiffre
 * d'affaires » a marqué 2 et « ventes par boutique » 0 vaut mieux que
 * d'affirmer que le routeur a bien classé.
 */
final readonly class ResultatDeRoutage
{
    /**
     * @param  array<string, int>  $scores  clé d'intention => score, décroissant
     */
    public function __construct(
        public CategorieQuestion $categorie,
        public ?Intention $intention,
        public int $score,
        public array $scores,
    ) {}

    public function estAgregation(): bool
    {
        return $this->categorie === CategorieQuestion::AGREGATION;
    }
}
