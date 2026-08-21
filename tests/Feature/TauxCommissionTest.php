<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Commerce\Exceptions\AucunTauxCommissionException;
use Modules\Commerce\Exceptions\TauxCommissionFigeException;
use Modules\Commerce\Models\TauxCommission;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\VillageArtisanal;
use Tests\TestCase;

/**
 * Vérifie l'historisation du taux de commission (RG-11) et les deux
 * refus que porte le modèle : pas de taux, pas de commission ; taux
 * entré en vigueur, taux figé.
 */
class TauxCommissionTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

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
    }

    protected function poser(float $taux, string $dateEffet): TauxCommission
    {
        return TauxCommission::create([
            'taux' => $taux,
            'date_effet' => $dateEffet,
            'reference_decision' => 'Note de service de test',
            'village_id' => $this->village->id,
        ]);
    }

    /**
     * Le point le plus important du module : une base vide ne doit pas
     * produire une commission de zéro pour cent.
     */
    public function test_une_table_vide_leve_une_exception_au_lieu_de_renvoyer_zero(): void
    {
        $this->expectException(AucunTauxCommissionException::class);

        TauxCommission::getTauxEnVigueur();
    }

    public function test_le_taux_applique_est_celui_en_vigueur_a_la_date_demandee(): void
    {
        $this->poser(15.00, '2025-01-01');
        $this->poser(5.00, '2025-07-01');
        $this->poser(10.00, '2026-01-01');

        $this->assertSame('15.00', TauxCommission::getTauxEnVigueur('2025-03-15'));
        $this->assertSame('5.00', TauxCommission::getTauxEnVigueur('2025-09-30'));
        $this->assertSame('10.00', TauxCommission::getTauxEnVigueur('2026-06-01'));
    }

    public function test_le_taux_s_applique_des_le_jour_de_sa_date_d_effet(): void
    {
        $this->poser(15.00, '2025-01-01');
        $this->poser(5.00, '2025-07-01');

        $this->assertSame('15.00', TauxCommission::getTauxEnVigueur('2025-06-30'));
        $this->assertSame('5.00', TauxCommission::getTauxEnVigueur('2025-07-01'));
    }

    public function test_une_date_anterieure_au_premier_taux_est_refusee(): void
    {
        $this->poser(15.00, '2025-01-01');

        $this->expectException(AucunTauxCommissionException::class);

        TauxCommission::getTauxEnVigueur('2024-12-31');
    }

    public function test_la_commission_se_calcule_en_pourcentage(): void
    {
        $taux = $this->poser(10.00, '2025-01-01');

        $this->assertSame(2500.00, $taux->commissionSur(25000));
    }

    public function test_un_taux_a_venir_reste_corrigible(): void
    {
        $taux = $this->poser(12.00, now()->addMonth()->toDateString());

        $this->assertTrue($taux->estModifiable());

        $taux->update(['taux' => 13.00]);

        $this->assertSame('13.00', $taux->fresh()->taux);
    }

    public function test_un_taux_entre_en_vigueur_ne_peut_plus_etre_modifie(): void
    {
        $taux = $this->poser(10.00, now()->subDay()->toDateString());

        $this->assertFalse($taux->estModifiable());

        $this->expectException(TauxCommissionFigeException::class);

        $taux->update(['taux' => 5.00]);
    }

    public function test_un_taux_entre_en_vigueur_ne_peut_plus_etre_supprime(): void
    {
        $taux = $this->poser(10.00, now()->subDay()->toDateString());

        $this->expectException(TauxCommissionFigeException::class);

        $taux->delete();
    }

    /**
     * La faille qu'un contrôle naïf laisserait passer : repousser la
     * date d'effet dans le futur pour déverrouiller un taux déjà
     * appliqué. Le contrôle porte sur la date d'origine, pas sur la
     * nouvelle.
     */
    public function test_repousser_la_date_d_effet_ne_deverrouille_pas_un_taux_applique(): void
    {
        $taux = $this->poser(10.00, now()->subDay()->toDateString());

        $this->expectException(TauxCommissionFigeException::class);

        $taux->update(['date_effet' => now()->addYear()->toDateString()]);
    }
}
