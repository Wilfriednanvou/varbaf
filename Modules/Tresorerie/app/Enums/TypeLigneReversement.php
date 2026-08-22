<?php

namespace Modules\Tresorerie\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Nature d'une ligne de détail d'un reversement.
 *
 * La distinction entre `PERIODE` et `REGULARISATION` est celle de
 * RG-19 : une vente du mois courant et une vente antérieure rattrapée
 * ne se présentent pas de la même façon à l'artisan, même si elles
 * s'additionnent au même total. `REPRISE` est le seul type dont le
 * montant est négatif — l'annulation d'une vente déjà payée.
 */
enum TypeLigneReversement: string implements HasColor, HasLabel
{
    case PERIODE = 'PERIODE';
    case REGULARISATION = 'REGULARISATION';
    case REPRISE = 'REPRISE';

    public function getLabel(): string
    {
        return match ($this) {
            self::PERIODE => 'Période',
            self::REGULARISATION => 'Régularisation',
            self::REPRISE => 'Reprise',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PERIODE => 'success',
            self::REGULARISATION => 'info',
            self::REPRISE => 'danger',
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
