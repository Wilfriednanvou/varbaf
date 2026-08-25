<?php

namespace App\Import;

/**
 * Ce que le rapprochement des noms d'artisans a établi.
 *
 * Trois lectures en sortent, et le rapport d'import a besoin des trois :
 * quelle forme retenir pour un nom donné, combien d'écritures ont été
 * ramenées à une autre, et quels couples ont été laissés distincts alors
 * qu'ils se ressemblaient.
 */
class ResultatRapprochement
{
    /**
     * @param  array<string, string>  $canoniqueParNom  Nom brut → forme retenue
     * @param  array<string, array<int, string>>  $variantesParCanonique
     * @param  array<int, array{nom: string, candidat: string, score: float}>  $doutes
     */
    public function __construct(
        protected array $canoniqueParNom,
        protected array $variantesParCanonique,
        protected array $doutes,
        public readonly float $seuil,
        public readonly float $marge,
    ) {}

    public function canonique(?string $nomBrut): ?string
    {
        if ($nomBrut === null) {
            return null;
        }

        return $this->canoniqueParNom[Normalisation::lisible($nomBrut)] ?? null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function variantes(): array
    {
        return $this->variantesParCanonique;
    }

    /**
     * Nombre d'écritures distinctes rencontrées dans le registre.
     */
    public function nombreEcritures(): int
    {
        return count($this->canoniqueParNom);
    }

    /**
     * Nombre d'artisans retenus au terme du rapprochement.
     */
    public function nombreDistincts(): int
    {
        return count($this->variantesParCanonique);
    }

    /**
     * Nombre d'écritures ramenées à une autre.
     */
    public function nombreRegroupees(): int
    {
        return $this->nombreEcritures() - $this->nombreDistincts();
    }

    /**
     * Le nom a-t-il été rapproché d'une autre écriture ?
     */
    public function aEteRegroupe(?string $nomBrut): bool
    {
        $canonique = $this->canonique($nomBrut);

        return $canonique !== null && $canonique !== Normalisation::lisible((string) $nomBrut);
    }

    /**
     * Couples restés distincts malgré une ressemblance proche du seuil.
     *
     * @return array<int, array{nom: string, candidat: string, score: float}>
     */
    public function doutes(): array
    {
        return $this->doutes;
    }

    /**
     * @return array{candidat: string, score: float}|null
     */
    public function doutePour(?string $nomBrut): ?array
    {
        if ($nomBrut === null) {
            return null;
        }

        $nom = Normalisation::lisible($nomBrut);

        foreach ($this->doutes as $doute) {
            if ($doute['nom'] === $nom) {
                return ['candidat' => $doute['candidat'], 'score' => $doute['score']];
            }
        }

        return null;
    }
}
