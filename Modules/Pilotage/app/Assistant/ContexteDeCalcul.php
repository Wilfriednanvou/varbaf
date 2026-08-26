<?php

namespace Modules\Pilotage\Assistant;

use Modules\Pilotage\Services\RapportService;
use Modules\Pilotage\Services\ServiceAnalyseCatalogue;

/**
 * Ce dont une intention dispose pour calculer : deux services, et rien
 * d'autre.
 *
 * Il n'y a ici ni connexion, ni constructeur de requête, ni accès à la
 * base. Une intention ne peut appeler que des méthodes nommées de
 * `RapportService` et de `ServiceAnalyseCatalogue` — c'est ce qui rend
 * vraie, et vérifiable en lisant une seule classe, l'affirmation qu'aucun
 * montant ne sort d'ailleurs que du calcul déterministe.
 */
final readonly class ContexteDeCalcul
{
    public function __construct(
        public RapportService $rapport,
        public ServiceAnalyseCatalogue $analyse,
    ) {}

    /**
     * Un montant en francs CFA, tel qu'il s'écrit dans un état.
     *
     * Le franc CFA n'a pas de subdivision (RG-12 bis) : les montants
     * sont des entiers, et les afficher avec des décimales laisserait
     * croire à une précision qui n'existe pas.
     */
    public static function montant(int $valeur): string
    {
        return number_format($valeur, 0, ',', ' ').' FCFA';
    }

    public static function nombre(int $valeur): string
    {
        return number_format($valeur, 0, ',', ' ');
    }
}
