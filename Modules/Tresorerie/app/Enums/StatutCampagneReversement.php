<?php

namespace Modules\Tresorerie\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * État d'une campagne de reversement.
 *
 * Deux états, transition irréversible : en préparation → validée
 * (RG-21). Une campagne en préparation est un état de travail — on la
 * recalcule, on l'abandonne. Une campagne validée a décaissé de
 * l'argent et rattaché des ventes : elle ne revient pas en arrière.
 */
enum StatutCampagneReversement: string implements HasColor, HasLabel
{
    case EN_PREPARATION = 'EN_PREPARATION';
    case VALIDEE = 'VALIDEE';

    public function getLabel(): string
    {
        return match ($this) {
            self::EN_PREPARATION => 'En préparation',
            self::VALIDEE => 'Validée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::EN_PREPARATION => 'warning',
            self::VALIDEE => 'success',
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
