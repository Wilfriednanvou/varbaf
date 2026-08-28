<?php

namespace Modules\Socle\Filament\Concerns;

use Illuminate\Contracts\Support\Htmlable;

/**
 * Rend le titre d'une page de ressource tel qu'il est écrit.
 *
 * **Le défaut corrigé.** Filament fabrique le titre d'une page de
 * ressource en passant le libellé pluriel dans `Str::title()`, qui
 * capitalise chaque mot. Un libellé français correctement déclaré —
 * « Corps de métier », « Attributions d'espaces » — s'affiche donc
 * « Corps De Métier » et « Attributions D'espaces », pendant que le fil
 * d'Ariane et le menu, eux, montrent la forme juste. Le même écran
 * porte alors deux orthographes du même mot.
 *
 * La règle typographique du français ne se déduit pas mot à mot : seule
 * la première lettre prend la majuscule, sauf nom propre. Aucune fonction
 * générique ne peut le savoir — c'est pourquoi le libellé est déclaré à
 * la main dans chaque ressource, et pourquoi il faut le rendre **tel
 * quel** plutôt que le retraiter.
 *
 * Ce trait vit dans le Socle parce que tous les modules en dépendent :
 * la règle de dépendance descendante l'autorise, et une copie par module
 * finirait par diverger.
 */
trait TitreLisible
{
    public function getTitle(): string | Htmlable
    {
        return static::getResource()::getPluralModelLabel();
    }
}
