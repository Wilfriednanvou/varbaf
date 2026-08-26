<?php

namespace Modules\Pilotage\Assistant;

use Illuminate\Support\Collection;
use Modules\Pilotage\Recherche\SegmentTrouve;

/**
 * Vérifie qu'une réponse descriptive n'avance aucun chiffre sans source.
 *
 * **Pourquoi une vérification et pas seulement une discipline.** La
 * branche descriptive ne compose sa réponse qu'à partir d'extraits
 * retrouvés : en théorie, elle ne peut donc pas inventer de nombre. En
 * pratique, une phrase de liaison — « 3 fiches correspondent » — en
 * introduit un qui ne vient d'aucune source, et personne ne s'en aperçoit
 * avant la soutenance. Cette classe transforme la discipline en
 * propriété testable : elle relit le texte produit et refuse tout groupe
 * de chiffres absent des extraits.
 *
 * Le contrôle porte sur les **suites de chiffres**, pas sur les nombres
 * écrits en lettres. Un « trois » en toutes lettres passerait ; c'est
 * assumé, parce qu'un lecteur ne lit pas un montant en lettres dans un
 * état financier, et parce qu'élargir le contrôle au vocabulaire
 * numéral rendrait la règle floue là où elle doit être mécanique.
 */
class GardeDesChiffres
{
    /**
     * Les chiffres du texte qui n'apparaissent dans aucune source.
     *
     * @param  Collection<int, SegmentTrouve>  $sources
     * @return array<int, string>
     */
    public function chiffresSansSource(string $texte, Collection $sources): array
    {
        $duTexte = $this->suitesDeChiffres($texte);

        if ($duTexte === []) {
            return [];
        }

        $adossement = $sources
            ->map(fn (SegmentTrouve $segment): string => $segment->titre.' '.$segment->extrait)
            ->implode(' ');

        $autorises = $this->suitesDeChiffres($adossement);

        return array_values(array_diff($duTexte, $autorises));
    }

    /**
     * @param  Collection<int, SegmentTrouve>  $sources
     */
    public function estAdosse(string $texte, Collection $sources): bool
    {
        return $this->chiffresSansSource($texte, $sources) === [];
    }

    /**
     * @return array<int, string>
     */
    protected function suitesDeChiffres(string $texte): array
    {
        // Les séparateurs de milliers sont retirés avant comparaison :
        // « 12 000 » dans une réponse et « 12000 » dans une fiche
        // désignent le même nombre, et les traiter comme deux chaînes
        // différentes ferait échouer le contrôle sur une question de
        // typographie.
        $texte = preg_replace('/(?<=\d)[\s\x{202F}\x{00A0}](?=\d)/u', '', $texte) ?? $texte;

        preg_match_all('/\d+/', $texte, $trouves);

        return array_values(array_unique($trouves[0] ?? []));
    }
}
