<?php

namespace Modules\Commerce\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Origine géographique du client, saisie facultative en un seul clic.
 *
 * L'information nourrira les indicateurs de fréquentation du tableau de
 * bord. Elle reste facultative : ralentir une vente au comptoir pour
 * une statistique ferait perdre plus qu'elle ne rapporte.
 */
enum ProvenanceClient: string implements HasLabel
{
    case LOCAL = 'LOCAL';
    case NATIONAL = 'NATIONAL';
    case ETRANGER = 'ETRANGER';

    public function getLabel(): string
    {
        return match ($this) {
            self::LOCAL => 'Local',
            self::NATIONAL => 'National',
            self::ETRANGER => 'Étranger',
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
