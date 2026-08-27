<?php

namespace Modules\Pilotage\Embeddings;

/**
 * Les deux seules opérations que la branche dense demande.
 *
 * **Les vecteurs sont normés à l'indexation, une fois pour toutes.**
 * Le cosinus de deux vecteurs de norme 1 est leur produit scalaire :
 * la division disparaît, et avec elle la question de la norme nulle à
 * chaque comparaison. C'est exactement le raisonnement que la branche
 * lexicale tient déjà en stockant `fiches_lexicales.norme` — le calcul
 * coûteux se fait quand on écrit, pas quand on lit, parce qu'on écrit
 * une fois et qu'on lit à chaque question.
 */
final class Vecteurs
{
    /**
     * Ramène un vecteur à la norme 1.
     *
     * Un vecteur nul est rendu tel quel : il n'y a pas de direction à
     * conserver, et le diviser par zéro produirait des `NAN` qui se
     * propageraient silencieusement jusqu'au classement.
     *
     * @param  array<int, float>  $vecteur
     * @return array<int, float>
     */
    public static function normer(array $vecteur): array
    {
        $somme = 0.0;

        foreach ($vecteur as $valeur) {
            $somme += $valeur * $valeur;
        }

        if ($somme <= 0.0) {
            return array_map(static fn ($v): float => (float) $v, array_values($vecteur));
        }

        $norme = sqrt($somme);

        return array_map(static fn ($v): float => ((float) $v) / $norme, array_values($vecteur));
    }

    /**
     * Le produit scalaire — c'est-à-dire le cosinus, sur des vecteurs
     * déjà normés.
     *
     * Deux vecteurs de dimensions différentes ne se comparent pas :
     * plutôt que de sommer sur la plus courte des deux, ce qui rendrait
     * une valeur d'apparence normale, on rend zéro. Le cas ne devrait
     * pas se produire — le moteur écarte déjà les vecteurs d'un autre
     * modèle — et s'il se produit, il vaut mieux qu'il ne ressemble pas
     * à un rapprochement faible.
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    public static function cosinus(array $a, array $b): float
    {
        if ($a === [] || count($a) !== count($b)) {
            return 0.0;
        }

        $produit = 0.0;

        foreach ($a as $index => $valeur) {
            $produit += $valeur * $b[$index];
        }

        return $produit;
    }
}
