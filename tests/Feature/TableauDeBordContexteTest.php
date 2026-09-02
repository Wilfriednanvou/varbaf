<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Pilotage\Filament\Pages\TableauDeBord;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Services\ContexteExercice;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Le tableau de bord s'ouvre sur l'exercice consulté, pas toujours sur
 * l'actif — premier écran branché sur `ContexteExercice`.
 */
class TableauDeBordContexteTest extends TestCase
{
    use RefreshDatabase;

    protected Exercice $actif;

    protected Exercice $precedent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        Filament::setCurrentPanel('admin');

        $this->actif = Exercice::query()->where('en_cours', true)->firstOrFail();

        $anneePrecedente = (string) ((int) $this->actif->libelle - 1);

        $this->precedent = Exercice::create([
            'libelle' => $anneePrecedente,
            'date_debut' => "{$anneePrecedente}-01-01",
            'date_fin' => "{$anneePrecedente}-12-31",
            'village_id' => $this->actif->village_id,
        ]);
        $this->precedent->activer();
        $this->precedent->cloturer();

        // `$this->actif` a été chargé avant ces deux bascules : sa
        // copie en mémoire dit encore `en_cours = true`, alors que la
        // base l'a entre-temps repassé à `false`. Sans ce
        // rafraîchissement, `activer()` ne verrait `en_cours` dirty à
        // aucun moment (true → true du point de vue de l'objet) et
        // n'écrirait jamais la colonne.
        $this->actif = $this->actif->fresh();
        $this->actif->activer();

        $permission = Permission::findOrCreate('consulter_tableau_bord', 'web');
        $role = Role::findOrCreate('lecteur_tableau_bord_test', 'web');
        $role->givePermissionTo($permission);

        $utilisateur = Utilisateur::create([
            'name' => 'Habilité',
            'email' => 'habilite-tableau@varbaf.local',
            'password' => 'motdepasse',
            'actif' => true,
        ]);
        $utilisateur->assignRole($role);

        $this->actingAs($utilisateur);
    }

    public function test_sans_selection_le_tableau_s_ouvre_sur_l_actif(): void
    {
        Livewire::test(TableauDeBord::class)
            ->assertSet('exerciceId', $this->actif->id);
    }

    public function test_avec_une_selection_le_tableau_s_ouvre_dessus(): void
    {
        app(ContexteExercice::class)->definir($this->precedent);

        Livewire::test(TableauDeBord::class)
            ->assertSet('exerciceId', $this->precedent->id);
    }
}
