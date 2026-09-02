<?php

namespace Modules\Socle\Filament\Widgets;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Services\ContexteExercice;

/**
 * Le sélecteur global d'exercice, dans la barre supérieure du panneau.
 *
 * **Une navigation complète, pas un événement Livewire.** Changer
 * l'exercice consulté doit faire repartir chaque table et chaque widget
 * de l'écran avec les nouvelles données — or aucun d'eux n'écoute ce
 * composant. Un `redirect()` sur la page courante est le moyen le plus
 * sûr de les faire tous repartir ensemble, plutôt que de câbler un
 * événement que le prochain écran ajouté oublierait d'écouter.
 *
 * **Lecture ouverte, changement gardé.** Tout le monde voit l'exercice
 * consulté ; seul qui détient `lister_exercices` peut le changer — la
 * même permission que celle qui ouvre `ExerciceResource`. Le gabarit
 * n'affiche le menu déroulant qu'à cette condition, et cette méthode le
 * revérifie : un composant Livewire s'observe côté client, la
 * permission doit donc aussi tenir côté serveur.
 */
class SelecteurExercice extends Component
{
    public ?int $exerciceId = null;

    public function mount(): void
    {
        $this->exerciceId = app(ContexteExercice::class)->exerciceConsulte()?->getKey();
    }

    public function updatedExerciceId(?int $value): void
    {
        if (! auth()->user()?->can('lister_exercices')) {
            return;
        }

        $exercice = $value !== null ? Exercice::find($value) : null;

        if ($exercice === null) {
            return;
        }

        app(ContexteExercice::class)->definir($exercice);

        $this->redirect(url()->previous(), navigate: false);
    }

    /**
     * @return array<int, array{id: int, libelle: string, statut: string}>
     */
    public function exercices(): array
    {
        // Le village de l'exercice actif fait autorité : sans exercice
        // actif (le tout premier démarrage), rien ne filtre et tous les
        // exercices du dépôt apparaissent — cas qui ne peut survenir
        // qu'avant le tout premier `activer()`.
        $villageId = Exercice::courant()?->village_id;

        return Exercice::query()
            ->when($villageId, fn ($requete) => $requete->where('village_id', $villageId))
            ->orderByDesc('date_debut')
            ->get()
            ->map(fn (Exercice $exercice): array => [
                'id' => $exercice->id,
                'libelle' => $exercice->libelle,
                'statut' => $exercice->statut->getLabel(),
            ])
            ->all();
    }

    public function render(): View
    {
        return view('socle::filament.widgets.selecteur-exercice');
    }
}
