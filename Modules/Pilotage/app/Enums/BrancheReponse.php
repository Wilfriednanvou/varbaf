<?php

namespace Modules\Pilotage\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Ce que l'assistant a effectivement fait de la question.
 *
 * Distincte de `CategorieQuestion`, et il faut le tenir : la catégorie
 * dit **qui aurait dû répondre**, la branche dit **ce qui s'est passé**.
 * Une question descriptive à laquelle rien n'a répondu reste classée
 * descriptive tout en aboutissant à un refus — et c'est exactement ce
 * que la mesure du taux de refus a besoin de pouvoir distinguer d'une
 * erreur de classification.
 */
enum BrancheReponse: string implements HasLabel, HasColor
{
    /** Calcul déterministe par RapportService. */
    case CALCUL = 'CALCUL';

    /** Recherche dans le corpus indexé. */
    case RECHERCHE = 'RECHERCHE';

    /** Intention reconnue, paramètre obligatoire manquant. */
    case PRECISION = 'PRECISION';

    /** Rien n'atteint le seuil : l'information n'est pas disponible. */
    case REFUS = 'REFUS';

    public function getLabel(): string
    {
        return match ($this) {
            self::CALCUL => 'Calcul',
            self::RECHERCHE => 'Recherche',
            self::PRECISION => 'Précision demandée',
            self::REFUS => 'Sans réponse',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::CALCUL => 'success',
            self::RECHERCHE => 'info',
            self::PRECISION => 'warning',
            self::REFUS => 'gray',
        };
    }

    /**
     * Une réponse a-t-elle été formulée ?
     *
     * Sert aux garde-fous : là où c'est faux, aucun texte de réponse ne
     * doit être produit, seulement l'explication du refus.
     */
    public function aRepondu(): bool
    {
        return $this === self::CALCUL || $this === self::RECHERCHE;
    }
}
