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
 * **Les trois opérations sont étroites pour la même raison.** Rédiger ne
 * reçoit que des extraits déjà retrouvés. Accueillir ne reçoit rien du
 * village — donc ne peut rien en dire. Reformuler rend une *question* et
 * jamais une réponse, et cette question repart dans le même routeur
 * déterministe que les autres. Aucune des trois ne donne au modèle
 * l'occasion d'énoncer un fait sur le village de sa propre autorité.
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

    /**
     * Répond à une saisie qui n'est pas une question sur le village.
     *
     * **Ne reçoit que la saisie.** Ni extrait, ni indicateur, ni chiffre :
     * le modèle n'a accès à rien du village, donc il ne peut rien en
     * affirmer — la même mécanique que `redigerDepuisExtraits()`, poussée
     * jusqu'à ne rien donner du tout. Un bonjour, un remerciement, un mot
     * hors sujet : l'assistant salue et dit ce qu'il sait faire.
     *
     * L'appelant rejette toute sortie contenant un chiffre. Un accueil
     * n'a aucune raison d'en porter un, et cette règle-là ne dépend
     * d'aucune consigne envoyée au modèle.
     *
     * `null` rend la main à une phrase fixe : sans clé ni réseau,
     * l'assistant accueille quand même, simplement sans tournure.
     */
    public function accueillir(string $saisie): ?string;

    /**
     * Rend une question autonome à partir d'une question de suite.
     *
     * **Le modèle produit une question, jamais une réponse.** « Et en
     * juillet ? » devient « Quel est le chiffre d'affaires en juillet ? »,
     * et cette question-là traverse ensuite le routeur, l'extracteur de
     * paramètres et `RapportService` exactement comme si elle avait été
     * tapée. Le chiffre reste calculé ; le modèle n'a fait que rendre
     * explicite ce que l'utilisateur sous-entendait.
     *
     * **Ce n'est pas le `classer()` écarté le 27/08.** Là, le modèle
     * choisissait quelle branche répond — il décidait de la garantie.
     * Ici il ne choisit rien : la question reformulée est classée par le
     * routeur déterministe, dont la classification reste mesurée à 100 %.
     * Et la reformulation est **affichée** à l'utilisateur, donc une
     * dérive se voit au lieu de se produire en silence.
     *
     * @param  array<int, array{question: string, reponse: string}>  $historique  du plus ancien au plus récent
     */
    public function reformuler(string $saisie, array $historique): ?string;
}
