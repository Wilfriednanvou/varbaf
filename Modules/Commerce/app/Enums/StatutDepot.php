<?php

namespace Modules\Commerce\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * État du document de dépôt.
 *
 * Deux états seulement, et le passage de l'un à l'autre est
 * irréversible. Tant qu'il est en brouillon, le dépôt se corrige
 * librement : rien n'est encore entré en stock et l'artisan n'a rien
 * signé. Une fois validé, il vaut décharge — le village reconnaît
 * détenir les biens listés — et les mouvements de stock existent. On
 * ne revient pas sur une reconnaissance signée : une erreur constatée
 * après coup se corrige par un retrait ou une contre-passation, qui
 * laissent l'un comme l'autre leur propre trace.
 */
enum StatutDepot: string implements HasColor, HasLabel
{
    case BROUILLON = 'BROUILLON';
    case VALIDE = 'VALIDE';

    public function getLabel(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::VALIDE => 'Validé',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::BROUILLON => 'warning',
            self::VALIDE => 'success',
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
