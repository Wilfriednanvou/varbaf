<?php

namespace Modules\Pilotage\Assistant;

use Closure;
use Modules\Pilotage\Indexation\Normalisateur;

/**
 * Une intention déclarée : ce qu'on sait reconnaître, et ce qu'on
 * appelle pour y répondre.
 *
 * **Rien n'est généré, tout est déclaré.** Le `resolveur` est une
 * fermeture écrite à la main qui appelle une méthode nommée de
 * `RapportService`. Aucune requête n'est fabriquée à partir de la
 * question, par quelque mécanisme que ce soit : le pire qui puisse
 * arriver à une question mal comprise est d'appeler le mauvais
 * indicateur — pas d'exécuter une instruction que personne n'a écrite.
 * Sur une application qui suit des flux financiers publics, cette
 * différence est la seule qui compte.
 *
 * Les mots-clés sont écrits en français et normalisés à la
 * construction, avec le tokeniser du corpus. « Chiffre d'affaires » et
 * « chiffres d'affaire » se ramènent donc au même couple de termes, et
 * la reconnaissance ne dépend ni des accents ni des pluriels.
 */
final readonly class Intention
{
    /**
     * @param  array<int, array<int, string>>  $expressions  déjà normalisées : chaque entrée est une suite de termes à trouver ensemble
     * @param  array<int, string>  $parametresRequis
     * @param  Closure  $resolveur  fn (ContexteDeCalcul $contexte, ParametresQuestion $p): array{texte: string, lignes: array}
     */
    public function __construct(
        public string $cle,
        public string $libelle,
        public array $expressions,
        public array $parametresRequis,
        public Closure $resolveur,
    ) {}

    /**
     * @param  array<int, string>  $expressions  en français, telles qu'on les dirait
     * @param  array<int, string>  $parametresRequis
     */
    public static function definir(
        string $cle,
        string $libelle,
        array $expressions,
        Closure $resolveur,
        array $parametresRequis = [],
        ?Normalisateur $normalisateur = null,
    ): self {
        $normalisateur ??= Normalisateur::depuisLaConfiguration();

        $normalisees = [];

        foreach ($expressions as $expression) {
            $termes = $normalisateur->decouper($expression);

            // Une expression dont tous les mots sont vides ne
            // reconnaîtrait rien et ferait gagner un score à toutes les
            // questions : elle est écartée à la construction.
            if ($termes !== []) {
                $normalisees[] = $termes;
            }
        }

        return new self($cle, $libelle, $normalisees, $parametresRequis, $resolveur);
    }

    /**
     * Le score de reconnaissance sur une question tokenisée.
     *
     * Une expression ne compte que si **tous** ses termes sont présents,
     * et elle rapporte autant de points qu'elle a de mots. « Chiffre
     * d'affaires » l'emporte donc sur « ventes » seul, ce qui est le
     * comportement voulu : plus une formule est spécifique, plus elle
     * doit être décisive.
     *
     * @param  array<string, int>  $presents  terme => 1, pour un test en temps constant
     */
    public function score(array $presents): int
    {
        $score = 0;

        foreach ($this->expressions as $termes) {
            $complete = true;

            foreach ($termes as $terme) {
                if (! isset($presents[$terme])) {
                    $complete = false;

                    break;
                }
            }

            if ($complete) {
                $score += count($termes);
            }
        }

        return $score;
    }
}
