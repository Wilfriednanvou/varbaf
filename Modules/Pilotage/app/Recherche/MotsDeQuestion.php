<?php

namespace Modules\Pilotage\Recherche;

/**
 * Les mots qui servent à poser une question, pas à la préciser.
 *
 * **Pourquoi une liste distincte de `MotsVides`, et non trois entrées de
 * plus dedans.** Les deux listes écartent des termes, mais pour des
 * motifs opposés, et les confondre effacerait le raisonnement de chacune.
 *
 * `MotsVides` retient les mots outils du français — articles,
 * prépositions, auxiliaires — et son docblock défend explicitement de
 * l'étendre : décider à la place du corpus quels mots ne discriminent pas
 * serait usurper le travail de l'IDF, qui mesure cela très bien. Ce
 * raisonnement tient, et il n'est pas remis en cause ici.
 *
 * Cette liste-ci écarte autre chose : les noms génériques par lesquels on
 * **désigne** ce qu'on cherche. « Quels produits en vannerie ? » ne parle
 * pas de « produit », elle parle de vannerie ; « produit » y est
 * l'échafaudage de la question. La distinction n'est donc pas
 * statistique mais grammaticale, et elle ne vaut que du côté de la
 * question — jamais du côté du corpus.
 *
 * **Le cas concret qui l'a rendue nécessaire.** L'IDF donne à « produit »
 * un poids fort, et il a raison : le terme est *rare* dans le corpus du
 * village, porté par les seules fiches dont la désignation d'origine est
 * un vide-poche — « Produit », « Produit dents ». Une question contenant
 * « produits » remontait donc ces fiches-là en tête, devant les articles
 * réellement demandés. Rien n'était en panne : le vocabulaire des
 * questions et celui des fiches ne se recouvraient simplement pas.
 *
 * **Ce que la liste ne fait jamais.** Vider une question. Si tous ses
 * termes sont de l'échafaudage — « Quels produits ? » —, ils sont
 * conservés : une question maigre doit rendre un résultat maigre, pas un
 * refus fabriqué par un filtre. Voir `VecteurDeQuestion::depuis()`.
 *
 * Les termes sont écrits sans accent : ils sont comparés après la
 * normalisation, jamais avant.
 */
final class MotsDeQuestion
{
    /**
     * @var array<int, string>
     */
    private const ECHAFAUDAGE = [
        // Le nom générique de ce que le catalogue contient. Les fiches
        // portent des désignations concrètes — « panier », « collier » —,
        // jamais ces mots-là, sauf quand la source était vide.
        'produit', 'produits', 'article', 'articles',
        'objet', 'objets', 'chose', 'choses', 'truc', 'trucs',

        // Le nom générique de ce que le corpus décrit. Une question qui
        // dit « liste des artisans en vannerie » cherche la vannerie ;
        // « liste » ne l'aide pas à la trouver.
        'liste', 'listes', 'ensemble', 'exemple', 'exemples',
        'information', 'informations', 'renseignement', 'renseignements',
        'detail', 'details', 'donnee', 'donnees',
    ];

    /**
     * @var array<string, true>|null
     */
    private static ?array $index = null;

    public static function contient(string $terme): bool
    {
        self::$index ??= array_fill_keys(self::ECHAFAUDAGE, true);

        return isset(self::$index[$terme]);
    }

    /**
     * Retire l'échafaudage — sauf s'il ne reste rien.
     *
     * @param  array<int, string>  $termes
     * @return array<int, string>
     */
    public static function elaguer(array $termes): array
    {
        $restants = array_values(array_filter(
            $termes,
            static fn (string $terme): bool => ! self::contient($terme),
        ));

        // Une question entièrement faite d'échafaudage garde le sien :
        // mieux vaut une réponse large qu'un refus dont la cause serait
        // un filtre et non le corpus.
        return $restants === [] ? $termes : $restants;
    }

    /**
     * @return array<int, string>
     */
    public static function liste(): array
    {
        return array_values(array_unique(self::ECHAFAUDAGE));
    }
}
