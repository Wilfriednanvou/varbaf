<?php

namespace Modules\Tresorerie\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * État d'une section de caisse.
 *
 * Deux états, transition irréversible : ouverte → clôturée (RG-07).
 * Une section clôturée ne rouvre jamais — toute correction passe par
 * la section suivante.
 */
enum EtatSectionCaisse: string implements HasColor, HasLabel
{
    case OUVERTE = 'OUVERTE';
    case CLOTUREE = 'CLOTUREE';

    public function getLabel(): string
    {
        return match ($this) {
            self::OUVERTE => 'Ouverte',
            self::CLOTUREE => 'Clôturée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::OUVERTE => 'success',
            self::CLOTUREE => 'gray',
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
