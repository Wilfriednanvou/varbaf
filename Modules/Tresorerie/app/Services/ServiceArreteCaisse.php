<?php

namespace Modules\Tresorerie\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Modules\Tresorerie\Enums\SensMouvementCaisse;
use Modules\Tresorerie\Exceptions\ArreteCaisseException;
use Modules\Tresorerie\Models\ArreteCaisse;
use Modules\Tresorerie\Models\MouvementCaisse;
use Modules\Tresorerie\Models\SectionCaisse;

/**
 * Point d'entrée unique de l'arrêté de caisse journalier (RG-25 à RG-27).
 *
 * Calcule le solde théorique depuis le brouillard — jamais saisi — et
 * délègue au modèle la garde sur l'écart non justifié (RG-26) : ce
 * service orchestre, il ne revalide pas une règle qui vit déjà ailleurs.
 */
class ServiceArreteCaisse
{
    /**
     * Solde théorique de la caisse à la fin de la journée donnée :
     * solde d'ouverture de la section plus le cumul des mouvements de
     * cette section datés jusqu'à ce jour inclus.
     */
    public function soldeTheorique(SectionCaisse $section, Carbon $dateArrete): int
    {
        $entrees = (int) MouvementCaisse::query()
            ->where('section_id', $section->getKey())
            ->where('sens', SensMouvementCaisse::ENTREE->value)
            ->whereDate('date_operation', '<=', $dateArrete)
            ->sum('montant');

        $sorties = (int) MouvementCaisse::query()
            ->where('section_id', $section->getKey())
            ->where('sens', SensMouvementCaisse::SORTIE->value)
            ->whereDate('date_operation', '<=', $dateArrete)
            ->sum('montant');

        return $section->solde_ouverture + $entrees - $sorties;
    }

    /**
     * Établit l'arrêté du jour : compare le solde théorique au montant
     * physiquement compté, et enregistre l'écart.
     *
     * @throws ArreteCaisseException si la caisse a déjà un arrêté pour
     *                                ce jour, ou si l'écart n'est pas
     *                                justifié (levée par le modèle)
     */
    public function arreter(
        SectionCaisse $section,
        Carbon $dateArrete,
        int $soldePhysique,
        ?string $commentaireEcart = null,
    ): ArreteCaisse {
        if (ArreteCaisse::query()
            ->where('caisse_id', $section->caisse_id)
            ->whereDate('date_arrete', $dateArrete)
            ->exists()
        ) {
            throw ArreteCaisseException::dejaArrete($dateArrete->format('d/m/Y'));
        }

        $soldeTheorique = $this->soldeTheorique($section, $dateArrete);

        return ArreteCaisse::create([
            'caisse_id' => $section->caisse_id,
            'section_id' => $section->getKey(),
            'date_arrete' => $dateArrete->toDateString(),
            'solde_theorique' => $soldeTheorique,
            'solde_physique' => $soldePhysique,
            'ecart' => $soldePhysique - $soldeTheorique,
            'commentaire_ecart' => $commentaireEcart,
            'arrete_par' => Auth::id(),
            'date_validation' => now(),
        ]);
    }
}
