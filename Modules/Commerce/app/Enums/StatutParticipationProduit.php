<?php

namespace Modules\Commerce\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Participation d'un produit a un exercice donne — distincte de
 * `Produit.statut_validation` (le cycle soumis/valide/expose/retire),
 * qui decrit le produit lui-meme et ne depend d'aucun exercice.
 *
 * RECONDUIT est un sous-etat informatif d'ACTIF, meme principe que
 * pour l'artisan : il dit d'ou vient la ligne, pas ce qu'elle autorise.
 */
enum StatutParticipationProduit: string implements HasColor, HasLabel
{
    case ACTIF = 'ACTIF';
    case RECONDUIT = 'RECONDUIT';
    case DESACTIVE = 'DESACTIVE';
    case ARCHIVE = 'ARCHIVE';

    public function getLabel(): string
    {
        return match ($this) {
            self::ACTIF => 'Actif',
            self::RECONDUIT => 'Reconduit',
            self::DESACTIVE => 'Désactivé',
            self::ARCHIVE => 'Archivé',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ACTIF => 'success',
            self::RECONDUIT => 'info',
            self::DESACTIVE => 'warning',
            self::ARCHIVE => 'gray',
        };
    }

    public function estActif(): bool
    {
        return in_array($this, [self::ACTIF, self::RECONDUIT], strict: true);
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
