<?php

namespace Modules\Portail\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Ce que le portail dit du stock — et rien de plus.
 *
 * Le stock réel n'est jamais affiché publiquement. Deux raisons, l'une
 * commerciale et l'autre de sécurité : annoncer « il en reste 2 »
 * dévalorise une pièce artisanale que son auteur peut refaire, et
 * publier les quantités du village renseigne gratuitement quiconque
 * s'intéresserait à ce qu'il y a dans les boutiques.
 *
 * La comparaison au zéro est faite par la base ; seul ce booléen
 * traverse vers PHP. La quantité ne quitte jamais le service.
 */
enum DisponibilitePortail: string implements HasColor, HasLabel
{
    case DISPONIBLE = 'DISPONIBLE';
    case SUR_COMMANDE = 'SUR_COMMANDE';

    public static function depuisPresenceEnStock(bool $enStock): self
    {
        return $enStock ? self::DISPONIBLE : self::SUR_COMMANDE;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::DISPONIBLE => 'Disponible en boutique',
            self::SUR_COMMANDE => 'Sur commande',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::DISPONIBLE => 'success',
            self::SUR_COMMANDE => 'warning',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $cas) => [$cas->value => $cas->getLabel()])
            ->all();
    }
}
