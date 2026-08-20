<?php

namespace Modules\Artisanat\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Cycle de vie d'une attribution de boutique.
 *
 * Distinction volontaire entre RESILIEE et TERMINEE : la première est
 * une rupture décidée avant l'échéance et exige un motif, la seconde
 * est l'arrivée normale au terme. Les confondre ferait perdre
 * l'information au moment des statistiques d'occupation.
 */
enum StatutAttribution: string implements HasColor, HasLabel
{
    case ACTIVE = 'ACTIVE';
    case RESILIEE = 'RESILIEE';
    case TERMINEE = 'TERMINEE';

    public function getLabel(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::RESILIEE => 'Résiliée',
            self::TERMINEE => 'Terminée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ACTIVE => 'success',
            self::RESILIEE => 'danger',
            self::TERMINEE => 'gray',
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
