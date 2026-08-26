<?php

namespace Modules\Pilotage\Recherche;

use Modules\Pilotage\Enums\TypeFicheLexicale;

/**
 * Un passage du corpus retrouvé pour une question, et sa source.
 *
 * **L'extrait n'est pas décoratif.** C'est lui qui rend la réponse
 * vérifiable : le lecteur voit d'où vient ce qu'on lui affirme et peut
 * remonter à la fiche. C'est aussi lui qui borne ce qu'une réponse
 * descriptive a le droit de contenir — un chiffre absent de tous les
 * extraits n'a aucune source, donc rien à faire dans la réponse.
 */
final readonly class SegmentTrouve
{
    public function __construct(
        public int $ficheId,
        public TypeFicheLexicale $type,
        public int $sourceId,
        public string $titre,
        public string $extrait,
        public float $similarite,
    ) {}

    public function pourcentage(): float
    {
        return round($this->similarite * 100, 1);
    }

    /**
     * @return array<string, mixed>
     */
    public function enTableau(): array
    {
        return [
            'type' => $this->type->getLabel(),
            'titre' => $this->titre,
            'extrait' => $this->extrait,
            'similarite' => round($this->similarite, 4),
        ];
    }
}
