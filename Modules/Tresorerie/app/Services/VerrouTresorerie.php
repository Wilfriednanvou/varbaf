<?php

namespace Modules\Tresorerie\Services;

use Modules\Socle\Contracts\VerrouDeCloture;
use Modules\Socle\Models\Exercice;
use Modules\Tresorerie\Enums\EtatSectionCaisse;
use Modules\Tresorerie\Enums\StatutCampagneReversement;
use Modules\Tresorerie\Models\CampagneReversement;
use Modules\Tresorerie\Models\SectionCaisse;

/**
 * Ce que la Trésorerie oppose à la clôture d'un exercice.
 *
 * **Deux obstacles, et la même raison derrière les deux.** Clôturer un
 * exercice, c'est déclarer une période close. Le faire en laissant une
 * section de caisse ouverte reviendrait à arrêter les comptes sur une
 * caisse dont le solde peut encore bouger ; le faire en laissant une
 * campagne de reversement en préparation reviendrait à le faire alors
 * que des artisans attendent leur part. Dans les deux cas la clôture
 * dirait une chose fausse.
 *
 * C'est la dette DT-01, ouverte le 20 août parce que le module 4
 * n'existait pas encore, et échue depuis qu'il existe. Le Socle n'a
 * jamais eu à changer : il expose un point d'accroche, la Trésorerie
 * vient s'y déclarer.
 */
class VerrouTresorerie implements VerrouDeCloture
{
    /**
     * @return array<int, string>
     */
    public function obstacles(Exercice $exercice): array
    {
        $obstacles = [];

        $sections = SectionCaisse::query()
            ->where('exercice_id', $exercice->getKey())
            ->where('etat', EtatSectionCaisse::OUVERTE->value)
            ->count();

        if ($sections > 0) {
            $obstacles[] = $sections === 1
                ? 'une section de caisse est encore ouverte'
                : "{$sections} sections de caisse sont encore ouvertes";
        }

        $campagnes = CampagneReversement::query()
            ->where('exercice_id', $exercice->getKey())
            ->where('statut', StatutCampagneReversement::EN_PREPARATION->value)
            ->count();

        if ($campagnes > 0) {
            $obstacles[] = $campagnes === 1
                ? 'une campagne de reversement est encore en préparation'
                : "{$campagnes} campagnes de reversement sont encore en préparation";
        }

        return $obstacles;
    }
}
