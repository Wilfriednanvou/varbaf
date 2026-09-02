<?php

namespace Modules\Socle\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Cycle de vie d'un exercice, au-delà des deux booleens `en_cours` et
 * `cloture` qui le portaient seuls jusqu'ici.
 *
 * **Colonne ajoutee, pas colonne de remplacement.** `en_cours` et
 * `cloture` restent la source ecrite par le formulaire et par
 * `activer()`/`cloturer()` ; `statut` s'en deduit dans le crochet
 * `saving` du modele et ne se choisit jamais directement, sauf pour
 * ARCHIVE qui n'a pas d'equivalent dans les deux booleens — un exercice
 * archive reste `cloture = true`, indistinguable de CLOTURE sans ce
 * quatrieme etat.
 */
enum StatutExercice: string implements HasColor, HasLabel
{
    case EN_PREPARATION = 'EN_PREPARATION';
    case ACTIF = 'ACTIF';
    case CLOTURE = 'CLOTURE';
    case ARCHIVE = 'ARCHIVE';

    public function getLabel(): string
    {
        return match ($this) {
            self::EN_PREPARATION => 'En préparation',
            self::ACTIF => 'Actif',
            self::CLOTURE => 'Clôturé',
            self::ARCHIVE => 'Archivé',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::EN_PREPARATION => 'gray',
            self::ACTIF => 'success',
            self::CLOTURE => 'warning',
            self::ARCHIVE => 'gray',
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
