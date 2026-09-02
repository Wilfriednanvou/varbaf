<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Artisanat\Enums\StatutParticipationArtisan;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Commerce\Enums\StatutParticipationProduit;
use Modules\Commerce\Models\CategorieProduit;
use Modules\Commerce\Models\Produit;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\VillageArtisanal;
use Tests\TestCase;

/**
 * `artisan_exercices` et `produit_exercices` — la bascule initiale.
 *
 * Artisan et Produit restent des entites permanentes, sans colonne
 * d'exercice propre (voir leurs migrations) : c'est cette table de
 * jonction qui porte, pour chaque exercice, ce que `actif` portait
 * seul jusqu'ici. Ce fichier n'eprouve que la bascule des donnees
 * existantes — le filtrage des ecrans par exercice consulte est une
 * etape ulterieure du plan, pas encore cablee.
 */
class ParticipationExerciceTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Exercice $exercice;

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

        $this->exercice = Exercice::create([
            'libelle' => '2026',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
            'village_id' => $this->village->id,
        ]);
        $this->exercice->activer();
    }

    protected function unArtisan(bool $actif): Artisan
    {
        $corpsMetier = CorpsMetier::firstOrCreate(['code' => 'VAN'], ['libelle' => 'Vannerie']);

        return Artisan::create([
            'nom' => 'Kamdem',
            'actif' => $actif,
            'corps_metier_id' => $corpsMetier->id,
            'village_id' => $this->village->id,
        ]);
    }

    protected function unProduit(bool $actif): Produit
    {
        $artisan = $this->unArtisan(true);
        $categorie = CategorieProduit::firstOrCreate(['code' => 'VAN-PAN'], ['libelle' => 'Paniers']);
        $boutique = Boutique::create(['numero' => 'B-12', 'village_id' => $this->village->id]);

        return Produit::create([
            'designation' => 'Panier tressé',
            'prix_unitaire' => 4000,
            'actif' => $actif,
            'categorie_id' => $categorie->id,
            'artisan_id' => $artisan->id,
            'boutique_id' => $boutique->id,
        ]);
    }

    // === Artisanat ===================================================

    public function test_la_commande_cree_une_participation_actif_pour_un_artisan_actif(): void
    {
        $artisan = $this->unArtisan(actif: true);

        $this->artisan('varbaf:bootstrap-artisan-exercices')->assertSuccessful();

        $participation = $artisan->participationsExercices()->firstOrFail();
        $this->assertSame(StatutParticipationArtisan::ACTIF, $participation->statut);
        $this->assertSame($this->exercice->id, $participation->exercice_id);
    }

    public function test_la_commande_cree_une_participation_desactive_pour_un_artisan_inactif(): void
    {
        $artisan = $this->unArtisan(actif: false);

        $this->artisan('varbaf:bootstrap-artisan-exercices')->assertSuccessful();

        $this->assertSame(
            StatutParticipationArtisan::DESACTIVE,
            $artisan->participationsExercices()->firstOrFail()->statut,
        );
    }

    public function test_relancer_la_commande_ne_duplique_rien(): void
    {
        $this->unArtisan(actif: true);

        $this->artisan('varbaf:bootstrap-artisan-exercices')->assertSuccessful();
        $premierCompte = \Modules\Artisanat\Models\ArtisanExercice::count();

        $this->artisan('varbaf:bootstrap-artisan-exercices')->assertSuccessful();

        $this->assertSame($premierCompte, \Modules\Artisanat\Models\ArtisanExercice::count());
    }

    public function test_la_commande_echoue_proprement_sur_un_libelle_d_exercice_inconnu(): void
    {
        $this->artisan('varbaf:bootstrap-artisan-exercices', ['--exercice' => '1999'])
            ->assertFailed();
    }

    // === Commerce =====================================================

    public function test_la_commande_produit_cree_une_participation_actif_pour_un_produit_actif(): void
    {
        $produit = $this->unProduit(actif: true);

        $this->artisan('varbaf:bootstrap-produit-exercices')->assertSuccessful();

        $participation = $produit->participationsExercices()->firstOrFail();
        $this->assertSame(StatutParticipationProduit::ACTIF, $participation->statut);
        $this->assertSame($this->exercice->id, $participation->exercice_id);
    }

    public function test_la_commande_produit_cree_une_participation_desactive_pour_un_produit_inactif(): void
    {
        $produit = $this->unProduit(actif: false);

        $this->artisan('varbaf:bootstrap-produit-exercices')->assertSuccessful();

        $this->assertSame(
            StatutParticipationProduit::DESACTIVE,
            $produit->participationsExercices()->firstOrFail()->statut,
        );
    }
}
