<?php

namespace Modules\Socle\Contracts;

use Modules\Socle\Models\Exercice;

/**
 * Ce qui peut s'opposer à la clôture d'un exercice.
 *
 * **Le contrat est déclaré dans le Socle, et c'est ce qui rend la chose
 * possible.** La clôture d'un exercice doit vérifier que les sections
 * de caisse sont fermées et que les campagnes de reversement sont
 * validées — deux notions du module Trésorerie, que le Socle n'a pas le
 * droit de connaître. C'est exactement la situation que le Commerce a
 * résolue avec `JournalDeCaisse` : le consommateur définit le service
 * dont il a besoin, le fournisseur s'y conforme et vient se déclarer.
 *
 * Le Socle ne référence donc rien du module 4. Il expose un point
 * d'accroche, et la Trésorerie s'y accroche depuis son propre
 * fournisseur de services. La dépendance continue de descendre.
 *
 * **Un verrou ne clôture pas, il explique.** Il renvoie des phrases
 * lisibles par la coordination, pas des booléens : « une section de
 * caisse est encore ouverte » se lit et se corrige, « false » ne dit
 * pas quoi faire.
 */
interface VerrouDeCloture
{
    /**
     * Ce qui empêche cet exercice d'être clôturé, s'il y a lieu.
     *
     * @return array<int, string> Vide quand rien ne s'y oppose.
     */
    public function obstacles(Exercice $exercice): array;
}
