<?php

namespace Modules\Pilotage\Indexation;

use Modules\Pilotage\Enums\TypeFicheLexicale;

/**
 * Une fiche prête à indexer, avant tokenisation.
 *
 * Objet de transport : le compositeur la produit depuis les modèles
 * métier, le service d'indexation la consomme. Ce découplage est ce qui
 * permet de tester la composition d'une fiche sans base de données, et
 * d'indexer sans savoir d'où vient le texte.
 */
final readonly class FicheComposee
{
    /**
     * @param  array<string, string|null>  $champs  nom du champ => texte, dans l'ordre de lecture
     */
    public function __construct(
        public TypeFicheLexicale $type,
        public int $sourceId,
        public string $titre,
        public array $champs,
    ) {}

    /**
     * Les champs effectivement renseignés.
     *
     * **C'est ici que se règle la question du champ vide**, une fois pour
     * toutes : un champ nul ou blanc n'est pas présent, il ne contribue
     * donc aucun terme, et rien en aval n'a à le savoir. Aucune branche
     * conditionnelle ailleurs.
     *
     * @return array<string, string>
     */
    public function champsRenseignes(): array
    {
        return array_filter(
            array_map(
                fn (?string $valeur): string => trim((string) $valeur),
                $this->champs,
            ),
            fn (string $valeur): bool => $valeur !== '',
        );
    }

    /**
     * La fiche lisible, telle qu'elle sera présentée en source.
     */
    public function texte(): string
    {
        return implode(' — ', $this->champsRenseignes());
    }

    /**
     * Empreinte du contenu, insensible à l'ordre des champs vides.
     *
     * Deux fiches dont le texte n'a pas bougé ont la même empreinte : la
     * réindexation sait alors qu'elle n'a pas à les retokeniser, et sait
     * le dire — c'est ce qui rend le compte rendu de la commande
     * informatif plutôt que décoratif.
     */
    public function empreinte(): string
    {
        return hash('sha256', $this->type->value.'|'.$this->sourceId.'|'.serialize($this->champsRenseignes()));
    }
}
