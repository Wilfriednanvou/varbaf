<?php

namespace Modules\Pilotage\Recommandation;

/**
 * Un produit proche, et de combien.
 *
 * **Deux nombres, pas un.** `similarite` est la mesure : le cosinus
 * entre les deux fiches, comparable d'un produit à l'autre et seul à
 * franchir le seuil. `score` est la mesure après majoration du même
 * artisan : il ne sert qu'à classer.
 *
 * Les confondre reviendrait à laisser la majoration faire franchir le
 * seuil de qualité à un rapprochement faible — un produit du même
 * artisan n'est pas plus ressemblant parce qu'il est du même artisan,
 * il est seulement préférable à ressemblance égale.
 */
final readonly class ProduitVoisin
{
    public function __construct(
        public int $produitId,
        public int $artisanId,
        public float $similarite,
        public float $score,
        public bool $memeArtisan,
    ) {}

    /**
     * La similarité en pourcentage, arrondie, pour l'affichage.
     */
    public function pourcentage(): float
    {
        return round($this->similarite * 100, 1);
    }
}
