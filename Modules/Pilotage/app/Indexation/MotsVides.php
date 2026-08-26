<?php

namespace Modules\Pilotage\Indexation;

/**
 * Mots vides du français.
 *
 * Liste embarquée plutôt qu'importée : aucun paquet supplémentaire n'est
 * justifié pour cent cinquante chaînes, et une liste qu'on peut lire est
 * une liste qu'on peut défendre. Elle est volontairement courte — elle
 * retient les mots outils et rien d'autre. Écarter au-delà reviendrait à
 * décider à la place du corpus quels mots ne discriminent pas, alors que
 * l'IDF est précisément là pour le mesurer : un mot présent partout y
 * tombe de lui-même.
 *
 * Les termes sont écrits sans accent : ils sont comparés après la
 * normalisation, jamais avant.
 */
final class MotsVides
{
    /**
     * @var array<int, string>
     */
    private const FRANCAIS = [
        // Articles et déterminants
        'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'au', 'aux',
        'ce', 'cet', 'cette', 'ces', 'son', 'sa', 'ses', 'leur', 'leurs',
        'mon', 'ma', 'mes', 'ton', 'ta', 'tes', 'notre', 'nos', 'votre', 'vos',
        'tout', 'toute', 'tous', 'toutes', 'meme', 'memes', 'autre', 'autres',
        'quel', 'quelle', 'quels', 'quelles', 'chaque', 'plusieurs', 'aucun', 'aucune',

        // Pronoms
        'je', 'tu', 'il', 'elle', 'on', 'nous', 'vous', 'ils', 'elles',
        'moi', 'toi', 'lui', 'eux', 'soi', 'que', 'qui', 'quoi', 'dont',
        'celui', 'celle', 'ceux', 'celles', 'cela', 'ceci',

        // Prépositions et conjonctions
        'et', 'ou', 'ni', 'mais', 'donc', 'car', 'or', 'si', 'sinon',
        'dans', 'sur', 'sous', 'par', 'pour', 'avec', 'sans', 'chez',
        'vers', 'entre', 'depuis', 'pendant', 'avant', 'apres', 'contre',
        'selon', 'malgre', 'parmi', 'jusque', 'jusqu', 'lors', 'lorsque',
        'comme', 'quand', 'puis', 'ensuite', 'alors', 'aussi', 'ainsi',

        // Auxiliaires et verbes outils
        'est', 'sont', 'etait', 'etaient', 'ete', 'etre', 'suis', 'sommes', 'etes',
        'ai', 'as', 'ont', 'avons', 'avez', 'avait', 'avaient', 'avoir', 'eu',
        'sera', 'seront', 'serait', 'fait', 'faire', 'peut', 'peuvent', 'doit', 'doivent',
        'y', 'en',

        // Adverbes fréquents
        'ne', 'pas', 'plus', 'moins', 'tres', 'bien', 'trop', 'peu',
        'encore', 'deja', 'toujours', 'jamais', 'ici', 'la', 'ou',
        'oui', 'non', 'cet', 'ceux',
    ];

    /**
     * @var array<string, true>|null
     */
    private static ?array $index = null;

    public static function contient(string $terme): bool
    {
        self::$index ??= array_fill_keys(self::FRANCAIS, true);

        return isset(self::$index[$terme]);
    }

    /**
     * @return array<int, string>
     */
    public static function liste(): array
    {
        return array_values(array_unique(self::FRANCAIS));
    }
}
