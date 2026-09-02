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
        /**
         * La question telle que l'assistant l'a comprise, quand elle a
         * dû être reconstruite depuis l'échange précédent.
         *
         * **Elle s'affiche.** Une reformulation invisible serait un
         * endroit où le sens de la question peut changer sans que
         * personne le voie — exactement ce que le nommage du moteur et
         * le champ `redacteur` existent pour empêcher ailleurs. `null`
         * signifie que la question a été prise telle qu'elle a été
         * tapée.
         */
        public ?string $questionReformulee = null,
    ) {}

    /**
     * La même réponse, sachant de quelle saisie elle provient.
     *
     * **Une réponse est un procès-verbal : elle ne se modifie pas.** La
     * classe est `readonly` depuis l'origine, et c'est ce qui a fait
     * échouer trente et un tests le jour où le service a voulu poser la
     * reformulation après coup. La contrainte avait raison — un objet
     * qui dit ce qui s'est passé ne doit pas pouvoir dire autre chose
     * une ligne plus loin. On en dérive donc une nouvelle.
     *
     * Sans reformulation, l'instance courante est rendue telle quelle :
     * le cas ordinaire — la question a été prise comme elle a été tapée —
     * ne coûte aucune allocation.
     */
    public function avecReformulation(?string $questionReformulee): self
    {
        if ($questionReformulee === null) {
            return $this;
        }

        return new self(
            question: $this->question,
            categorie: $this->categorie,
            branche: $this->branche,
            texte: $this->texte,
            sources: $this->sources,
            lignes: $this->lignes,
            intention: $this->intention,
            intentionLibelle: $this->intentionLibelle,
            moteur: $this->moteur,
            moteurCle: $this->moteurCle,
            parametres: $this->parametres,
            redacteur: $this->redacteur,
            questionReformulee: $questionReformulee,
        );
    }

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
     * Les titres des sources, pour l'affichage.
     *
     * @return array<int, string>
     */
    public function titresDesSources(): array
    {
        return $this->sources->map(fn (SegmentTrouve $segment): string => $segment->titre)->all();
    }

    /**
     * Les sources en entier — titre **et** extrait —, pour la mesure.
     *
     * **Pourquoi pas les titres seuls, comme jusqu'au 27/08.** Le titre
     * d'une fiche produit est sa référence et sa désignation :
     * « BTQ12-0038 — Collier ». Le corps de métier, lui, n'apparaît que
     * dans l'extrait : « Collier — Vannerie — MINTCHOUGOM SIDONIE ». Un
     * jeu d'évaluation qui vise les corps de métier — parce qu'ils sont
     * seedés, donc stables d'un import à l'autre — ne pouvait donc
     * jamais valider une fiche produit, quelle que soit sa pertinence.
     *
     * Le rappel@5 mesurait ainsi une propriété des titres et non la
     * qualité du classement : il restait à 20 % sur les quatre moteurs,
     * témoin par mots-clés compris, ce qui aurait dû alerter plus tôt.
     * Un indicateur qui ne bouge jamais ne mesure rien.
     *
     * Élargir à l'extrait n'assouplit pas la règle : une source dont le
     * passage retrouvé parle de vannerie *est* une source sur la
     * vannerie. C'est la définition de la pertinence qui est en jeu, pas
     * son seuil.
     *
     * @return array<int, string>
     */
    public function textesDesSources(): array
    {
        return $this->sources
            ->map(fn (SegmentTrouve $segment): string => $segment->titre.' '.$segment->extrait)
            ->all();
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
