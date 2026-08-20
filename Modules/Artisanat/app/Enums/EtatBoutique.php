<?php

namespace Modules\Artisanat\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * État d'une boutique.
 *
 * DISPONIBLE et OCCUPEE sont dérivés des attributions et tenus à jour
 * par le modèle AttributionBoutique : la coordination ne les saisit
 * pas. INDISPONIBLE est le seul état réellement administratif — travaux,
 * réserve, retrait du parc — et il est posé à la main. C'est aussi le
 * seul qui bloque toute nouvelle attribution.
 */
enum EtatBoutique: string implements HasColor, HasLabel
{
    case DISPONIBLE = 'DISPONIBLE';
    case OCCUPEE = 'OCCUPEE';
    case INDISPONIBLE = 'INDISPONIBLE';

    public function getLabel(): string
    {
        return match ($this) {
            self::DISPONIBLE => 'Disponible',
            self::OCCUPEE => 'Occupée',
            self::INDISPONIBLE => 'Indisponible',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::DISPONIBLE => 'success',
            self::OCCUPEE => 'info',
            self::INDISPONIBLE => 'danger',
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
