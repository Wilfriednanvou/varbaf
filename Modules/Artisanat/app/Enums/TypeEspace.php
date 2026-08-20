<?php

namespace Modules\Artisanat\Enums;

use Filament\Support\Contracts\HasLabel;

enum TypeEspace: string implements HasLabel
{
    case SALLE_REUNION = 'SALLE_REUNION';
    case SALLE_APPRENTISSAGE = 'SALLE_APPRENTISSAGE';
    case STAND = 'STAND';
    case PARKING = 'PARKING';

    public function getLabel(): string
    {
        return match ($this) {
            self::SALLE_REUNION => 'Salle de réunion',
            self::SALLE_APPRENTISSAGE => 'Salle d\'apprentissage',
            self::STAND => 'Stand',
            self::PARKING => 'Parking',
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
