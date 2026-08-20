<?php

namespace Modules\Socle\Enums;

use Filament\Support\Contracts\HasLabel;

enum Sexe: string implements HasLabel
{
    case MASCULIN = 'MASCULIN';
    case FEMININ = 'FEMININ';

    public function getLabel(): string
    {
        return match ($this) {
            self::MASCULIN => 'Masculin',
            self::FEMININ => 'Féminin',
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
