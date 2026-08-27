<?php

namespace Modules\Pilotage\Assistant;

use Illuminate\Support\Collection;
use Modules\Pilotage\Enums\BrancheReponse;
use Modules\Pilotage\Enums\CategorieQuestion;
use Modules\Pilotage\Recherche\SegmentTrouve;

/**
 * Ce que l'assistant rend, garde-fous compris.
 *
 * Trois choses que l'écran doit pouvoir montrer sans les reconstituer :
 * **quel moteur a répondu**, **quelle branche a été empruntée**, et
 * **sur quelles sources**. Les deux premières sont ce qui rend la
 * démonstration du repli lisible ; la troisième est ce qui rend la
 * réponse vérifiable.
 */
final readonly class ReponseAssistant
{
    /**
     * @param  Collection<int, SegmentTrouve>  $sources
     * @param  array<int, array<string, mixed>>  $lignes
     * @param  array<string, mixed>  $parametres
     */
    public function __construct(
        public string $question,
        public CategorieQuestion $categorie,
        public BrancheReponse $branche,
        public string $texte,
        public Collection $sources,
        public array $lignes = [],
        public ?string $intention = null,
        public ?string $intentionLibelle = null,
        public ?string $moteur = null,
        public ?string $moteurCle = null,
        public array $parametres = [],
        /**
         * Qui a **tourné** le texte, quand ce n'est pas la composition
         * mécanique.
         *
         * Distinct de `$moteur`, qui dit qui a **trouvé** les extraits.
         * Un lecteur a le droit de savoir qu'une phrase a été écrite par
         * un modèle de langage plutôt que citée telle quelle : c'est le
         * principe du nommage du moteur, appliqué à l'autre moitié du
         * travail. `null` signifie que rien n'a été rédigé — les extraits
         * sont montrés bruts.
         */
        public ?string $redacteur = null,
    ) {}

    public function aRepondu(): bool
    {
        return $this->branche->aRepondu();
    }

    public function estRefus(): bool
    {
        return $this->branche === BrancheReponse::REFUS;
    }

    public function demandeUnePrecision(): bool
    {
        return $this->branche === BrancheReponse::PRECISION;
    }

    /**
     * Les titres des sources, pour la mesure du rappel.
     *
     * @return array<int, string>
     */
    public function titresDesSources(): array
    {
        return $this->sources->map(fn (SegmentTrouve $segment): string => $segment->titre)->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function enTableau(): array
    {
        return [
            'question' => $this->question,
            'categorie' => $this->categorie->value,
            'branche' => $this->branche->value,
            'intention' => $this->intention,
            'moteur' => $this->moteurCle,
            'redacteur' => $this->redacteur,
            'texte' => $this->texte,
            'sources' => $this->sources->map(fn (SegmentTrouve $s): array => $s->enTableau())->all(),
            'parametres' => $this->parametres,
        ];
    }
}
