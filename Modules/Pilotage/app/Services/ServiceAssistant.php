<?php

namespace Modules\Pilotage\Services;

use Illuminate\Support\Collection;
use Modules\Pilotage\Assistant\CatalogueDIntentions;
use Modules\Pilotage\Assistant\ContexteDeCalcul;
use Modules\Pilotage\Assistant\ExtracteurDeParametres;
use Modules\Pilotage\Assistant\GardeDesChiffres;
use Modules\Pilotage\Assistant\ParametresQuestion;
use Modules\Pilotage\Assistant\ReponseAssistant;
use Modules\Pilotage\Assistant\Routeur;
use Modules\Pilotage\Contracts\MoteurDeRecherche;
use Modules\Pilotage\Enums\BrancheReponse;
use Modules\Pilotage\Enums\CategorieQuestion;
use Modules\Pilotage\Recherche\SegmentTrouve;
use Modules\Pilotage\Recommandation\ResolveurDeMoteur;

/**
 * L'assistant d'interrogation du tableau de bord.
 *
 * **Deux branches, une frontière, trois garde-fous.**
 *
 * La frontière est posée par le `Routeur` et n'est jamais franchie dans
 * les deux sens : une question classée agrégation est résolue par
 * `RapportService` et par lui seul ; une question classée descriptive
 * est résolue par la recherche et n'a aucun accès aux indicateurs. C'est
 * la garantie centrale du chantier — aucun montant ne peut être produit
 * par proximité textuelle.
 *
 * Les trois garde-fous, dans l'ordre où on les rencontre :
 *
 * 1. **Rien sous le seuil.** Si aucun segment n'atteint le seuil de
 *    similarité, l'assistant dit que l'information n'est pas
 *    disponible. Il ne formule pas de réponse approchée, il ne propose
 *    pas « peut-être vouliez-vous dire ».
 * 2. **Aucun chiffre sans source.** Le texte d'une réponse descriptive
 *    est relu par `GardeDesChiffres` : tout groupe de chiffres absent
 *    des extraits fait basculer la réponse en refus. Le contrôle est
 *    mécanique, donc démontrable.
 * 3. **Les sources accompagnent la réponse.** Titre et extrait, toujours,
 *    pour que le lecteur remonte à la fiche.
 *
 * Le nom du moteur qui a répondu est porté par la réponse : couper le
 * réseau devant un jury doit se voir à l'écran, pas seulement dans les
 * journaux.
 */
class ServiceAssistant
{
    public function __construct(
        protected Routeur $routeur,
        protected CatalogueDIntentions $catalogue,
        protected ExtracteurDeParametres $extracteur,
        protected ResolveurDeMoteur $resolveur,
        protected RapportService $rapport,
        protected ServiceAnalyseCatalogue $analyse,
        protected GardeDesChiffres $garde,
    ) {}

    /**
     * Répond à une question.
     *
     * `$moteur` permet à la commande d'évaluation d'imposer le moteur
     * témoin sans toucher à la configuration : c'est ce qui rend la
     * comparaison de l'hypothèse H3 reproductible.
     */
    public function repondre(string $question, ?MoteurDeRecherche $moteur = null): ReponseAssistant
    {
        $question = trim($question);

        if ($question === '') {
            return $this->refus($question, CategorieQuestion::DESCRIPTIVE, 'Aucune question n\'a été posée.');
        }

        $routage = $this->routeur->classer($question);
        $parametres = $this->extracteur->extraire($question);

        return $routage->estAgregation()
            ? $this->parLeCalcul($question, $routage, $parametres)
            : $this->parLaRecherche($question, $moteur);
    }

    // =================================================================
    //  BRANCHE CALCUL
    // =================================================================

    protected function parLeCalcul(
        string $question,
        \Modules\Pilotage\Assistant\ResultatDeRoutage $routage,
        ParametresQuestion $parametres,
    ): ReponseAssistant {
        $intention = $routage->intention;
        $manquants = $parametres->manquants($intention->parametresRequis);

        // Un paramètre obligatoire absent ne se devine pas. L'assistant
        // demande, il ne choisit pas à la place du demandeur : attribuer
        // un indicateur financier au mauvais artisan par inférence serait
        // une faute, pas une approximation.
        if ($manquants !== []) {
            $libelles = array_map(
                fn (string $p): string => ParametresQuestion::libelleDuParametre($p),
                $manquants,
            );

            return new ReponseAssistant(
                question: $question,
                categorie: CategorieQuestion::AGREGATION,
                branche: BrancheReponse::PRECISION,
                texte: 'Précisez '.implode(' et ', $libelles).' : « '.$intention->libelle
                    .' » ne peut pas se calculer sans cette information, et je ne la devine pas.',
                sources: new Collection(),
                intention: $intention->cle,
                intentionLibelle: $intention->libelle,
                parametres: $parametres->enTableau(),
            );
        }

        $contexte = new ContexteDeCalcul($this->rapport, $this->analyse);
        $resultat = ($intention->resolveur)($contexte, $parametres);

        return new ReponseAssistant(
            question: $question,
            categorie: CategorieQuestion::AGREGATION,
            branche: BrancheReponse::CALCUL,
            texte: (string) $resultat['texte'],
            // Aucune source documentaire : le chiffre ne vient pas d'un
            // texte retrouvé mais d'un calcul, et sa source est la
            // méthode nommée que l'intention désigne. La citer comme un
            // extrait laisserait croire à un rapprochement.
            sources: new Collection(),
            lignes: $resultat['lignes'] ?? [],
            intention: $intention->cle,
            intentionLibelle: $intention->libelle,
            moteur: 'Calcul déterministe — '.$intention->libelle,
            moteurCle: 'rapport_service',
            parametres: $parametres->enTableau(),
        );
    }

    // =================================================================
    //  BRANCHE RECHERCHE
    // =================================================================

    protected function parLaRecherche(string $question, ?MoteurDeRecherche $moteur): ReponseAssistant
    {
        $moteur ??= $this->resolveur->resoudreOuNul();

        if ($moteur === null) {
            return $this->refus(
                $question,
                CategorieQuestion::DESCRIPTIVE,
                'Le corpus n\'est pas indexé : aucune recherche n\'est possible. '
                .'Lancez « php artisan varbaf:indexer ».',
            );
        }

        $limite = (int) config('pilotage.recherche.extraits', 5);
        $segments = $moteur->rechercher($question, $limite);

        // Garde-fou 1 : rien n'atteint le seuil, donc rien n'est
        // formulé. Pas de reformulation, pas de suggestion, pas de
        // réponse « la plus proche ».
        if ($segments->isEmpty()) {
            return $this->refus(
                $question,
                CategorieQuestion::DESCRIPTIVE,
                'L\'information n\'est pas disponible dans le corpus du village : '
                .'aucun passage n\'atteint le seuil de similarité.',
                $moteur,
            );
        }

        $texte = $this->composerLaReponse($segments);

        // Garde-fou 2 : un chiffre sans source fait basculer en refus.
        // On ne corrige pas le texte, on le refuse : réparer une réponse
        // qui a inventé un nombre reviendrait à parier sur la nature de
        // l'invention.
        $orphelins = $this->garde->chiffresSansSource($texte, $segments);

        if ($orphelins !== []) {
            return $this->refus(
                $question,
                CategorieQuestion::DESCRIPTIVE,
                'Réponse écartée : elle avançait des chiffres ('.implode(', ', $orphelins)
                .') qui ne figurent dans aucun extrait retrouvé.',
                $moteur,
                $segments,
            );
        }

        return new ReponseAssistant(
            question: $question,
            categorie: CategorieQuestion::DESCRIPTIVE,
            branche: BrancheReponse::RECHERCHE,
            texte: $texte,
            // Garde-fou 3 : les sources accompagnent toujours la réponse.
            sources: $segments,
            moteur: $moteur->nom(),
            moteurCle: $moteur->cle(),
        );
    }

    /**
     * Compose la réponse à partir des seuls extraits retrouvés.
     *
     * **Aucune synthèse.** L'assistant n'écrit pas ce qu'il a compris,
     * il montre ce qu'il a trouvé. C'est ce qui rend le garde-fou 2
     * tenable : un texte qui ne fait que citer ne peut avancer que des
     * chiffres qui figurent dans ce qu'il cite.
     *
     * @param  Collection<int, SegmentTrouve>  $segments
     */
    protected function composerLaReponse(Collection $segments): string
    {
        $lignes = $segments
            ->map(fn (SegmentTrouve $segment): string => '— '.$segment->titre.' : '.$segment->extrait)
            ->all();

        return "Voici ce que le corpus du village contient de plus proche :\n".implode("\n", $lignes);
    }

    // =================================================================

    /**
     * @param  Collection<int, SegmentTrouve>|null  $sources
     */
    protected function refus(
        string $question,
        CategorieQuestion $categorie,
        string $motif,
        ?MoteurDeRecherche $moteur = null,
        ?Collection $sources = null,
    ): ReponseAssistant {
        return new ReponseAssistant(
            question: $question,
            categorie: $categorie,
            branche: BrancheReponse::REFUS,
            texte: $motif,
            sources: $sources ?? new Collection(),
            moteur: $moteur?->nom(),
            moteurCle: $moteur?->cle(),
        );
    }
}
