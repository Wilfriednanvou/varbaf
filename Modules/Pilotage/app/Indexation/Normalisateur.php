<?php

namespace Modules\Pilotage\Indexation;

/**
 * Découpe un texte français en termes indexables.
 *
 * **Le même découpage sert au corpus et aux questions.** C'est la seule
 * propriété qui compte : une question normalisée autrement que le corpus
 * ne retrouverait rien. C'est aussi pourquoi la classe est sans état et
 * ses paramètres passés au constructeur — deux instances configurées
 * différemment ne doivent jamais indexer et interroger le même index.
 *
 * Pas de racinisation au-delà du pluriel. Aucune bibliothèque de
 * racinisation française ne s'installe sans dépendance, et une
 * racinisation approximative rapproche des mots qui n'ont rien à voir —
 * ce qui coûte plus cher que les rapprochements qu'elle fait gagner.
 */
final class Normalisateur
{
    /**
     * Table de dépliage des caractères accentués.
     *
     * Écrite à la main plutôt que confiée à `iconv` ou à l'extension
     * `intl` : l'une comme l'autre dépendent de la locale du système,
     * et un index qui change de contenu selon la machine qui l'a
     * construit n'est pas un index.
     */
    private const ACCENTS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'æ' => 'ae', 'œ' => 'oe', 'ß' => 'ss',
    ];

    public function __construct(
        private readonly int $longueurMinimale = 3,
        private readonly int $longueurMinimaleSingularisation = 5,
    ) {}

    public static function depuisLaConfiguration(): self
    {
        return new self(
            longueurMinimale: (int) config('pilotage.index.longueur_minimale_terme', 3),
            longueurMinimaleSingularisation: (int) config('pilotage.index.longueur_minimale_singularisation', 5),
        );
    }

    /**
     * Les termes retenus d'un texte, dans l'ordre d'apparition.
     *
     * Un texte vide ou nul rend un tableau vide : c'est le comportement
     * qui permet à un champ non renseigné de ne contribuer aucun terme
     * sans qu'aucun appelant ait à le tester.
     *
     * @return array<int, string>
     */
    public function decouper(?string $texte): array
    {
        if ($texte === null || trim($texte) === '') {
            return [];
        }

        $texte = mb_strtolower($texte, 'UTF-8');
        $texte = strtr($texte, self::ACCENTS);

        // Tout ce qui n'est ni lettre latine ni chiffre sépare deux
        // termes : apostrophes, tirets, ponctuation, unités collées.
        $texte = preg_replace('/[^a-z0-9]+/', ' ', $texte) ?? '';

        $termes = [];

        foreach (explode(' ', $texte) as $brut) {
            $terme = $this->retenir($brut);

            if ($terme !== null) {
                $termes[] = $terme;
            }
        }

        return $termes;
    }

    /**
     * Les fréquences pondérées d'un texte, terme => nombre.
     *
     * Le poids est un facteur de répétition : un champ de poids 3 fait
     * compter chacun de ses termes trois fois. C'est ce qui donne à la
     * désignation plus de force qu'à la description, sans introduire de
     * seconde formule à expliquer.
     *
     * @return array<string, int>
     */
    public function frequences(?string $texte, int $poids = 1): array
    {
        if ($poids < 1) {
            return [];
        }

        $frequences = [];

        foreach ($this->decouper($texte) as $terme) {
            $frequences[$terme] = ($frequences[$terme] ?? 0) + $poids;
        }

        return $frequences;
    }

    /**
     * Le terme retenu, ou null s'il est écarté.
     */
    private function retenir(string $brut): ?string
    {
        if ($brut === '') {
            return null;
        }

        // Un nombre nu ne décrit rien : « 500 » se retrouve dans un prix,
        // une contenance et une année. Un terme mixte comme « 50cl » est
        // conservé, lui : il porte une unité.
        if (ctype_digit($brut)) {
            return null;
        }

        if (strlen($brut) < $this->longueurMinimale) {
            return null;
        }

        if (MotsVides::contient($brut)) {
            return null;
        }

        $terme = $this->singulariser($brut);

        // La singularisation peut faire passer sous le seuil, et peut
        // faire tomber sur un mot vide — « les » n'arrive pas ici, mais
        // « ses » viendrait de « sess ». Les deux gardes sont donc
        // repassées après coup.
        if (strlen($terme) < $this->longueurMinimale || MotsVides::contient($terme)) {
            return null;
        }

        return $terme;
    }

    /**
     * Ramène un pluriel français au singulier, prudemment.
     *
     * Le seuil de longueur protège les singuliers terminés par s ou x —
     * « bois », « prix », « croix » — qu'une troncature aveugle
     * mutilerait. Au-delà, « miels » rejoint « miel » et « paniers »
     * rejoint « panier », ce qui est tout l'intérêt : deux fiches qui
     * parlent du même objet au singulier et au pluriel doivent se
     * retrouver.
     */
    private function singulariser(string $terme): string
    {
        if (strlen($terme) < $this->longueurMinimaleSingularisation) {
            return $terme;
        }

        $derniere = substr($terme, -1);

        if ($derniere === 's' || $derniere === 'x') {
            return substr($terme, 0, -1);
        }

        return $terme;
    }
}
