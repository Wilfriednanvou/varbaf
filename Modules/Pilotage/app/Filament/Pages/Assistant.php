<?php

namespace Modules\Pilotage\Filament\Pages;

use Illuminate\Support\Collection;
use Modules\Pilotage\Assistant\ReponseAssistant;
use Modules\Pilotage\Recherche\SegmentTrouve;
use Modules\Pilotage\Services\ServiceAssistant;
use Modules\Socle\Enums\NavigationGroup;
use Filament\Pages\Page;

/**
 * L'assistant d'interrogation, dans le panneau d'administration.
 *
 * **La page ne décide rien.** Elle transmet la question au service et
 * affiche ce qu'il rend, badges compris. Aucune règle de routage,
 * aucun seuil, aucune mise en forme de montant ne vit ici : ce qui est
 * à l'écran doit être exactement ce que la commande d'évaluation mesure,
 * sans quoi la table 4.3 du rapport décrirait autre chose que ce que le
 * jury verra.
 *
 * **Le fil de conversation vit ici, et nulle part ailleurs.** C'est un
 * choix : un échange n'est pas une donnée du village. Il ne se range pas
 * en base, il ne s'audite pas, il ne survit pas à la page — le
 * rafraîchir repart de zéro. Le village a besoin de traçabilité sur ses
 * ventes et ses mouvements de caisse, pas sur les questions qu'un agent
 * a posées ; et une table de conversations serait une base de données
 * personnelles de plus à justifier.
 *
 * Elle est soumise à `consulter_tableau_bord`, la permission qui garde
 * déjà le tableau de bord. L'assistant n'expose rien de plus que lui —
 * les mêmes indicateurs, interrogés autrement — et lui créer une
 * permission propre donnerait à croire le contraire.
 */
class Assistant extends Page
{
    protected string $view = 'pilotage::pages.assistant';

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::PILOTAGE;

    protected static ?string $navigationLabel = 'Assistant';

    protected static ?string $slug = 'assistant';

    protected static ?int $navigationSort = 2;

    /**
     * Tours transmis au modèle pour reconstruire une question de suite.
     *
     * Trois suffisent : « et en juillet ? » se comprend par ce qui
     * précède immédiatement, pas par le début de la séance. Plus long
     * coûterait des jetons à chaque appel et donnerait au modèle
     * l'occasion de rattacher la question au mauvais tour.
     */
    protected const TOURS_DE_CONTEXTE = 3;

    /**
     * Tours conservés à l'écran.
     *
     * Le fil traverse le réseau à chaque aller-retour Livewire : le
     * laisser croître sans borne alourdirait indéfiniment une page dont
     * personne ne relit le début.
     */
    protected const TOURS_AFFICHES = 20;

    public string $question = '';

    public bool $interroge = false;

    /**
     * Le fil, du plus ancien au plus récent.
     *
     * Des tableaux de chaînes, jamais des `ReponseAssistant` : Livewire
     * sérialise les propriétés publiques à chaque aller-retour, et une
     * réponse porte une collection d'objets. On range donc ce qui
     * s'affiche, et rien de plus.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $echanges = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('consulter_tableau_bord') ?? false;
    }

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('consulter_tableau_bord'), 403);
    }

    public function getTitle(): string
    {
        return 'Assistant d\'interrogation';
    }

    public function getHeading(): string
    {
        return 'Assistant d\'interrogation';
    }

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            route('filament.admin.pages.tableau-de-bord') => 'Tableau de bord',
            '' => 'Assistant',
        ];
    }

    public function interroger(): void
    {
        $this->interroge = trim($this->question) !== '';

        if ($this->interroge) {
            $this->traiter();
        }
    }

    public function reinitialiser(): void
    {
        $this->question = '';
        $this->interroge = false;
        $this->echanges = [];
    }

    /**
     * @return array<int, string>
     */
    public function exemples(): array
    {
        return [
            'Quel est le chiffre d\'affaires en juillet ?',
            'Combien le village doit-il aux artisans ?',
            'Quelles sont les ventes par boutique ?',
            'Quels artisans travaillent la vannerie ?',
            'Quel est le taux d\'occupation du parc ?',
        ];
    }

    /**
     * Pose l'exemple de rang donné.
     *
     * Un rang et non la chaîne elle-même : passer un libellé à travers
     * un attribut Blade oblige à l'échapper pour le HTML, puis pour
     * JavaScript, puis pour l'expression Livewire. Une apostrophe dans
     * « chiffre d'affaires » suffit alors à casser le bouton, sans que
     * rien ne le signale. Le rang traverse les trois couches sans
     * échappement.
     */
    public function poser(int $rang): void
    {
        $exemples = $this->exemples();

        if (! isset($exemples[$rang])) {
            return;
        }

        $this->question = $exemples[$rang];
        $this->interroge = true;

        $this->traiter();
    }

    // =================================================================

    /**
     * Interroge le service et range le tour dans le fil.
     *
     * L'historique part **avant** que le tour courant n'y entre : une
     * question ne se reformule pas à partir d'elle-même.
     */
    protected function traiter(): void
    {
        $reponse = app(ServiceAssistant::class)->repondre(
            $this->question,
            historique: $this->historique(),
        );

        $this->echanges[] = $this->enTour($reponse);

        if (count($this->echanges) > self::TOURS_AFFICHES) {
            $this->echanges = array_slice($this->echanges, -self::TOURS_AFFICHES);
        }
    }

    /**
     * Les derniers tours, réduits à ce dont la reformulation a besoin.
     *
     * Ni sources, ni badges, ni paramètres : le modèle reçoit la
     * question et le texte, et rien d'autre. Ce n'est pas une économie,
     * c'est la même règle que partout — on ne lui donne que la matière
     * de la tâche qu'on lui confie.
     *
     * @return array<int, array{question: string, reponse: string}>
     */
    protected function historique(): array
    {
        return array_map(
            static fn (array $tour): array => [
                'question' => (string) $tour['question'],
                'reponse' => (string) $tour['texte'],
            ],
            array_slice($this->echanges, -self::TOURS_DE_CONTEXTE),
        );
    }

    /**
     * Aplatit une réponse en un tour affichable et sérialisable.
     *
     * @return array<string, mixed>
     */
    protected function enTour(ReponseAssistant $reponse): array
    {
        return [
            // La saisie de l'utilisateur, telle qu'il l'a tapée. Quand
            // elle a été reformulée, `reformulation` porte ce que
            // l'assistant a compris — et les deux s'affichent, pour
            // qu'une reconstruction de sens ne se fasse jamais en
            // silence.
            'saisie' => $this->question,
            'question' => $reponse->question,
            'reformulation' => $reponse->questionReformulee,

            'texte' => $reponse->texte,
            'brancheLabel' => $reponse->branche->getLabel(),
            'brancheColor' => $reponse->branche->getColor(),
            'categorie' => $reponse->categorie->getLabel(),
            'moteur' => $reponse->moteur,
            'redacteur' => $reponse->redacteur,
            'intention' => $reponse->intention,
            'intentionLibelle' => $reponse->intentionLibelle,
            'parametres' => $reponse->parametres,
            'lignes' => $reponse->lignes,
            'sources' => $this->enSources($reponse->sources),
        ];
    }

    /**
     * @param  Collection<int, SegmentTrouve>  $sources
     * @return array<int, array<string, mixed>>
     */
    protected function enSources(Collection $sources): array
    {
        return $sources
            ->map(static fn (SegmentTrouve $segment): array => [
                'titre' => $segment->titre,
                'extrait' => $segment->extrait,
                'type' => $segment->type->getLabel(),
                'pourcentage' => $segment->pourcentage(),
            ])
            ->all();
    }
}
