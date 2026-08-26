<?php

namespace Modules\Pilotage\Assistant;

use Modules\Pilotage\Enums\CategorieQuestion;
use Modules\Pilotage\Indexation\Normalisateur;

/**
 * Décide qui répond : le calcul, ou la recherche.
 *
 * **Une seule décision, prise une seule fois.** Il n'y a pas de repli
 * de la branche calcul vers la branche recherche : une question
 * d'agrégation dont l'intention est reconnue reste sur le calcul, même
 * si le calcul rend zéro. Autoriser la bascule ferait qu'un chiffre
 * d'affaires nul se transformerait en extraits de fiches produit, et
 * un lecteur pressé y lirait une réponse à sa question financière.
 *
 * L'inverse est vrai aussi : une question descriptive ne remonte jamais
 * vers `RapportService`, faute de quoi la garantie « aucun montant par
 * proximité textuelle » n'en serait plus une.
 */
class Routeur
{
    public function __construct(
        protected CatalogueDIntentions $catalogue,
        protected ?Normalisateur $normalisateur = null,
    ) {}

    public function classer(string $question): ResultatDeRoutage
    {
        $normalisateur = $this->normalisateur ?? Normalisateur::depuisLaConfiguration();
        $termes = $normalisateur->decouper($question);

        if ($termes === []) {
            return new ResultatDeRoutage(CategorieQuestion::DESCRIPTIVE, null, 0, []);
        }

        $presents = array_fill_keys($termes, 1);
        $seuil = max(2, (int) config('pilotage.assistant.seuil_intention', 2));

        $meilleure = null;
        $meilleurScore = 0;
        $scores = [];

        foreach ($this->catalogue->toutes() as $intention) {
            $score = $intention->score($presents);

            if ($score > 0) {
                $scores[$intention->cle] = $score;
            }

            // Strictement supérieur : à égalité, la première intention
            // déclarée l'emporte. Le catalogue est donc ordonné du plus
            // général au plus spécifique, et l'ordre de lecture du
            // fichier est l'ordre de priorité — ce qui se vérifie en
            // lisant, sans avoir à exécuter.
            if ($score > $meilleurScore) {
                $meilleurScore = $score;
                $meilleure = $intention;
            }
        }

        arsort($scores);

        if ($meilleure === null || $meilleurScore < $seuil) {
            return new ResultatDeRoutage(CategorieQuestion::DESCRIPTIVE, null, $meilleurScore, $scores);
        }

        return new ResultatDeRoutage(CategorieQuestion::AGREGATION, $meilleure, $meilleurScore, $scores);
    }
}
