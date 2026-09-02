<?php

namespace Modules\Artisanat\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Participation d'un artisan a un exercice donne — distincte de
 * `Artisan.actif`, qui decrit l'identite permanente, jamais un exercice
 * en particulier.
 *
 * RECONDUIT est un sous-etat informatif d'ACTIF : il dit que la ligne
 * vient de l'assistant de cloture plutot que d'une saisie directe, sans
 * changer aucun droit — un artisan RECONDUIT participe exactement comme
 * un artisan ACTIF.
 */
enum StatutParticipationArtisan: string implements HasColor, HasLabel
{
    case ACTIF = 'ACTIF';
    case RECONDUIT = 'RECONDUIT';
    case DESACTIVE = 'DESACTIVE';
    case INACTIF = 'INACTIF';

    public function getLabel(): string
    {
        return match ($this) {
            self::ACTIF => 'Actif',
            self::RECONDUIT => 'Reconduit',
            self::DESACTIVE => 'Désactivé',
            self::INACTIF => 'Inactif',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ACTIF => 'success',
            self::RECONDUIT => 'info',
            self::DESACTIVE => 'warning',
            self::INACTIF => 'gray',
        };
    }

    /**
     * Un artisan RECONDUIT ou ACTIF participe reellement a l'exercice ;
     * les deux autres etats n'ouvrent aucune operation nouvelle.
     */
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
