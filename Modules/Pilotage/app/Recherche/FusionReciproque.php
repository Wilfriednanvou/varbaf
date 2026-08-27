<?php

namespace Modules\Pilotage\Recherche;

use Illuminate\Support\Collection;

/**
 * La fusion par rangs réciproques (RRF).
 *
 * **Pourquoi les rangs et non les scores.** Un cosinus TF-IDF et un
 * cosinus dense ne vivent pas sur la même échelle et n'ont pas la même
 * distribution : 0,30 est un rapprochement solide pour le premier et un
 * bruit de fond pour le second. Les additionner — même pondérés —
 * demanderait de calibrer une correspondance entre les deux, et de la
 * recalibrer à chaque réindexation, puisque l'IDF bouge avec le corpus.
 * Les rangs, eux, sont comparables par construction : « premier chez le
 * lexical » veut dire la même chose que « premier chez le dense », quel
 * que soit l'état du corpus.
 *
 *     score(d) = Σ_moteurs  poids_moteur / (k + rang_moteur(d))
 *
 * `k` amortit l'écart entre les premiers rangs. À k = 60, passer du
 * rang 1 au rang 2 fait perdre 1,6 % — assez pour classer, trop peu
 * pour qu'un moteur impose seul son premier résultat contre l'avis de
 * l'autre. C'est la valeur d'usage de la littérature, et elle est
 * configurable pour pouvoir être justifiée plutôt que subie.
 *
 * **La fusion classe, elle ne filtre pas.** Chaque moteur a déjà écarté
 * ce qui n'atteignait pas son propre seuil ; ce qui arrive ici a donc
 * passé une porte. Rajouter un seuil sur le score fusionné reviendrait à
 * filtrer une seconde fois sur une grandeur qui ne mesure plus une
 * ressemblance mais un accord — c'est la même distinction que le seuil
 * et la majoration du même artisan dans la recommandation.
 *
 * **Le score rendu est normalisé.** Il est divisé par le maximum
 * théorique, celui d'un passage classé premier par tous les moteurs qui
 * ont effectivement répondu. Il se lit donc comme un degré d'accord :
 * 1,0 signifie « les deux moteurs le mettent en tête », 0,5 « un seul
 * des deux le connaît ». C'est une grandeur différente d'une similarité,
 * et `MoteurHybride` le dit à l'écran plutôt que de laisser croire à un
 * cosinus.
 */
final class FusionReciproque
{
    /**
     * @param  array<string, Collection<int, SegmentTrouve>>  $parMoteur  clé du moteur => résultats ordonnés
     * @param  array<string, float>  $poids
     * @return Collection<int, SegmentTrouve>
     */
    public static function fusionner(array $parMoteur, array $poids, float $k, int $limite): Collection
    {
        /** @var array<int, array{segment: SegmentTrouve, score: float}> $cumul */
        $cumul = [];
        $maximum = 0.0;

        foreach ($parMoteur as $cle => $segments) {
            if ($segments->isEmpty()) {
                continue;
            }

            $poidsDuMoteur = (float) ($poids[$cle] ?? 1.0);

            // Le maximum ne compte que les moteurs qui ont répondu. Sans
            // cela, un système où le dense est arrêté plafonnerait tous
            // ses scores à la moitié et donnerait l'impression d'une
            // recherche dégradée là où la branche lexicale a fait
            // exactement son travail habituel.
            $maximum += $poidsDuMoteur / ($k + 1);

            $rang = 0;

            foreach ($segments as $segment) {
                $rang++;
                $apport = $poidsDuMoteur / ($k + $rang);

                if (! isset($cumul[$segment->ficheId])) {
                    $cumul[$segment->ficheId] = ['segment' => $segment, 'score' => 0.0];
                }

                $cumul[$segment->ficheId]['score'] += $apport;
            }
        }

        if ($cumul === [] || $maximum <= 0.0) {
            return new Collection();
        }

        // Départage stable sur l'identifiant de fiche, comme partout
        // ailleurs : deux passages au même score doivent sortir dans le
        // même ordre d'une exécution à l'autre.
        uasort($cumul, function (array $a, array $b): int {
            return $b['score'] <=> $a['score']
                ?: $a['segment']->ficheId <=> $b['segment']->ficheId;
        });

        $retenus = array_slice($cumul, 0, $limite, true);

        return new Collection(array_map(
            static fn (array $entree): SegmentTrouve => new SegmentTrouve(
                ficheId: $entree['segment']->ficheId,
                type: $entree['segment']->type,
                sourceId: $entree['segment']->sourceId,
                titre: $entree['segment']->titre,
                extrait: $entree['segment']->extrait,
                similarite: min(1.0, $entree['score'] / $maximum),
            ),
            array_values($retenus),
        ));
    }
}
