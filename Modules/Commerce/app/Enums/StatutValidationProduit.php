<?php

namespace Modules\Commerce\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Cycle de validation d'un produit (règle 14 de CLAUDE.md).
 *
 * L'ordre des cas n'est pas décoratif : c'est le chemin normal du
 * dépôt à la vitrine. Le passage de SOUMIS à EXPOSE sans validation
 * est refusé par le modèle — un produit non validé n'est ni vendable
 * ni publiable.
 */
enum StatutValidationProduit: string implements HasColor, HasLabel
{
    case SOUMIS = 'SOUMIS';
    case VALIDE = 'VALIDE';
    case EXPOSE = 'EXPOSE';
    case RETIRE = 'RETIRE';

    public function getLabel(): string
    {
        return match ($this) {
            self::SOUMIS => 'Soumis',
            self::VALIDE => 'Validé',
            self::EXPOSE => 'Exposé',
            self::RETIRE => 'Retiré',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::SOUMIS => 'warning',
            self::VALIDE => 'info',
            self::EXPOSE => 'success',
            self::RETIRE => 'gray',
        };
    }

    /**
     * Transitions autorisées depuis ce statut.
     *
     * RETIRE revient à VALIDE et non à SOUMIS : un produit déjà validé
     * puis retiré de la circulation n'a pas à repasser devant le chef
     * de section Production pour être remis en vente.
     *
     * @return array<int, self>
     */
    public function transitionsPossibles(): array
    {
        return match ($this) {
            self::SOUMIS => [self::VALIDE, self::RETIRE],
            self::VALIDE => [self::EXPOSE, self::RETIRE],
            self::EXPOSE => [self::VALIDE, self::RETIRE],
            self::RETIRE => [self::VALIDE],
        };
    }

    public function peutDevenir(self $cible): bool
    {
        return in_array($cible, $this->transitionsPossibles(), strict: true);
    }

    /**
     * Un produit vendable est validé, qu'il soit ou non en vitrine :
     * la vitrine relève de la promotion, pas de la vente au comptoir.
     */
    public function estVendable(): bool
    {
        return in_array($this, [self::VALIDE, self::EXPOSE], strict: true);
    }

    /**
     * Seul l'état EXPOSE ouvre la publication sur le portail public.
     */
    public function estPubliable(): bool
    {
        return $this === self::EXPOSE;
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
