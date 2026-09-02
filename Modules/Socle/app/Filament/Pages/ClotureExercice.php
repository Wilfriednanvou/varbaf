<?php

namespace Modules\Socle\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Filament\Resources\ExerciceResource;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\JournalAudit;
use Modules\Socle\Services\RegistreDeReconduction;
use Modules\Socle\Services\VerrousDeCloture;

/**
 * Assistant de clôture d'exercice.
 *
 * **Ce que la clôture prépare, en une seule opération engagée.**
 * `Exercice::cloturer()` referme l'ancien exercice ; ce que cette page
 * ajoute, c'est ce qui doit se passer *avant* que ça se ferme pour de
 * bon — reconduire les artisans et les produits qui continuent, poser
 * le nouvel exercice. Les trois tiennent dans la même transaction :
 * un échec sur l'un ne laisse jamais les deux autres à moitié faits.
 *
 * **Générique sur le registre.** Cette page ne connaît ni `Artisan` ni
 * `Produit` : elle itère `RegistreDeReconduction`, affiche ce que
 * chaque reconducteur déclare, et lui repasse la sélection telle
 * quelle. Un module qui ajoute demain un troisième reconducteur
 * apparaît ici sans qu'une ligne de cette classe ne change.
 *
 * **Une seule page, pas un assistant à étapes séparées.** Le
 * récapitulatif, la reconduction et le nouvel exercice se lisent l'un
 * après l'autre sur le même écran : rien n'empêche de les voir tous
 * avant de décider, et un rechargement de page ne perd aucune saisie
 * en cours de route.
 */
class ClotureExercice extends Page
{
    protected string $view = 'socle::pages.cloture-exercice';

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-archive-box-arrow-down';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::SOCLE;

    protected static ?string $navigationLabel = 'Clôture d\'exercice';

    protected static ?string $slug = 'cloture-exercice';

    protected static ?int $navigationSort = 3;

    public ?int $exerciceId = null;

    /** @var array<string, array<int, int>> */
    public array $selections = [];

    public string $nouveauLibelle = '';

    public ?string $nouveauDateDebut = null;

    public ?string $nouveauDateFin = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('cloturer_exercice') ?? false;
    }

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('cloturer_exercice'), 403);

        $this->exerciceId = Exercice::courant()?->getKey();

        // Tout est présélectionné : la décision qu'on demande est « qui
        // écarter », pas « qui reconduire un par un » — la reconduction
        // est le cas normal, la non-reconduction l'exception.
        if ($exercice = $this->exercice()) {
            foreach ($this->reconducteurs() as $cle => $reconducteur) {
                $this->selections[$cle] = $reconducteur
                    ->elementsAReconduire($exercice)
                    ->pluck('id')
                    ->all();
            }
        }
    }

    public function getTitle(): string
    {
        return 'Clôture d\'exercice';
    }

    public function getHeading(): string
    {
        return 'Clôture d\'exercice';
    }

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Clôture d\'exercice',
        ];
    }

    public function exercice(): ?Exercice
    {
        return $this->exerciceId ? Exercice::find($this->exerciceId) : null;
    }

    /**
     * @return array<int, string>
     */
    public function obstacles(): array
    {
        $exercice = $this->exercice();

        return $exercice ? app(VerrousDeCloture::class)->obstacles($exercice) : [];
    }

    /**
     * @return array<string, \Modules\Socle\Contracts\ReconducteurExercice>
     */
    public function reconducteurs(): array
    {
        return app(RegistreDeReconduction::class)->tous();
    }

    /**
     * @return Collection<int, array{id: int, libelle: string, statut_actuel: string}>
     */
    public function elementsPour(string $cle): Collection
    {
        $exercice = $this->exercice();

        if (! $exercice || ! isset($this->reconducteurs()[$cle])) {
            return collect();
        }

        return $this->reconducteurs()[$cle]->elementsAReconduire($exercice);
    }

    public function toutSelectionner(string $cle): void
    {
        $this->selections[$cle] = $this->elementsPour($cle)->pluck('id')->all();
    }

    public function toutDeselectionner(string $cle): void
    {
        $this->selections[$cle] = [];
    }

    /**
     * Trouve l'exercice cible par son libellé, ou le crée s'il n'existe
     * pas encore — un exercice posé à l'avance depuis `ExerciceResource`
     * (statut « En préparation ») n'a pas à être recréé.
     */
    protected function resoudreNouvelExercice(Exercice $ancien): Exercice
    {
        $existant = Exercice::query()
            ->where('village_id', $ancien->village_id)
            ->where('libelle', $this->nouveauLibelle)
            ->first();

        if ($existant) {
            if ($existant->cloture) {
                throw new \RuntimeException(
                    "L'exercice « {$this->nouveauLibelle} » est déjà clôturé : il ne peut pas devenir l'exercice actif.",
                );
            }

            return $existant;
        }

        return Exercice::create([
            'libelle' => $this->nouveauLibelle,
            'date_debut' => $this->nouveauDateDebut,
            'date_fin' => $this->nouveauDateFin,
            'village_id' => $ancien->village_id,
        ]);
    }

    public function confirmer(): void
    {
        $ancien = $this->exercice();

        if (! $ancien) {
            Notification::make()->title('Aucun exercice en cours à clôturer.')->danger()->send();

            return;
        }

        if ($this->obstacles() !== []) {
            Notification::make()
                ->title('Clôture refusée')
                ->body(implode(' ', $this->obstacles()))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        if (blank($this->nouveauLibelle) || blank($this->nouveauDateDebut) || blank($this->nouveauDateFin)) {
            Notification::make()
                ->title('Nouvel exercice incomplet')
                ->body('Le libellé et les deux dates du nouvel exercice sont obligatoires.')
                ->danger()
                ->send();

            return;
        }

        try {
            $nouveau = DB::transaction(function () use ($ancien): Exercice {
                $nouveau = $this->resoudreNouvelExercice($ancien);

                foreach ($this->reconducteurs() as $cle => $reconducteur) {
                    $reconducteur->reconduire($ancien, $nouveau, $this->selections[$cle] ?? []);
                }

                $nouveau->activer();

                if (! $ancien->cloturer()) {
                    throw new \RuntimeException("La clôture de l'exercice {$ancien->libelle} a échoué.");
                }

                return $nouveau;
            });
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Clôture refusée')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        JournalAudit::enregistrer(
            'Clôture d\'exercice avec reconduction',
            'SOCLE',
            'Exercice',
            $ancien->id,
            [
                'ancien' => $ancien->libelle,
                'nouveau' => $nouveau->libelle,
                'reconductions' => collect($this->selections)->map(fn (array $ids) => count($ids))->all(),
            ],
        );

        Notification::make()
            ->title('Exercice clôturé')
            ->body("{$ancien->libelle} est clôturé, {$nouveau->libelle} est l'exercice actif.")
            ->success()
            ->send();

        $this->redirect(ExerciceResource::getUrl());
    }
}
