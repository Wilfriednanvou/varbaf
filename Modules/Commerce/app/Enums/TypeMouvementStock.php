<?php

namespace Modules\Commerce\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Nature d'un mouvement de stock (règle 3 de CLAUDE.md).
 *
 * Le type dit *pourquoi* le stock a bougé, le sens dit *dans quel
 * sens*. Les deux sont nécessaires : une CORRECTION peut être une
 * entrée comme une sortie, selon qu'elle contre-passe une sortie ou
 * une entrée.
 */
enum TypeMouvementStock: string implements HasLabel
{
    case DEPOT = 'DEPOT';
    case VENTE = 'VENTE';
    case RETRAIT = 'RETRAIT';
    case PERTE = 'PERTE';
    case CORRECTION = 'CORRECTION';

    public function getLabel(): string
    {
        return match ($this) {
            self::DEPOT => 'Dépôt',
            self::VENTE => 'Vente',
            self::RETRAIT => 'Retrait par l\'artisan',
            self::PERTE => 'Perte ou casse',
            self::CORRECTION => 'Contre-passation',
        };
    }

    /**
     * Sens naturel du type, quand il n'y en a qu'un.
     *
     * CORRECTION n'en a pas : son sens dépend du mouvement qu'elle
     * annule.
     */
    public function sensNaturel(): ?SensMouvementStock
    {
        return match ($this) {
            self::DEPOT => SensMouvementStock::ENTREE,
            self::VENTE, self::RETRAIT, self::PERTE => SensMouvementStock::SORTIE,
            self::CORRECTION => null,
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
