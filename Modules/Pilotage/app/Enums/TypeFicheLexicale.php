<?php

namespace Modules\Pilotage\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Les deux natures d'objet que le corpus décrit.
 *
 * Le type n'est pas qu'une étiquette : il décide de la composition de la
 * fiche, du jeu de poids appliqué, et de ce qu'une recommandation
 * compare. On ne recommande pas un artisan à la place d'un produit.
 */
enum TypeFicheLexicale: string implements HasLabel
{
    case PRODUIT = 'PRODUIT';

    case ARTISAN = 'ARTISAN';

    public function getLabel(): string
    {
        return match ($this) {
            self::PRODUIT => 'Produit',
            self::ARTISAN => 'Artisan',
        };
    }

    /**
     * Clé du bloc de poids correspondant dans `config/pilotage.php`.
     */
    public function cleDePoids(): string
    {
        return match ($this) {
            self::PRODUIT => 'produit',
            self::ARTISAN => 'artisan',
        };
    }

    /**
     * @return array<int, self>
     */
    public static function toutes(): array
    {
        return self::cases();
    }
}
