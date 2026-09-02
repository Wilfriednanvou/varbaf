<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Artisanat\Enums\StatutParticipationArtisan;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Commerce\Enums\StatutParticipationProduit;
use Modules\Commerce\Models\CategorieProduit;
use Modules\Commerce\Models\Produit;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\VillageArtisanal;
use Modules\Socle\Services\RegistreDeReconduction;
use Tests\TestCase;

/**
 * Le registre de reconduction — la dette qu'ouvrait le plan
 * multi-exercice à l'étape 4, échue.
 *
 * Même renversement de dépendance que `VerrousDeCloture` (DT-01) : le
 * Socle expose un point d'accroche, Artisanat et Commerce viennent s'y
 * déclarer depuis leur propre fournisseur. Ce fichier éprouve le
 * câblage lui-même, autant que le comportement de chaque reconducteur.
 */
class RegistreDeReconductionTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Exercice $ancien;

    protected Exercice $nouveau;

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

        $this->ancien = Exercice::create([
            'libelle' => '2026',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
            'village_id' => $this->village->id,
        ]);
        $this->ancien->activer();

        $this->nouveau = Exercice::create([
            'libelle' => '2027',
            'date_debut' => '2027-01-01',
            'date_fin' => '2027-12-31',
            'village_id' => $this->village->id,
        ]);
    }

    public function test_le_registre_accueille_les_reconducteurs_d_artisanat_et_de_commerce(): void
    {
        // Si ce test tombe, le cablage est casse : un fournisseur n'a
        // pas ete charge, et l'assistant de cloture ne proposerait rien
        // a reconduire pour ce module, en silence.
        $this->assertGreaterThanOrEqual(2, app(RegistreDeReconduction::class)->compte());
        $this->assertArrayHasKey('artisans', app(RegistreDeReconduction::class)->tous());
        $this->assertArrayHasKey('produits', app(RegistreDeReconduction::class)->tous());
    }

    public function test_reconducteur_artisans_liste_les_participants_actifs(): void
    {
        $corpsMetier = CorpsMetier::create(['code' => 'VAN', 'libelle' => 'Vannerie']);

        $artisan = Artisan::create([
            'nom' => 'Kamdem',
            'corps_metier_id' => $corpsMetier->id,
            'village_id' => $this->village->id,
        ]);

        $inactif = Artisan::create([
            'nom' => 'Tchouta',
            'corps_metier_id' => $corpsMetier->id,
            'village_id' => $this->village->id,
        ]);
        $inactif->participationsExercices()
            ->where('exercice_id', $this->ancien->id)
            ->update(['statut' => StatutParticipationArtisan::DESACTIVE->value]);

        $reconducteur = app(RegistreDeReconduction::class)->tous()['artisans'];
        $elements = $reconducteur->elementsAReconduire($this->ancien);

        $this->assertCount(1, $elements);
        $this->assertSame($artisan->id, $elements->first()['id']);
    }

    public function test_reconducteur_artisans_cree_une_participation_reconduit_sur_le_nouvel_exercice(): void
    {
        $corpsMetier = CorpsMetier::create(['code' => 'VAN', 'libelle' => 'Vannerie']);

        $artisan = Artisan::create([
            'nom' => 'Kamdem',
            'corps_metier_id' => $corpsMetier->id,
            'village_id' => $this->village->id,
        ]);

        $reconducteur = app(RegistreDeReconduction::class)->tous()['artisans'];
        $reconducteur->reconduire($this->ancien, $this->nouveau, [$artisan->id]);

        $participation = $artisan->participationsExercices()
            ->where('exercice_id', $this->nouveau->id)
            ->firstOrFail();

        $this->assertSame(StatutParticipationArtisan::RECONDUIT, $participation->statut);
    }

    public function test_reconducteur_artisans_ne_duplique_pas_si_deja_reconduit(): void
    {
        $corpsMetier = CorpsMetier::create(['code' => 'VAN', 'libelle' => 'Vannerie']);

        $artisan = Artisan::create([
            'nom' => 'Kamdem',
            'corps_metier_id' => $corpsMetier->id,
            'village_id' => $this->village->id,
        ]);

        $reconducteur = app(RegistreDeReconduction::class)->tous()['artisans'];
        $reconducteur->reconduire($this->ancien, $this->nouveau, [$artisan->id]);
        $reconducteur->reconduire($this->ancien, $this->nouveau, [$artisan->id]);

        $this->assertSame(
            1,
            $artisan->participationsExercices()->where('exercice_id', $this->nouveau->id)->count(),
        );
    }

    public function test_reconducteur_produits_liste_et_reconduit(): void
    {
        $corpsMetier = CorpsMetier::create(['code' => 'VAN', 'libelle' => 'Vannerie']);
        $artisan = Artisan::create([
            'nom' => 'Kamdem',
            'corps_metier_id' => $corpsMetier->id,
            'village_id' => $this->village->id,
        ]);
        $boutique = \Modules\Artisanat\Models\Boutique::create(['numero' => 'B-12', 'village_id' => $this->village->id]);
        $categorie = CategorieProduit::create(['code' => 'VAN-PAN', 'libelle' => 'Paniers']);

        $produit = Produit::create([
            'designation' => 'Panier tressé',
            'prix_unitaire' => 4000,
            'categorie_id' => $categorie->id,
            'artisan_id' => $artisan->id,
            'boutique_id' => $boutique->id,
        ]);

        $reconducteur = app(RegistreDeReconduction::class)->tous()['produits'];
        $elements = $reconducteur->elementsAReconduire($this->ancien);

        $this->assertCount(1, $elements);

        $reconducteur->reconduire($this->ancien, $this->nouveau, [$produit->id]);

        $participation = $produit->participationsExercices()
            ->where('exercice_id', $this->nouveau->id)
            ->firstOrFail();

        $this->assertSame(StatutParticipationProduit::RECONDUIT, $participation->statut);
    }
}
