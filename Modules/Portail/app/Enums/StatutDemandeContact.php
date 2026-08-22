<?php

namespace Modules\Portail\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Suivi d'une demande reçue par le formulaire public.
 *
 * `ARCHIVEE` existe pour ce qui n'appelle pas de réponse — démarchage,
 * message vide — sans avoir à supprimer la ligne. Ce qui est arrivé est
 * arrivé : on le classe, on ne l'efface pas.
 */
enum StatutDemandeContact: string implements HasColor, HasLabel
{
    case NOUVELLE = 'NOUVELLE';
    case EN_COURS = 'EN_COURS';
    case TRAITEE = 'TRAITEE';
    case ARCHIVEE = 'ARCHIVEE';

    public function getLabel(): string
    {
        return match ($this) {
            self::NOUVELLE => 'Nouvelle',
            self::EN_COURS => 'En cours',
            self::TRAITEE => 'Traitée',
            self::ARCHIVEE => 'Archivée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NOUVELLE => 'danger',
            self::EN_COURS => 'warning',
            self::TRAITEE => 'success',
            self::ARCHIVEE => 'gray',
        };
    }

    public function estClose(): bool
    {
        return in_array($this, [self::TRAITEE, self::ARCHIVEE], strict: true);
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
