<?php

namespace Modules\Pilotage\Filament\Pages;

use Filament\Pages\Page;
use Livewire\Attributes\Computed;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Services\ContexteExercice;
use Modules\Pilotage\Data\FiltreRapport;

/**
 * Tableau de bord du village.
 *
 * La page ne calcule rien : elle porte les filtres et les transmet aux
 * widgets, qui interrogent `RapportService`. Trois couches, une seule
 * qui parle à la base.
 *
 * Les filtres sont posés ici et non sur chaque widget : trois widgets
 * portant chacun son propre sélecteur d'exercice finiraient par
 * afficher trois périodes différentes côte à côte, et le lecteur
 * n'aurait aucun moyen de s'en apercevoir.
 *
 * La page ne remplace pas le tableau de bord par défaut de Filament :
 * celui-ci reste la cible de `route('filament.admin.pages.dashboard')`,
 * que chaque `getBreadcrumbs()` du projet référence.
 */
class TableauDeBord extends Page
{
    protected string $view = 'pilotage::pages.tableau-de-bord';

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::PILOTAGE;

    protected static ?string $navigationLabel = 'Tableau de bord';

    protected static ?string $slug = 'tableau-de-bord';

    protected static ?int $navigationSort = 1;

    public ?int $exerciceId = null;

    public ?string $du = null;

    public ?string $au = null;

    public static function canAccess(): bool
    {
        return auth()->user()->can('consulter_tableau_bord');
    }

    public function mount(): void
    {
        abort_unless(auth()->user()->can('consulter_tableau_bord'), 403);

        // Le sélecteur global de la barre supérieure pose l'exercice
        // consulté : le tableau de bord s'ouvre dessus par défaut,
        // plutôt que de retomber systématiquement sur l'actif. Rien
        // n'empêche ensuite de changer la sélection dans la liste
        // déroulante ci-dessous, propre à cette page.
        $this->exerciceId = app(ContexteExercice::class)->exerciceConsulte()?->getKey();
    }

    public function getTitle(): string
    {
        return 'Tableau de bord';
    }

    public function getHeading(): string
    {
        return 'Tableau de bord';
    }

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Tableau de bord',
        ];
    }

    /**
     * @return array<int, array{id: int, libelle: string}>
     */
    #[Computed]
    public function exercices(): array
    {
        return Exercice::query()
            ->orderByDesc('date_debut')
            ->get()
            ->map(fn (Exercice $exercice): array => [
                'id' => $exercice->id,
                'libelle' => $exercice->libelle,
            ])
            ->all();
    }

    #[Computed]
    public function filtre(): FiltreRapport
    {
        return FiltreRapport::depuisTableau($this->filtresTableau());
    }

    /**
     * L'état des filtres tel qu'il est transmis aux widgets. Un tableau
     * plutôt que l'objet : une propriété Livewire doit être
     * sérialisable.
     *
     * @return array<string, mixed>
     */
    public function filtresTableau(): array
    {
        return [
            'exercice_id' => $this->exerciceId,
            'du' => $this->du,
            'au' => $this->au,
        ];
    }

    /**
     * Change de filtre ⇒ change de clé ⇒ les widgets se remontent avec
     * les nouvelles valeurs. Sans clé variable, Livewire réutiliserait
     * les composants existants et les chiffres ne bougeraient pas.
     */
    public function empreinteFiltre(): string
    {
        return $this->filtre->empreinte();
    }

    public function reinitialiserIntervalle(): void
    {
        $this->du = null;
        $this->au = null;
    }
}
