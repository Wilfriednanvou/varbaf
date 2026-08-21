<?php

namespace Modules\Commerce\Enums;

use Filament\Support\Contracts\HasLabel;

enum ModeReglement: string implements HasLabel
{
    case ESPECES = 'ESPECES';
    case MOBILE_MONEY = 'MOBILE_MONEY';
    case AUTRE = 'AUTRE';

    public function getLabel(): string
    {
        return match ($this) {
            self::ESPECES => 'Espèces',
            self::MOBILE_MONEY => 'Mobile Money',
            self::AUTRE => 'Autre',
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
