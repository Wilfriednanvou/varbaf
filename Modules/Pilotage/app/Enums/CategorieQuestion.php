<?php

namespace Modules\Pilotage\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * La nature d'une question, telle que le routeur la classe.
 *
 * **Ce n'est pas une nuance de présentation : c'est ce qui décide qui
 * calcule.** Une question d'agrégation porte sur de l'argent public —
 * chiffre d'affaires, commissions, dettes envers les artisans. Y
 * répondre par proximité textuelle reviendrait à produire un montant
 * qu'aucun calcul ne garantit, et qu'aucun contrôle ne pourrait
 * refaire. La classification est donc une frontière : au-delà, seul
 * `RapportService` a le droit de répondre.
 */
enum CategorieQuestion: string implements HasLabel
{
    case AGREGATION = 'AGREGATION';

    case DESCRIPTIVE = 'DESCRIPTIVE';

    public function getLabel(): string
    {
        return match ($this) {
            self::AGREGATION => 'Agrégation',
            self::DESCRIPTIVE => 'Descriptive',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AGREGATION => 'Calcul déterministe par le service de rapport',
            self::DESCRIPTIVE => 'Recherche dans le corpus indexé',
        };
    }
}
