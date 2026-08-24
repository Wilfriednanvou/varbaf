<?php

namespace Modules\Artisanat\Enums;

use Filament\Support\Contracts\HasLabel;

enum ZoneBoutique: string implements HasLabel
{
    case ZONE_A = 'ZONE_A';
    case ZONE_B = 'ZONE_B';
    case ZONE_C = 'ZONE_C';

    public function getLabel(): string
    {
        return match ($this) {
            self::ZONE_A => 'Zone A',
            self::ZONE_B => 'Zone B',
            self::ZONE_C => 'Zone C',
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
