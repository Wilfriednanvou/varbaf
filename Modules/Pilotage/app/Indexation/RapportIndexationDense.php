<?php

namespace Modules\Pilotage\Indexation;

/**
 * Ce qu'une indexation dense a produit.
 *
 * **`echouees` n'est pas une statistique décorative.** Un vecteur
 * manquant retire silencieusement une fiche de la branche dense : elle
 * reste trouvable par le lexical, donc rien ne casse, et personne ne
 * s'aperçoit que la moitié du corpus a cessé d'exister pour l'un des
 * deux moteurs. Le nombre doit être affiché même quand il vaut zéro,
 * pour que sa présence à l'écran soit la norme et son absence
 * remarquable.
 */
final readonly class RapportIndexationDense
{
    public function __construct(
        public string $modele,
        public int $fichesLues,
        public int $vectorisees,
        public int $inchangees,
        public int $echouees,
        public int $dimensions,
        public int $couverture,
        public int $tailleCorpus,
    ) {}

    /**
     * @return array<string, string|int>
     */
    public function indicateurs(): array
    {
        return [
            'Modèle' => $this->modele,
            'Fiches lues' => $this->fichesLues,
            'Vectorisées' => $this->vectorisees,
            'Inchangées' => $this->inchangees,
            'Échouées' => $this->echouees,
            'Dimensions' => $this->dimensions,
            'Couverture' => $this->couverture.' / '.$this->tailleCorpus,
        ];
    }

    /**
     * La part du corpus que la branche dense sait chercher.
     *
     * En dessous de 100 %, la fusion travaille sur un corpus tronqué
     * pour l'une de ses deux branches — ce qui reste correct, mais doit
     * se savoir avant qu'on interprète une mesure de rappel.
     */
    public function partCouverte(): float
    {
        return $this->tailleCorpus === 0
            ? 0.0
            : round($this->couverture * 100 / $this->tailleCorpus, 1);
    }
}
