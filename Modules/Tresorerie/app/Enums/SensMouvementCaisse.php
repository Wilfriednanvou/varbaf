<?php

namespace Modules\Tresorerie\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Sens d'un mouvement de caisse.
 *
 * Même convention que le journal de stock : le montant est toujours
 * positif, c'est le sens qui porte la direction. Un journal où les
 * sorties seraient des nombres négatifs se lit mal et se totalise mal.
 */
enum SensMouvementCaisse: string implements HasColor, HasLabel
{
    case ENTREE = 'ENTREE';
    case SORTIE = 'SORTIE';

    public function getLabel(): string
    {
        return match ($this) {
            self::ENTREE => 'Entrée',
            self::SORTIE => 'Sortie',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ENTREE => 'success',
            self::SORTIE => 'danger',
        };
    }

    /**
     * Signe à appliquer pour cumuler un solde.
     */
    public function signe(): int
    {
        return $this === self::ENTREE ? 1 : -1;
    }

    public function inverse(): self
    {
        return $this === self::ENTREE ? self::SORTIE : self::ENTREE;
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
