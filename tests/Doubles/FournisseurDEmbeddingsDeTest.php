<?php

namespace Tests\Doubles;

use Modules\Pilotage\Contracts\FournisseurDEmbeddings;

/**
 * Un fournisseur d'embeddings sans modèle et sans réseau.
 *
 * **Pourquoi il existe.** Une suite de tests qui exigerait qu'un modèle
 * tourne sur la machine ne s'exécuterait chez personne d'autre, et le
 * jour où elle échouerait on ne saurait pas si c'est le code ou le
 * service. Le port `FournisseurDEmbeddings` a été déclaré pour que ce
 * double soit possible ; c'est sa raison d'être principale, avant même
 * la possibilité de changer un jour de fournisseur.
 *
 * La règle est une fonction du texte vers un vecteur, ce qui permet
 * d'écrire des rapprochements **voulus** : « tout ce qui parle de
 * cuisine vit ici, le reste vit là ». On ne teste pas la qualité d'un
 * modèle d'embeddings — ce n'est pas notre code — mais le comportement
 * du moteur devant les vecteurs qu'un modèle lui rend.
 */
final class FournisseurDEmbeddingsDeTest implements FournisseurDEmbeddings
{
    /** @var callable(string): (array<int, float>|null) */
    private $regle;

    /**
     * @param  (callable(string): (array<int, float>|null))|null  $regle
     */
    public function __construct(
        ?callable $regle = null,
        private bool $disponible = true,
        private string $modele = 'modele-de-test',
    ) {
        $this->regle = $regle ?? static fn (string $texte): array => [1.0, 0.0, 0.0];
    }

    /**
     * Le fournisseur des rapprochements « cuisine ».
     *
     * Deux directions orthogonales : ce qui relève de la cuisine, et
     * tout le reste. Le cosinus vaut donc 1 ou 0 — assez tranché pour
     * qu'un test dise ce qu'il vérifie sans dépendre d'un seuil.
     */
    public static function parTheme(bool $disponible = true, string $modele = 'modele-de-test'): self
    {
        return new self(
            static function (string $texte): array {
                $minuscules = mb_strtolower($texte);

                foreach (['cuisine', 'marmite', 'terre cuite'] as $indice) {
                    if (str_contains($minuscules, $indice)) {
                        return [1.0, 0.0, 0.0];
                    }
                }

                return [0.0, 1.0, 0.0];
            },
            $disponible,
            $modele,
        );
    }

    public function nom(): string
    {
        return 'Fournisseur de test — '.$this->modele;
    }

    public function modele(): string
    {
        return $this->modele;
    }

    public function estDisponible(): bool
    {
        return $this->disponible;
    }

    /**
     * @param  array<int, string>  $textes
     * @return array<int, array<int, float>|null>
     */
    public function vecteurs(array $textes): array
    {
        if (! $this->disponible) {
            return array_fill(0, count($textes), null);
        }

        return array_map(fn (string $texte): ?array => ($this->regle)($texte), array_values($textes));
    }

    /**
     * @return array<int, float>|null
     */
    public function vecteur(string $texte): ?array
    {
        return $this->vecteurs([$texte])[0] ?? null;
    }
}
