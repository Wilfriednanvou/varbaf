<?php

namespace Modules\Artisanat\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Rythme d'exigibilité de la redevance de boutique.
 *
 * Le village encaisse le plus souvent au mois, parfois au trimestre :
 * la périodicité est donc portée par l'attribution, pas par la
 * boutique, puisqu'elle relève de l'accord passé avec l'artisan.
 */
enum PeriodiciteRedevance: string implements HasLabel
{
    case MENSUELLE = 'MENSUELLE';
    case TRIMESTRIELLE = 'TRIMESTRIELLE';

    public function getLabel(): string
    {
        return match ($this) {
            self::MENSUELLE => 'Mensuelle',
            self::TRIMESTRIELLE => 'Trimestrielle',
        };
    }

    /**
     * Nombre de mois couverts par une échéance.
     */
    public function nombreDeMois(): int
    {
        return match ($this) {
            self::MENSUELLE => 1,
            self::TRIMESTRIELLE => 3,
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
