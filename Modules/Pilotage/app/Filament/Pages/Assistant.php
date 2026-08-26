<?php

namespace Modules\Pilotage\Filament\Pages;

use Filament\Pages\Page;
use Modules\Pilotage\Assistant\ReponseAssistant;
use Modules\Pilotage\Services\ServiceAssistant;
use Modules\Socle\Enums\NavigationGroup;

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

    public string $question = '';

    public bool $interroge = false;

    protected ?ReponseAssistant $reponse = null;

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
    }

    public function reinitialiser(): void
    {
        $this->question = '';
        $this->interroge = false;
    }

    /**
     * La réponse courante, ou null tant qu'aucune question n'est posée.
     *
     * Recalculée à chaque rendu plutôt que gardée en propriété Livewire :
     * une `ReponseAssistant` porte une collection d'objets, que Livewire
     * devrait sérialiser à chaque aller-retour. Le calcul est local et
     * borné — il coûte moins que la sérialisation qu'il évite.
     */
    public function reponse(): ?ReponseAssistant
    {
        if (! $this->interroge || trim($this->question) === '') {
            return null;
        }

        return $this->reponse ??= app(ServiceAssistant::class)->repondre($this->question);
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
        $this->reponse = null;
        $this->interroge = true;
    }
}
