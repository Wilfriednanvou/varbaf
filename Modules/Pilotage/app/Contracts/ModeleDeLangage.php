<?php

namespace Modules\Pilotage\Contracts;

use Illuminate\Support\Collection;
use Modules\Pilotage\Recherche\SegmentTrouve;

/**
 * Ce qu'un modèle de langage a le droit de faire dans ce système.
 *
 * **La forme de ce port est la contrainte, pas une commodité.** Une
 * interface générique — « pose une question au modèle, reçois du texte » —
 * autoriserait n'importe quel usage, et le premier venu serait de lui
 * demander un montant. Ce contrat n'expose qu'une opération, et cette
 * opération reçoit déjà les extraits : le modèle ne va rien chercher, il
 * met en français ce qu'on lui donne. Il ne peut donc pas produire un
 * chiffre, non pas parce qu'on le lui interdit dans une consigne, mais
 * parce qu'il n'a accès à rien d'où un chiffre pourrait venir.
 *
 * **Ce que le port n'expose délibérément pas.** La conception initiale
 * prévoyait aussi `classer()`, pour rattraper le routeur quand sa
 * confiance passe sous le seuil. Écarté le 27/08 : le routage décide
 * *quelle branche répond*, donc se situe en amont de la frontière entre
 * l'agrégation calculée et le descriptif. Un appel non déterministe à cet
 * endroit affaiblirait la garantie centrale du volet IA — aucun montant
 * produit par proximité textuelle — pour un gain que
 * « varbaf:evaluer-assistant » mesure déjà à zéro : la classification est
 * à 100 % sur les 48 questions du jeu d'évaluation. On ne remplace pas un
 * composant qui ne se trompe jamais par un composant qui peut se tromper.
 *
 * **La rédaction, elle, est en aval de la frontière** et sous la
 * surveillance mécanique de `GardeDesChiffres`, qui relit le texte produit
 * et bascule en refus si un groupe de chiffres n'apparaît dans aucun
 * extrait. C'est ce contrôle, et lui seul, qui rend un modèle génératif
 * tenable ici.
 */
interface ModeleDeLangage
{
    /**
     * Le nom du modèle, tel qu'il s'affiche à côté de la réponse.
     */
    public function nom(): string;

    /**
     * Le modèle peut-il être sollicité ?
     *
     * **Sans appel réseau distant.** Cette question est posée sur le
     * chemin d'une réponse, là où l'utilisateur attend : sonder un
     * service en ligne ajouterait un aller-retour à chaque question
     * descriptive. Une clé absente suffit à répondre non, et une panne se
     * manifestera au moment de l'appel — où le repli existe déjà. Un
     * service local, lui, se sonde : la milliseconde qu'il coûte est sans
     * commune mesure avec le budget qu'on attendrait pour rien s'il
     * n'était pas lancé.
     */
    public function estDisponible(): bool;

    /**
     * Met les extraits en français suivi, ou rend `null`.
     *
     * **`null` n'est pas une erreur, c'est un refus de rédiger** — service
     * injoignable, délai dépassé, réponse vide, quota épuisé. L'appelant
     * retombe alors sur la composition mécanique, qui est le comportement
     * livré depuis le début du chantier. Le chemin dégradé n'est pas un
     * chemin de secours écrit à part : c'est le chemin nominal d'hier,
     * qu'on n'a jamais retiré.
     *
     * @param  Collection<int, SegmentTrouve>  $extraits  les seuls matériaux autorisés
     */
    public function redigerDepuisExtraits(string $question, Collection $extraits): ?string;
}
