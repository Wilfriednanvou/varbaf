<?php

namespace Tests\Doubles;

use Illuminate\Support\Collection;
use Modules\Pilotage\Contracts\ModeleDeLangage;
use Modules\Pilotage\Recherche\SegmentTrouve;

/**
 * Un modèle de langage sans réseau et sans clé.
 *
 * **Pourquoi il existe.** Ce qu'il faut éprouver ici n'est pas la qualité
 * d'un modèle — ce n'est pas notre code, et elle varie d'un appel à
 * l'autre — mais le comportement du système *devant* ce qu'un modèle lui
 * rend : une rédaction correcte, un refus de rédiger, ou une rédaction
 * qui avance un chiffre absent des extraits. Les trois se posent ici en
 * une ligne, et le troisième est celui qui compte.
 *
 * La règle est une fonction de la question et des extraits vers un texte
 * ou `null`, ce qui permet d'écrire des cas **voulus** plutôt que
 * d'espérer les rencontrer.
 */
final class ModeleDeLangageDeTest implements ModeleDeLangage
{
    /** @var callable(string, Collection<int, SegmentTrouve>): ?string */
    private $regle;

    /** @var callable(string): ?string */
    private $accueil;

    /** @var callable(string, array): ?string */
    private $reformulation;

    /**
     * @param  (callable(string, Collection<int, SegmentTrouve>): ?string)|null  $regle
     */
    public function __construct(
        ?callable $regle = null,
        private bool $disponible = true,
        private string $nom = 'Modèle de test',
    ) {
        $this->regle = $regle ?? static fn (string $question, Collection $extraits): string => 'Rédaction de test.';
        $this->accueil = static fn (string $saisie): string => 'Bonjour. Je réponds sur les artisans, les produits et les chiffres du village.';
        $this->reformulation = static fn (string $saisie, array $historique): ?string => null;
    }

    /**
     * Le modèle dont l'accueil avance un chiffre.
     *
     * Sur ce chemin il n'y a aucun extrait à confronter : la règle est
     * donc plus stricte qu'ailleurs — aucun chiffre n'est toléré, quel
     * qu'il soit. Ce cas existe pour éprouver ce rejet-là.
     */
    public function accueilAvecChiffre(string $texte = 'Bonjour ! Le village compte 47 artisans.'): self
    {
        $this->accueil = static fn (): string => $texte;

        return $this;
    }

    /**
     * Le modèle qui rend une question autonome à partir d'une suite.
     */
    public function reformulantEn(string $question): self
    {
        $this->reformulation = static fn (): string => $question;

        return $this;
    }

    /**
     * Le modèle qui ne rédige jamais — l'équivalent d'une absence de clé.
     */
    public static function muet(): self
    {
        return new self(static fn (): ?string => null, disponible: false);
    }

    /**
     * Le modèle qui invente un chiffre.
     *
     * Le cas que `GardeDesChiffres` existe pour attraper, et qu'aucune
     * consigne envoyée au modèle ne peut garantir.
     */
    public static function affabulateur(string $texte = 'Le village compte 47 artisans en vannerie.'): self
    {
        return new self(static fn (): string => $texte);
    }

    /**
     * Le modèle qui recopie fidèlement les titres qu'on lui donne.
     *
     * Utile pour vérifier qu'une rédaction honnête traverse les
     * garde-fous sans être écartée : un contrôle qui refuserait tout
     * serait aussi inutile qu'un contrôle qui n'attrape rien.
     */
    public static function fidele(): self
    {
        return new self(static function (string $question, Collection $extraits): string {
            $titres = $extraits->map(fn (SegmentTrouve $s): string => $s->titre)->implode(', ');

            return "Le corpus mentionne : {$titres}.";
        });
    }

    public function nom(): string
    {
        return $this->nom;
    }

    public function estDisponible(): bool
    {
        return $this->disponible;
    }

    public function redigerDepuisExtraits(string $question, Collection $extraits): ?string
    {
        if (! $this->disponible) {
            return null;
        }

        return ($this->regle)($question, $extraits);
    }

    public function accueillir(string $saisie): ?string
    {
        if (! $this->disponible) {
            return null;
        }

        return ($this->accueil)($saisie);
    }

    public function reformuler(string $saisie, array $historique): ?string
    {
        if (! $this->disponible) {
            return null;
        }

        return ($this->reformulation)($saisie, $historique);
    }
}
