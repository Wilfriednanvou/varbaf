<?php

namespace Modules\Pilotage\Contracts;

/**
 * Ce qui sait transformer un texte en vecteur dense.
 *
 * **Le port est déclaré ici, pas chez le fournisseur.** Le Pilotage est
 * le consommateur : c'est lui qui dit de quoi il a besoin, et un client
 * Ollama, un service distant ou un double de test viennent s'y
 * conformer. C'est le même idiome que `JournalDeCaisse` entre le
 * Commerce et la Trésorerie, appliqué cette fois à une dépendance
 * externe plutôt qu'à un module.
 *
 * L'intérêt est direct : les tests n'ont jamais besoin qu'un modèle
 * tourne quelque part. Une suite de tests qui exigerait un service
 * lancé sur le poste ne s'exécuterait chez personne d'autre — et le
 * jour où elle échouerait, on ne saurait pas si c'est le code ou le
 * service.
 *
 * **Un fournisseur peut être absent, et ce n'est pas une panne.** Le
 * village n'a pas de modèle installé sur tous ses postes. Un
 * fournisseur indisponible fait taire la branche dense, la recherche
 * continue sur la branche lexicale, et l'écran nomme ce qui a répondu.
 */
interface FournisseurDEmbeddings
{
    /**
     * Le nom affiché à côté d'une réponse.
     */
    public function nom(): string;

    /**
     * Le modèle employé — porté par chaque vecteur en base.
     *
     * Deux modèles produisent des espaces vectoriels sans rapport : un
     * index construit avec l'un et interrogé avec l'autre rendrait des
     * rapprochements qui ont l'air de fonctionner et qui ne veulent
     * rien dire. C'est le pire des cas, et c'est pourquoi le nom du
     * modèle est stocké avec le vecteur plutôt que supposé.
     */
    public function modele(): string;

    /**
     * Le fournisseur répond-il, et le modèle est-il présent chez lui ?
     */
    public function estDisponible(): bool;

    /**
     * Les vecteurs de plusieurs textes, dans le même ordre.
     *
     * Une entrée dont le vecteur n'a pas pu être obtenu vaut `null` à
     * sa position : l'indexation la compte comme échouée et poursuit,
     * plutôt que d'abandonner tout le corpus sur une fiche.
     *
     * @param  array<int, string>  $textes
     * @return array<int, array<int, float>|null>
     */
    public function vecteurs(array $textes): array;

    /**
     * Le vecteur d'un texte, ou null si le fournisseur n'a pas répondu.
     *
     * @return array<int, float>|null
     */
    public function vecteur(string $texte): ?array;
}
