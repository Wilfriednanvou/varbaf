<?php

namespace Modules\Commerce\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EtatVente: string implements HasColor, HasLabel
{
    case VALIDEE = 'VALIDEE';
    case ANNULEE = 'ANNULEE';

    public function getLabel(): string
    {
        return match ($this) {
            self::VALIDEE => 'Validée',
            self::ANNULEE => 'Annulée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::VALIDEE => 'success',
            self::ANNULEE => 'danger',
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
