<?php

namespace Modules\Artisanat\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Nature du contenant qui abrite des espaces locatifs.
 *
 * **Pourquoi cette distinction existe.** Le parc ne se compose pas que
 * de boutiques. La feuille de recouvrement des redevances du village
 * porte trois espaces loués hors du bâtiment de vente : deux au
 * sous-sol, un sur l'espace vert. Ils se louent, ils sont attribués, ils
 * facturent — mais ce ne sont pas des locaux de vente, et le taux
 * d'occupation que la coordination présente à sa tutelle porte sur les
 * boutiques.
 *
 * Les confondre coûterait dans les deux sens : les écarter du parc
 * sous-évaluerait le locatif d'un tiers, les fondre dedans gonflerait un
 * indicateur qui n'a de sens que sur les boutiques. La nature permet de
 * calculer les deux.
 *
 * **Sur le nom de la table.** Ces trois lignes vivent dans `boutiques`,
 * qui devient de fait la table des contenants. Renommer la table et le
 * modèle à huit jours du gel du code coûterait plus que la gêne de
 * lecture : c'est consigné ici plutôt que corrigé.
 */
enum NatureContenant: string implements HasLabel
{
    case BOUTIQUE = 'BOUTIQUE';
    case SOUS_SOL = 'SOUS_SOL';
    case ESPACE_VERT = 'ESPACE_VERT';

    public function getLabel(): string
    {
        return match ($this) {
            self::BOUTIQUE => 'Boutique',
            self::SOUS_SOL => 'Sous-sol',
            self::ESPACE_VERT => 'Espace vert',
        };
    }

    /**
     * Le local de vente est-il compté dans le taux d'occupation des
     * boutiques ?
     */
    public function estLocalDeVente(): bool
    {
        return $this === self::BOUTIQUE;
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
