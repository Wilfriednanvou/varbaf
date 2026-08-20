<?php

namespace Modules\Socle\Enums;

use Filament\Support\Contracts\HasLabel;

enum CategorieVillage: string implements HasLabel
{
    case REGIONAL = 'REGIONAL';
    case SPECIAL = 'SPECIAL';
    case CENTRE_INTERNATIONAL = 'CENTRE_INTERNATIONAL';

    public function getLabel(): string
    {
        return match ($this) {
            self::REGIONAL => 'Village artisanal régional',
            self::SPECIAL => 'Village artisanal spécial',
            self::CENTRE_INTERNATIONAL => 'Centre international de l\'artisanat',
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
