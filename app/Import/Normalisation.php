<?php

namespace App\Import;

use Illuminate\Support\Str;

/**
 * Petites transformations de chaînes partagées par tout l'import.
 *
 * Rassemblées ici pour une raison précise : la comparaison de deux noms
 * d'artisans, le regroupement de deux désignations de produit et la
 * reconnaissance d'un code de boutique doivent employer **exactement**
 * la même normalisation. Deux implémentations proches finiraient par
 * diverger sur un accent ou une apostrophe, et le rapprochement
 * d'entités deviendrait irreproductible.
 */
class Normalisation
{
    /**
     * Forme de comparaison : minuscules, sans accent, sans ponctuation,
     * espaces réduits.
     *
     * « NGOUNJOU / Sylvie » et « ngounjou sylvie » se rejoignent ici, ce
     * qui évite au rapprochement par distance de chaînes d'avoir à
     * dépenser son seuil sur des différences de saisie.
     */
    public static function comparable(?string $valeur): string
    {
        $valeur = Str::ascii(Str::lower(trim((string) $valeur)));
        $valeur = preg_replace('/[^a-z0-9]+/', ' ', $valeur) ?? '';

        return trim(preg_replace('/\s+/', ' ', $valeur) ?? '');
    }

    /**
     * Forme d'affichage : espaces réduits, rien d'autre.
     *
     * Ce qui sera montré à la coordination garde la casse et les accents
     * du registre : c'est sous cette forme qu'elle reconnaîtra ses
     * propres écritures.
     */
    public static function lisible(?string $valeur): string
    {
        $valeur = trim((string) $valeur);
        $valeur = preg_replace('/\s+/u', ' ', $valeur) ?? '';

        return trim($valeur, " \t\n\r\0\x0B-");
    }

    /**
     * La cellule porte-t-elle un guillemet de répétition ?
     *
     * Dans un registre tenu à la main, `-"-` veut dire « comme
     * au-dessus ». C'est une écriture de la source, pas une absence :
     * la reprendre revient à lire le registre, non à supposer une
     * valeur. La distinction compte, parce que l'énoncé interdit de
     * supposer un nom d'artisan et n'interdit pas de lire celui que la
     * ligne désigne.
     */
    public static function estRepetition(?string $valeur): bool
    {
        $valeur = trim((string) $valeur);

        if ($valeur === '') {
            return false;
        }

        return (bool) preg_match('/^[\-–—"\'\x{201C}\x{201D}\s]+$/u', $valeur);
    }

    /**
     * Entier d'un montant, d'un prix ou d'une quantité.
     *
     * Le franc CFA n'a pas de subdivision : tout ce qui suit une
     * virgule ou un point est écarté plutôt qu'arrondi, et les
     * séparateurs de milliers — espace ordinaire ou insécable — sont
     * retirés. Une cellule vide, un tiret ou un point d'interrogation
     * donnent `null`, jamais zéro : « inconnu » et « rien » ne se
     * confondent pas.
     */
    public static function entier(?string $valeur): ?int
    {
        $valeur = trim((string) $valeur);

        if ($valeur === '') {
            return null;
        }

        // Sépare la partie entière d'une éventuelle décimale.
        $valeur = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $valeur) ?? '';
        $valeur = preg_split('/[.,]/', $valeur)[0] ?? '';

        $chiffres = preg_replace('/\D/', '', $valeur) ?? '';

        return $chiffres === '' ? null : (int) $chiffres;
    }

    /**
     * Code de boutique tel qu'il sera confronté au parc : majuscules,
     * espaces réduits.
     */
    public static function codeBoutique(?string $valeur): string
    {
        $valeur = Str::upper(Str::ascii(trim((string) $valeur)));

        return trim(preg_replace('/\s+/', ' ', $valeur) ?? '');
    }
}
