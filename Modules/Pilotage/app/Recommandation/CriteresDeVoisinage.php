<?php

namespace Modules\Pilotage\Recommandation;

use Closure;

/**
 * Les paramètres d'une recherche de voisins.
 *
 * **Tout est ici, rien n'est en dur dans le moteur.** Les trois premiers
 * viennent de `config('pilotage.recommandation.*')` et sont destinés à
 * être calibrés puis reportés dans le dossier. Les deux derniers sont la
 * part de la surface appelante : le portail et un écran de gestion ne
 * veulent pas voir le même catalogue, et ce n'est pas au moteur d'en
 * décider.
 *
 * `exclureStockEpuise` illustre exactement pourquoi. Sur le portail, un
 * produit épuisé n'est pas masqué mais annoncé « sur commande » — un
 * artisan peut le refaire. Sur un écran de gestion qui proposerait de
 * quoi remplacer un article manquant, le montrer n'aurait aucun sens.
 * La même mesure, deux lectures : le paramètre appartient à l'appelant.
 */
final readonly class CriteresDeVoisinage
{
    /**
     * @param  int  $limite  nombre de voisins restitués au plus
     * @param  float  $seuil  similarité brute minimale ; en dessous, rien
     * @param  float  $bonusMemeArtisan  facteur appliqué au score de classement
     * @param  bool  $exclureStockEpuise  décision de la surface, pas du moteur
     * @param  Closure|null  $restreindre  affine la requête sur `produits as p`
     */
    public function __construct(
        public int $limite,
        public float $seuil,
        public float $bonusMemeArtisan,
        public bool $exclureStockEpuise = false,
        public ?Closure $restreindre = null,
    ) {}

    public static function depuisLaConfiguration(
        ?int $limite = null,
        ?float $seuil = null,
        ?float $bonusMemeArtisan = null,
        ?bool $exclureStockEpuise = null,
        ?Closure $restreindre = null,
    ): self {
        return new self(
            limite: $limite ?? (int) config('pilotage.recommandation.voisins', 5),
            seuil: $seuil ?? (float) config('pilotage.recommandation.seuil', 0.15),
            bonusMemeArtisan: $bonusMemeArtisan ?? (float) config('pilotage.recommandation.bonus_meme_artisan', 1.15),
            exclureStockEpuise: $exclureStockEpuise ?? (bool) config('pilotage.recommandation.exclure_stock_epuise', false),
            restreindre: $restreindre,
        );
    }

    public function avec(
        ?int $limite = null,
        ?float $seuil = null,
        ?float $bonusMemeArtisan = null,
        ?bool $exclureStockEpuise = null,
        ?Closure $restreindre = null,
    ): self {
        return new self(
            limite: $limite ?? $this->limite,
            seuil: $seuil ?? $this->seuil,
            bonusMemeArtisan: $bonusMemeArtisan ?? $this->bonusMemeArtisan,
            exclureStockEpuise: $exclureStockEpuise ?? $this->exclureStockEpuise,
            restreindre: $restreindre ?? $this->restreindre,
        );
    }

    /**
     * Ce que le dossier doit pouvoir citer : les valeurs effectivement
     * appliquées, pas celles du fichier de configuration.
     *
     * @return array<string, int|float|bool>
     */
    public function enTableau(): array
    {
        return [
            'voisins' => $this->limite,
            'seuil' => $this->seuil,
            'bonus_meme_artisan' => $this->bonusMemeArtisan,
            'exclure_stock_epuise' => $this->exclureStockEpuise,
        ];
    }
}
