<?php

namespace Modules\Pilotage\Filament\Widgets;

use Filament\Widgets\Widget;
use Modules\Pilotage\Services\ServiceAnalyseCatalogue;

/**
 * Les produits qui ne ressemblent à rien d'autre du catalogue.
 *
 * À l'usage de la section Promotion et Commercialisation : un produit
 * isolé est un candidat naturel à une mise en avant sur le portail,
 * puisqu'il ne se noie dans aucun rayon. C'est le seul indicateur du
 * tableau de bord qui désigne une occasion plutôt qu'un problème.
 */
class ProduitsIsoles extends Widget
{
    protected string $view = 'pilotage::widgets.produits-isoles';

    /** @var array<string, mixed> */
    public array $filtres = [];

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $analyse = app(ServiceAnalyseCatalogue::class);

        return [
            'produits' => $analyse->produitsIsoles(),
            'total' => $analyse->nombreDeProduitsIsoles(),
            'seuil' => (float) config('pilotage.analyse.seuil_isolement', 0.15),
        ];
    }
}
