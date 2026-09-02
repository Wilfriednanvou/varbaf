<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\VillageArtisanal;
use Modules\Socle\Services\ContexteExercice;
use Tests\TestCase;

/**
 * L'exercice consulté, distinct de l'exercice actif — voir le
 * commentaire de tête du service pour le motif de la distinction.
 */
class ContexteExerciceTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Exercice $actif;

    protected Exercice $cloture;

    protected ContexteExercice $contexte;

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

        $this->cloture = Exercice::create([
            'libelle' => '2025',
            'date_debut' => '2025-01-01',
            'date_fin' => '2025-12-31',
            'village_id' => $this->village->id,
        ]);
        $this->cloture->activer();
        $this->cloture->cloturer();

        $this->actif = Exercice::create([
            'libelle' => '2026',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
            'village_id' => $this->village->id,
        ]);
        $this->actif->activer();

        $this->contexte = app(ContexteExercice::class);
    }

    public function test_sans_selection_l_exercice_consulte_est_l_actif(): void
    {
        $this->assertTrue($this->contexte->exerciceConsulte()->is($this->actif));
        $this->assertTrue($this->contexte->estModifiable());
    }

    public function test_definir_change_l_exercice_consulte(): void
    {
        $this->contexte->definir($this->cloture);

        $this->assertTrue($this->contexte->exerciceConsulte()->is($this->cloture));
    }

    public function test_consulter_un_exercice_qui_n_est_pas_l_actif_rend_le_contexte_non_modifiable(): void
    {
        $this->contexte->definir($this->cloture);

        $this->assertFalse($this->contexte->estModifiable());
    }

    public function test_reinitialiser_revient_a_l_actif(): void
    {
        $this->contexte->definir($this->cloture);
        $this->contexte->reinitialiser();

        $this->assertTrue($this->contexte->exerciceConsulte()->is($this->actif));
        $this->assertTrue($this->contexte->estModifiable());
    }

    public function test_un_identifiant_de_session_perime_retombe_sur_l_actif(): void
    {
        session(['exercice_consulte_id' => 999999]);

        $this->assertTrue($this->contexte->exerciceConsulte()->is($this->actif));
    }
}
