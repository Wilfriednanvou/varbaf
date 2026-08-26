<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Commerce\Enums\StatutValidationProduit;
use Modules\Commerce\Models\CategorieProduit;
use Modules\Commerce\Models\Produit;
use Modules\Pilotage\Filament\Pages\Assistant;
use Modules\Pilotage\Services\ServiceIndexationLexicale;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La page de l'assistant : permissions et affichage.
 *
 * **Le badge du moteur est testé comme une fonctionnalité, pas comme un
 * ornement.** Il doit être à l'écran, parce que la démonstration du
 * repli en soutenance consiste précisément à le regarder changer.
 */
class AssistantPageTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Boutique $boutique;

    protected Artisan $vannier;

    protected CategorieProduit $paniers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->village = VillageArtisanal::create([
            'code' => 'VARBAF',
            'nom' => 'Village Artisanal Régional de Bafoussam',
            'categorie' => CategorieVillage::REGIONAL,
            'region' => 'Ouest',
            'actif' => true,
        ]);

        Exercice::create([
            'libelle' => '2026',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
            'en_cours' => true,
            'village_id' => $this->village->id,
        ]);

        $vannerie = CorpsMetier::create([
            'code' => 'VAN',
            'libelle' => 'Vannerie',
            'description' => 'Tressage de fibres végétales',
        ]);

        $this->vannier = Artisan::create([
            'nom' => 'Kamdem',
            'corps_metier_id' => $vannerie->id,
            'village_id' => $this->village->id,
        ]);

        $this->boutique = Boutique::create(['numero' => 'B-04', 'village_id' => $this->village->id]);
        $this->paniers = CategorieProduit::create(['code' => 'PAN', 'libelle' => 'Paniers']);
    }

    protected function utilisateur(bool $habilite): Utilisateur
    {
        $permission = Permission::findOrCreate('consulter_tableau_bord', 'web');

        $role = Role::findOrCreate($habilite ? 'lecteur_habilite' : 'lecteur_simple', 'web');

        if ($habilite) {
            $role->givePermissionTo($permission);
        }

        $utilisateur = Utilisateur::create([
            'name' => $habilite ? 'Habilité' : 'Sans droit',
            'email' => ($habilite ? 'habilite' : 'sansdroit').'@varbaf.local',
            'password' => bcrypt('motdepasse'),
        ]);

        $utilisateur->assignRole($role);

        return $utilisateur;
    }

    protected function produit(string $designation): Produit
    {
        $produit = Produit::create([
            'designation' => $designation,
            'prix_unitaire' => 10000,
            'categorie_id' => $this->paniers->id,
            'artisan_id' => $this->vannier->id,
            'boutique_id' => $this->boutique->id,
        ]);

        $produit->changerStatut(StatutValidationProduit::VALIDE);
        $produit->changerStatut(StatutValidationProduit::EXPOSE);

        return $produit->fresh();
    }

    // =================================================================
    //  PERMISSIONS
    // =================================================================

    public function test_un_compte_habilite_atteint_la_page(): void
    {
        $this->actingAs($this->utilisateur(true));

        Livewire::test(Assistant::class)->assertSuccessful();
    }

    public function test_un_compte_sans_permission_n_atteint_pas_la_page(): void
    {
        $this->actingAs($this->utilisateur(false));

        $this->assertFalse(Assistant::canAccess());
    }

    // =================================================================
    //  AFFICHAGE
    // =================================================================

    public function test_la_page_n_affiche_rien_avant_la_premiere_question(): void
    {
        $this->actingAs($this->utilisateur(true));

        Livewire::test(Assistant::class)
            ->assertSet('interroge', false)
            ->assertSee('Poser une question');
    }

    public function test_une_question_d_agregation_affiche_le_badge_calcul(): void
    {
        $this->actingAs($this->utilisateur(true));

        Livewire::test(Assistant::class)
            ->set('question', "Quel est le chiffre d'affaires ?")
            ->call('interroger')
            ->assertSet('interroge', true)
            ->assertSee('Calcul')
            ->assertSee('Agrégation');
    }

    public function test_une_question_descriptive_affiche_le_moteur_et_les_sources(): void
    {
        $this->actingAs($this->utilisateur(true));

        $this->produit('Panier tressé');
        $this->produit('Corbeille tressée');
        app(ServiceIndexationLexicale::class)->reindexer();

        Livewire::test(Assistant::class)
            ->set('question', 'Quels produits en vannerie ?')
            ->call('interroger')
            ->assertSee('Recherche')
            ->assertSee('Descriptive')
            ->assertSee('Similarité lexicale (TF-IDF)')
            ->assertSee('Sources mobilisées');
    }

    public function test_une_question_sans_reponse_affiche_le_refus_sans_source(): void
    {
        $this->actingAs($this->utilisateur(true));

        $this->produit('Panier tressé');
        app(ServiceIndexationLexicale::class)->reindexer();

        Livewire::test(Assistant::class)
            ->set('question', 'Quels artisans soufflent le verre de Murano ?')
            ->call('interroger')
            ->assertSee('Sans réponse')
            ->assertDontSee('Sources mobilisées');
    }

    public function test_un_exemple_clique_pose_la_question(): void
    {
        $this->actingAs($this->utilisateur(true));

        Livewire::test(Assistant::class)
            ->call('poser', 0)
            ->assertSet('question', "Quel est le chiffre d'affaires en juillet ?")
            ->assertSet('interroge', true);
    }

    /**
     * Le bouton passe un rang, et le rang doit être borné.
     *
     * Une propriété Livewire est réinscriptible depuis le navigateur :
     * un rang hors liste ne doit pas lever, seulement ne rien faire.
     */
    public function test_un_rang_hors_liste_ne_pose_aucune_question(): void
    {
        $this->actingAs($this->utilisateur(true));

        Livewire::test(Assistant::class)
            ->call('poser', 99)
            ->assertSet('question', '')
            ->assertSet('interroge', false);
    }

    /**
     * Le défaut que les assertions de contenu ne voyaient pas : un
     * `@js()` dans une valeur d'attribut de composant n'est pas compilé
     * par Blade et sortait tel quel dans le HTML, rendant les cinq
     * boutons inertes.
     */
    public function test_les_boutons_d_exemple_portent_un_appel_livewire_compile(): void
    {
        $this->actingAs($this->utilisateur(true));

        $html = Livewire::test(Assistant::class)->html();

        $this->assertStringContainsString('poser(0)', $html);
        $this->assertStringNotContainsString('@js(', $html, 'Blade ne compile pas @js() dans un attribut de composant.');
    }

    public function test_la_reinitialisation_efface_la_question(): void
    {
        $this->actingAs($this->utilisateur(true));

        Livewire::test(Assistant::class)
            ->set('question', 'Quelque chose')
            ->call('interroger')
            ->call('reinitialiser')
            ->assertSet('question', '')
            ->assertSet('interroge', false);
    }
}
