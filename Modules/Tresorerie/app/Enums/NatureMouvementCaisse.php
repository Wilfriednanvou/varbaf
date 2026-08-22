<?php

namespace Modules\Tresorerie\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Nature d'un mouvement de caisse.
 *
 * Chaque nature porte une sémantique métier : la vente est un
 * encaissement, le reversement un décaissement, la contre-passation
 * une écriture inverse référençant un mouvement d'origine.
 */
enum NatureMouvementCaisse: string implements HasColor, HasLabel
{
    case VENTE = 'VENTE';
    case REDEVANCE = 'REDEVANCE';
    case LOCATION = 'LOCATION';
    case FORMATION = 'FORMATION';
    case DEPENSE = 'DEPENSE';
    case REVERSEMENT = 'REVERSEMENT';
    case CONTREPASSATION = 'CONTREPASSATION';

    public function getLabel(): string
    {
        return match ($this) {
            self::VENTE => 'Vente',
            self::REDEVANCE => 'Redevance',
            self::LOCATION => 'Location',
            self::FORMATION => 'Formation',
            self::DEPENSE => 'Dépense',
            self::REVERSEMENT => 'Reversement',
            self::CONTREPASSATION => 'Contre-passation',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::VENTE => 'success',
            self::REDEVANCE => 'info',
            self::LOCATION => 'info',
            self::FORMATION => 'info',
            self::DEPENSE => 'warning',
            self::REVERSEMENT => 'danger',
            self::CONTREPASSATION => 'gray',
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
