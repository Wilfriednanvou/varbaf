<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Commerce\Enums\StatutValidationProduit;
use Modules\Commerce\Exceptions\ProduitInvalideException;
use Modules\Commerce\Models\CategorieProduit;
use Modules\Commerce\Models\Produit;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\VillageArtisanal;
use Tests\TestCase;

/**
 * Vérifie les deux invariants du catalogue : la référence est un
 * identifiant figé (RG-09), et le statut de validation ne saute pas
 * d'étape (règle 14).
 */
class ProduitTest extends TestCase
{
    use RefreshDatabase;

    protected Artisan $artisan;

    protected Boutique $boutiqueDouze;

    protected Boutique $boutiqueTrois;

    protected CategorieProduit $categorie;

    protected function setUp(): void
    {
        parent::setUp();

        $village = VillageArtisanal::create([
            'code' => 'VARBAF',
            'nom' => 'Village Artisanal Régional de Bafoussam',
            'categorie' => CategorieVillage::REGIONAL,
            'region' => 'Ouest',
            'actif' => true,
        ]);

        $corpsMetier = CorpsMetier::create(['code' => 'SCB', 'libelle' => 'Sculpture sur bois']);

        $this->artisan = Artisan::create([
            'nom' => 'Kamdem',
            'prenom' => 'Jean',
            'corps_metier_id' => $corpsMetier->id,
            'village_id' => $village->id,
        ]);

        $this->boutiqueDouze = Boutique::create(['numero' => 'B-12', 'village_id' => $village->id]);
        $this->boutiqueTrois = Boutique::create(['numero' => 'B-03', 'village_id' => $village->id]);

        $this->categorie = CategorieProduit::create(['code' => 'SCU-MAS', 'libelle' => 'Masques']);
    }

    protected function deposer(?Boutique $boutique = null, string $designation = 'Masque cérémoniel'): Produit
    {
        return Produit::create([
            'designation' => $designation,
            'prix_unitaire' => 25000,
            'categorie_id' => $this->categorie->id,
            'artisan_id' => $this->artisan->id,
            'boutique_id' => ($boutique ?? $this->boutiqueDouze)->id,
        ]);
    }

    public function test_la_reference_suit_le_gabarit_de_la_boutique(): void
    {
        $produit = $this->deposer();

        $this->assertSame('BTQ12-0001', $produit->reference);
    }

    public function test_le_compteur_est_propre_a_chaque_boutique(): void
    {
        $premier = $this->deposer($this->boutiqueDouze);
        $second = $this->deposer($this->boutiqueDouze, 'Masque de danse');
        $autreBoutique = $this->deposer($this->boutiqueTrois, 'Tabouret royal');

        $this->assertSame('BTQ12-0001', $premier->reference);
        $this->assertSame('BTQ12-0002', $second->reference);
        $this->assertSame('BTQ03-0001', $autreBoutique->reference);
    }

    /**
     * La décision d'arbitrage : la référence est un identifiant, pas
     * une adresse. Elle figure sur l'étiquette physique collée sur
     * l'objet — si elle suivait les déménagements, toutes les
     * étiquettes déjà imprimées deviendraient fausses.
     */
    public function test_la_reference_ne_change_pas_quand_le_produit_change_de_boutique(): void
    {
        $produit = $this->deposer($this->boutiqueDouze);
        $referenceOrigine = $produit->reference;

        $produit->update(['boutique_id' => $this->boutiqueTrois->id]);

        $this->assertSame($referenceOrigine, $produit->fresh()->reference);
        $this->assertSame($this->boutiqueTrois->id, $produit->fresh()->boutique_id);
    }

    public function test_la_reference_ne_peut_pas_etre_reecrite(): void
    {
        $produit = $this->deposer();

        $this->expectException(ProduitInvalideException::class);

        $produit->reference = 'BTQ99-9999';
        $produit->save();
    }

    public function test_un_produit_depose_est_soumis_et_pas_encore_vendable(): void
    {
        $produit = $this->deposer();

        $this->assertSame(StatutValidationProduit::SOUMIS, $produit->statut_validation);
        $this->assertFalse($produit->estVendable());
        $this->assertFalse($produit->estPubliable());
    }

    public function test_un_produit_soumis_ne_peut_pas_sauter_la_validation(): void
    {
        $produit = $this->deposer();

        $this->expectException(ProduitInvalideException::class);

        $produit->changerStatut(StatutValidationProduit::EXPOSE);
    }

    public function test_le_chemin_normal_va_du_depot_a_la_vitrine(): void
    {
        $produit = $this->deposer();

        $produit->changerStatut(StatutValidationProduit::VALIDE);
        $this->assertTrue($produit->fresh()->estVendable());

        $produit->changerStatut(StatutValidationProduit::EXPOSE);
        $this->assertTrue($produit->fresh()->estVendable());
    }

    /**
     * Exposer ne suffit pas : le portail public exige en plus le
     * consentement de l'artisan. La vitrine relève de la Promotion, le
     * consentement appartient à l'artisan — l'un ne vaut pas l'autre.
     */
    public function test_la_publication_exige_l_autorisation_de_l_artisan(): void
    {
        $produit = $this->deposer();
        $produit->changerStatut(StatutValidationProduit::VALIDE);
        $produit->changerStatut(StatutValidationProduit::EXPOSE);

        $this->assertFalse($produit->fresh()->estPubliable());
        $this->assertSame(0, Produit::publiable()->count());

        $this->artisan->update(['autorisation_publication' => true]);

        $this->assertTrue($produit->fresh()->estPubliable());
        $this->assertSame(1, Produit::publiable()->count());
    }

    public function test_un_produit_retire_cesse_d_etre_vendable_puis_peut_revenir(): void
    {
        $produit = $this->deposer();
        $produit->changerStatut(StatutValidationProduit::VALIDE);
        $produit->changerStatut(StatutValidationProduit::RETIRE);

        $this->assertFalse($produit->fresh()->estVendable());
        $this->assertSame(0, Produit::vendable()->count());

        // Un produit déjà validé puis retiré n'a pas à repasser devant
        // la section Production.
        $produit->changerStatut(StatutValidationProduit::VALIDE);

        $this->assertTrue($produit->fresh()->estVendable());
    }

    public function test_un_produit_inactif_n_est_ni_vendable_ni_publiable(): void
    {
        $produit = $this->deposer();
        $produit->changerStatut(StatutValidationProduit::VALIDE);
        $produit->changerStatut(StatutValidationProduit::EXPOSE);
        $this->artisan->update(['autorisation_publication' => true]);

        $produit->update(['actif' => false]);

        $this->assertFalse($produit->fresh()->estVendable());
        $this->assertFalse($produit->fresh()->estPubliable());
        $this->assertSame(0, Produit::vendable()->count());
    }

    public function test_une_categorie_ne_peut_pas_etre_sa_propre_parente(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->categorie->update(['categorie_parent_id' => $this->categorie->id]);
    }

    public function test_une_hierarchie_de_categories_ne_peut_pas_boucler(): void
    {
        $famille = CategorieProduit::create(['code' => 'SCU', 'libelle' => 'Sculpture et statuaire']);
        $this->categorie->update(['categorie_parent_id' => $famille->id]);

        $this->expectException(\RuntimeException::class);

        $famille->update(['categorie_parent_id' => $this->categorie->id]);
    }
}
