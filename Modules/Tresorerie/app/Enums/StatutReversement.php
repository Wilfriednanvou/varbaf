<?php

namespace Modules\Tresorerie\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * État du reversement d'un artisan au sein d'une campagne.
 *
 * `REPORTE` n'est pas un échec : c'est le traitement prévu par RG-20
 * quand le solde d'un artisan est négatif. Rien n'est décaissé, la
 * dette passe à la campagne suivante. Le distinguer de `PAYE` permet à
 * l'état récapitulatif de dire pourquoi une ligne n'a pas de
 * décaissement, au lieu d'afficher un zéro muet.
 */
enum StatutReversement: string implements HasColor, HasLabel
{
    case A_PAYER = 'A_PAYER';
    case PAYE = 'PAYE';
    case REPORTE = 'REPORTE';

    public function getLabel(): string
    {
        return match ($this) {
            self::A_PAYER => 'À payer',
            self::PAYE => 'Payé',
            self::REPORTE => 'Reporté',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::A_PAYER => 'warning',
            self::PAYE => 'success',
            self::REPORTE => 'gray',
        };
    }

    /**
     * Un reversement payé ou reporté est définitif : sa campagne est
     * validée, et RG-21 interdit d'y revenir.
     */
    public function estDefinitif(): bool
    {
        return $this !== self::A_PAYER;
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
