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
use Modules\Pilotage\Contracts\ModeleDeLangage;
use Modules\Pilotage\Contracts\MoteurDeRecherche;
use Modules\Pilotage\Enums\BrancheReponse;
use Modules\Pilotage\Enums\CategorieQuestion;
use Modules\Pilotage\Recherche\SegmentTrouve;
use Modules\Pilotage\Recherche\VecteurDeQuestion;
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
 *
 * **La rédaction générative, ajoutée le 27/08, n'ouvre aucune brèche
 * dans ce dispositif.** Un modèle de langage peut tourner les extraits
 * en français suivi, mais il n'est appelé que dans la branche
 * descriptive, il ne reçoit que les extraits déjà retrouvés, et sa
 * sortie passe par le garde-fou 2 comme n'importe quel autre texte. Il
 * ne cherche rien, ne calcule rien et ne voit aucun indicateur : la
 * frontière posée par le `Routeur` est en amont de lui. Quand aucun
 * modèle n'est disponible — pas de clé, pas de réseau — la réponse est
 * composée mécaniquement, et c'est le comportement d'origine.
 */
class ServiceAssistant
{
    /**
     * Au-delà, une saisie est tenue pour une question autonome et n'est
     * pas soumise à reformulation. Six mots sépare « et en juillet ? »
     * de « quel est le chiffre d'affaires en juillet ? ».
     */
    protected const MOTS_QUESTION_AUTONOME = 6;

    /**
     * Au-delà, une saisie est trop développée pour être une simple
     * interpellation : même sans point d'interrogation, six mots
     * expriment une demande.
     */
    protected const MOTS_INTERPELLATION = 6;

    /**
     * Les marques d'une demande.
     *
     * Leur présence suffit à écarter l'accueil : quelqu'un qui interroge
     * mérite qu'on lui dise qu'on a cherché et qu'on n'a rien trouvé,
     * pas qu'on le salue.
     */
    protected const MOTS_INTERROGATIFS = [
        'quel', 'quelle', 'quels', 'quelles', 'qui', 'que', 'quoi',
        'ou', 'où', 'combien', 'comment', 'pourquoi', 'quand',
        'existe', 'trouve', 'propose', 'vend', 'y',
    ];

    /**
     * L'accueil servi sans modèle — et le filet quand le modèle dérape.
     *
     * Il ne dit rien du village qui ne soit vrai par construction : il
     * énonce le périmètre de l'assistant, pas un fait.
     */
    protected const ACCUEIL_PAR_DEFAUT = 'Bonjour. Je réponds aux questions sur les artisans, '
        .'les produits, les boutiques, les locations et les chiffres du village. '
        .'Posez-moi une question, ou choisissez l\'un des exemples proposés.';

    public function __construct(
        protected Routeur $routeur,
        protected CatalogueDIntentions $catalogue,
        protected ExtracteurDeParametres $extracteur,
        protected ResolveurDeMoteur $resolveur,
        protected RapportService $rapport,
        protected ServiceAnalyseCatalogue $analyse,
        protected GardeDesChiffres $garde,
        protected ModeleDeLangage $modele,
    ) {}

    /**
     * Répond à une question.
     *
     * `$moteur` permet à la commande d'évaluation d'imposer le moteur
     * témoin sans toucher à la configuration : c'est ce qui rend la
     * comparaison de l'hypothèse H3 reproductible.
     */
    public function repondre(
        string $question,
        ?MoteurDeRecherche $moteur = null,
        array $historique = [],
    ): ReponseAssistant {
        $saisie = trim($question);

        if ($saisie === '') {
            return $this->refus($saisie, CategorieQuestion::DESCRIPTIVE, 'Aucune question n\'a été posée.');
        }

        // La question effectivement traitée peut différer de la saisie :
        // « et en juillet ? » n'a de sens que par ce qui précède. Tout ce
        // qui suit — routeur, extracteur, calcul, recherche — travaille
        // sur la question autonome et ignore qu'elle a été reconstruite.
        $reformulee = $this->reformuler($saisie, $historique);
        $question = $reformulee ?? $saisie;

        $routage = $this->routeur->classer($question);
        $parametres = $this->extracteur->extraire($question);

        $reponse = $routage->estAgregation()
            ? $this->parLeCalcul($question, $routage, $parametres)
            : $this->parLaRecherche($question, $moteur);

        return $reponse->avecReformulation($reformulee);
    }

    /**
     * Reconstruit une question de suite, ou rend `null`.
     *
     * **Le déclenchement est déterministe, pas confié au modèle.** Une
     * saisie de plus de six mots se suffit presque toujours à elle-même ;
     * en deçà, et seulement s'il y a un échange derrière, on demande au
     * modèle de rendre la question explicite. Ce filtre évite un
     * aller-retour réseau sur chaque question complète — le budget est de
     * huit secondes, et deux appels feraient seize.
     *
     * Le modèle rend la saisie inchangée quand elle se suffit : dans ce
     * cas, rien n'a été reformulé et rien ne sera affiché.
     *
     * @param  array<int, array{question: string, reponse: string}>  $historique
     */
    protected function reformuler(string $saisie, array $historique): ?string
    {
        if ($historique === [] || count(preg_split('/\s+/u', $saisie) ?: []) > self::MOTS_QUESTION_AUTONOME) {
            return null;
        }

        $reformulee = $this->modele->reformuler($saisie, $historique);

        if ($reformulee === null || $reformulee === $saisie) {
            return null;
        }

        return $reformulee;
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
            // **Deux vides qui ne disent pas la même chose.** Une
            // question dont les mots sont au vocabulaire mais dont aucun
            // passage n'atteint le seuil est une question sans réponse :
            // le corpus a été interrogé et n'a rien. Une saisie dont
            // aucun mot n'est au vocabulaire n'a pas été interrogée du
            // tout — le moteur s'est arrêté avant. Répondre « aucun
            // passage n'atteint le seuil de similarité » à quelqu'un qui
            // a écrit « bonjour » est un diagnostic faux pour une
            // décision juste.
            //
            // La distinction était déjà écrite dans
            // `VecteurDeQuestion::estExploitable()`, dont le docbloc la
            // formule mot pour mot, et elle s'arrêtait une couche trop
            // tôt : le moteur la connaissait, le message la perdait.
            //
            // **L'absence de vocabulaire ne suffit pas**, et l'avoir cru
            // a coûté trois tests. « Quels artisans soufflent le verre de
            // Murano ? » n'a elle non plus aucun mot au vocabulaire : les
            // fiches portent des désignations, des catégories, des métiers
            // et des *noms* d'artisans — le mot « artisan » n'y figure
            // nulle part. Le critère capturait donc les questions que le
            // jeu d'évaluation exige de refuser, et le taux de refus
            // correct de la table 4.3 serait tombé.
            //
            // Ce qui sépare vraiment les deux cas est la forme : « bonjour »
            // n'est pas une demande, « quels artisans… ? » en est une.
            if ($this->estUneInterpellation($question)
                && ! VecteurDeQuestion::depuis($question)->estExploitable()) {
                return $this->accueil($question);
            }

            return $this->refus(
                $question,
                CategorieQuestion::DESCRIPTIVE,
                'L\'information n\'est pas disponible dans le corpus du village : '
                .'aucun passage n\'atteint le seuil de similarité.',
                $moteur,
            );
        }

        // Une rédaction, si un modèle est là pour la faire ; sinon la
        // liste des extraits, qui est le comportement livré depuis le
        // premier jour. La matière est la même dans les deux cas.
        $redaction = $this->modele->redigerDepuisExtraits($question, $segments);
        $texte = $redaction ?? $this->listerLesExtraits($segments);

        // Garde-fou 2 : un chiffre sans source fait basculer en refus.
        // On ne corrige pas le texte, on le refuse : réparer une réponse
        // qui a inventé un nombre reviendrait à parier sur la nature de
        // l'invention.
        //
        // Le contrôle ne sait pas — et n'a pas à savoir — laquelle des
        // deux compositions il relit. C'est ce qui le rend suffisant :
        // ajouter un modèle génératif n'ajoute aucune exception à la
        // règle, il ajoute seulement une manière de la violer, que la
        // règle attrape déjà.
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
            // Rien n'a été rédigé : le dire, plutôt que de laisser croire
            // qu'un modèle est passé sur un texte qu'il n'a pas vu.
            redacteur: $redaction === null ? null : $this->modele->nom(),
        );
    }

    /**
     * La composition mécanique : montrer, sans écrire.
     *
     * **Aucune synthèse.** L'assistant n'écrit pas ce qu'il a compris,
     * il montre ce qu'il a trouvé. C'est ce qui rend le garde-fou 2
     * tenable sans modèle : un texte qui ne fait que citer ne peut
     * avancer que des chiffres qui figurent dans ce qu'il cite.
     *
     * **Ce n'est pas un chemin de secours écrit à part.** C'est le
     * comportement livré depuis le premier jour, qu'on n'a pas retiré en
     * ajoutant la rédaction. Il est parcouru à chaque exécution de la
     * suite de tests, puisque aucun modèle n'y est disponible : le
     * chemin dégradé est donc le mieux éprouvé des deux, ce qui est
     * exactement la propriété qu'on attend d'un repli.
     *
     * @param  Collection<int, SegmentTrouve>  $segments
     */
    protected function listerLesExtraits(Collection $segments): string
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
    /**
     * La saisie est-elle une interpellation plutôt qu'une demande ?
     *
     * Trois conditions, et chacune se défend seule. Pas de point
     * d'interrogation : celui qui interroge attend une réponse, et la
     * réponse honnête à une question sans réponse est le refus, jamais
     * une salutation qui esquive. Pas de mot interrogatif : la même
     * chose, pour qui ponctue peu. Et six mots au plus : au-delà, on
     * n'interpelle plus, on demande.
     *
     * Le critère est volontairement étroit. Se tromper en accueillant
     * une vraie question coûte le taux de refus correct — l'argument
     * central du volet IA ; se tromper en refusant un « merci » ne coûte
     * qu'une phrase mal choisie.
     */
    protected function estUneInterpellation(string $saisie): bool
    {
        if (str_contains($saisie, '?')) {
            return false;
        }

        $mots = preg_split('/\s+/u', mb_strtolower(trim($saisie))) ?: [];

        if (count($mots) > self::MOTS_INTERPELLATION) {
            return false;
        }

        foreach ($mots as $mot) {
            $nu = trim($mot, ".,;:!¡'\u{2019}");

            // « trouve-t-on », « est-ce », « y a-t-il » : le mot
            // interrogatif est en tête du composé.
            $tete = explode('-', $nu)[0];

            if (in_array($nu, self::MOTS_INTERROGATIFS, true)
                || in_array($tete, self::MOTS_INTERROGATIFS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Accueille une saisie qui n'est pas une question sur le village.
     *
     * **Le modèle ne reçoit rien du village**, donc il ne peut rien en
     * affirmer : c'est la même mécanique que la rédaction, poussée
     * jusqu'à ne rien donner du tout. Et comme il n'y a ici aucun
     * extrait à confronter, `GardeDesChiffres` n'a pas de matière — le
     * contrôle est donc plus strict, pas plus souple : **tout chiffre,
     * quel qu'il soit, fait rejeter la tournure** et rend la main à la
     * phrase fixe. Un accueil n'a aucune raison d'en porter un.
     *
     * Sans clé et sans réseau, la phrase fixe est servie telle quelle :
     * le message reste juste, il est seulement moins bien tourné. C'est
     * la propriété qui tient depuis le premier jour — un système dégradé
     * s'annonce et continue.
     */
    protected function accueil(string $saisie): ReponseAssistant
    {
        $tournure = $this->modele->accueillir($saisie);

        $redige = $tournure !== null && ! preg_match('/\d/', $tournure);

        return new ReponseAssistant(
            question: $saisie,
            categorie: CategorieQuestion::DESCRIPTIVE,
            branche: BrancheReponse::ACCUEIL,
            texte: $redige ? $tournure : self::ACCUEIL_PAR_DEFAUT,
            sources: new Collection(),
            redacteur: $redige ? $this->modele->nom() : null,
        );
    }

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
