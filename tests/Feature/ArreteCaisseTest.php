<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;
use Modules\Tresorerie\Enums\NatureMouvementCaisse;
use Modules\Tresorerie\Enums\SensMouvementCaisse;
use Modules\Tresorerie\Exceptions\ArreteCaisseException;
use Modules\Tresorerie\Models\ArreteCaisse;
use Modules\Tresorerie\Models\Caisse;
use Modules\Tresorerie\Models\MouvementCaisse;
use Modules\Tresorerie\Models\SectionCaisse;
use Modules\Tresorerie\Services\ServiceArreteCaisse;
use Modules\Tresorerie\Services\ServiceTresorerie;
use Tests\TestCase;

/**
 * Arrêté de caisse journalier (RG-25 à RG-27).
 *
 * Un écart non justifié refuse l'enregistrement (RG-26), un seul
 * arrêté par caisse et par jour (RG-25), et une journée déjà arrêtée
 * verrouille les nouvelles écritures — pas en les rejetant, mais en
 * refusant leur date demandée et en les reportant à aujourd'hui
 * (RG-27).
 */
class ArreteCaisseTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;
    protected Caisse $caisse;
    protected SectionCaisse $section;
    protected ServiceArreteCaisse $service;
    protected ServiceTresorerie $tresorerie;

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

        $exercice = Exercice::create([
            'libelle' => '2026',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
            'actif' => true,
            'village_id' => $this->village->id,
        ]);

        $utilisateur = Utilisateur::create([
            'name' => 'Test',
            'email' => 'test@varbaf.local',
            'password' => bcrypt('test'),
            'est_super_utilisateur' => true,
        ]);

        $this->actingAs($utilisateur);

        $this->caisse = Caisse::create([
            'code' => 'CAISSE-TEST',
            'libelle' => 'Caisse de test',
            'etat' => 'ACTIVE',
            'village_id' => $this->village->id,
        ]);

        $this->section = SectionCaisse::create([
            'caisse_id' => $this->caisse->id,
            'libelle' => 'Section test',
            'date_ouverture' => now(),
            'solde_ouverture' => 0,
            'etat' => 'OUVERTE',
            'ouverte_par' => $utilisateur->id,
            'village_id' => $this->village->id,
            'exercice_id' => $exercice->id,
        ]);

        $this->service = app(ServiceArreteCaisse::class);
        $this->tresorerie = app(ServiceTresorerie::class);
    }

    // === RG-26 : écart justifié ===

    public function test_un_ecart_non_nul_sans_commentaire_est_refuse(): void
    {
        // Solde théorique = 0 (aucun mouvement), solde compté = 5000 :
        // un écart de 5000 sans justification.
        $this->expectException(ArreteCaisseException::class);

        $this->service->arreter($this->section, now(), 5000);
    }

    public function test_un_ecart_non_nul_avec_commentaire_est_accepte(): void
    {
        $arrete = $this->service->arreter(
            $this->section,
            now(),
            5000,
            'Billet de 5000 F trouvé dans le tiroir, origine inconnue.',
        );

        $this->assertSame(0, $arrete->solde_theorique);
        $this->assertSame(5000, $arrete->solde_physique);
        $this->assertSame(5000, $arrete->ecart);
        $this->assertFalse($arrete->estEquilibre());
    }

    public function test_un_ecart_nul_ne_demande_aucun_commentaire(): void
    {
        $arrete = $this->service->arreter($this->section, now(), 0);

        $this->assertSame(0, $arrete->ecart);
        $this->assertTrue($arrete->estEquilibre());
    }

    public function test_le_solde_theorique_est_calcule_depuis_le_brouillard(): void
    {
        $this->tresorerie->enregistrer(
            $this->section, NatureMouvementCaisse::VENTE, SensMouvementCaisse::ENTREE, 10000, 'Vente',
        );
        $this->tresorerie->enregistrer(
            $this->section, NatureMouvementCaisse::DEPENSE, SensMouvementCaisse::SORTIE, 2000, 'Dépense',
        );

        $this->assertSame(8000, $this->service->soldeTheorique($this->section, now()));

        $arrete = $this->service->arreter($this->section, now(), 8000);

        $this->assertSame(8000, $arrete->solde_theorique);
        $this->assertSame(0, $arrete->ecart);
    }

    // === RG-25 : un seul arrêté par caisse et par jour ===

    public function test_un_second_arrete_le_meme_jour_est_refuse(): void
    {
        $this->service->arreter($this->section, now(), 0);

        $this->expectException(ArreteCaisseException::class);

        $this->service->arreter($this->section, now(), 0);
    }

    // === Immuabilité ===

    public function test_un_arrete_ne_peut_pas_etre_modifie(): void
    {
        $arrete = $this->service->arreter($this->section, now(), 0);

        $this->expectException(ArreteCaisseException::class);

        $arrete->update(['solde_physique' => 9999]);
    }

    public function test_un_arrete_ne_peut_pas_etre_supprime(): void
    {
        $arrete = $this->service->arreter($this->section, now(), 0);

        $this->expectException(ArreteCaisseException::class);

        $arrete->delete();
    }

    // === RG-27 : journée arrêtée verrouillée ===

    public function test_un_mouvement_date_sur_une_journee_arretee_voit_sa_date_refusee_et_reportee_a_aujourdhui(): void
    {
        $hier = now()->subDay();

        // La caisse a déjà été arrêtée hier.
        $this->service->arreter($this->section, $hier, 0);

        $mouvement = $this->tresorerie->enregistrer(
            $this->section,
            NatureMouvementCaisse::DEPENSE,
            SensMouvementCaisse::SORTIE,
            1500,
            'Redevance oubliée de la veille',
            dateOperation: $hier,
        );

        // La date demandée (hier, journée arrêtée) est refusée : le
        // mouvement est bien enregistré, mais à la date du jour, avec
        // mention de la date d'origine.
        $this->assertTrue($mouvement->date_operation->isToday());
        $this->assertNotNull($mouvement->date_origine);
        $this->assertSame($hier->toDateString(), $mouvement->date_origine->toDateString());
    }

    public function test_un_mouvement_date_sur_une_journee_non_arretee_garde_sa_date(): void
    {
        $hier = now()->subDay();

        $mouvement = $this->tresorerie->enregistrer(
            $this->section,
            NatureMouvementCaisse::DEPENSE,
            SensMouvementCaisse::SORTIE,
            1500,
            'Dépense normale',
            dateOperation: $hier,
        );

        $this->assertSame($hier->toDateString(), $mouvement->date_operation->toDateString());
        $this->assertNull($mouvement->date_origine);
    }

    public function test_un_mouvement_ordinaire_du_jour_n_est_pas_reporte_par_l_arrete_du_jour(): void
    {
        // Le mouvement est saisi avant l'arrêté du jour, à la date du
        // jour : rien à corriger, la journée est verrouillée après.
        $mouvement = $this->tresorerie->enregistrer(
            $this->section, NatureMouvementCaisse::VENTE, SensMouvementCaisse::ENTREE, 3000, 'Vente',
        );

        $this->assertNull($mouvement->date_origine);
        $this->assertSame(1, MouvementCaisse::count());
    }

    /**
     * Le trou que la comparaison à la date exacte laissait ouvert.
     *
     * Hier est arrêté, avant-hier ne l'a jamais été. Un mouvement daté
     * d'avant-hier passait alors sans être reporté — et entrait dans le
     * périmètre du solde théorique de l'arrêté d'hier, qui devenait faux
     * rétroactivement tout en restant immuable. Un écart de caisse
     * pouvait ainsi naître après le contrôle censé le constater.
     */
    public function test_un_mouvement_date_avant_une_journee_arretee_est_aussi_reporte(): void
    {
        $hier = now()->subDay();
        $avantHier = now()->subDays(2);

        $this->service->arreter($this->section, $hier, 0);

        $mouvement = $this->tresorerie->enregistrer(
            $this->section,
            NatureMouvementCaisse::DEPENSE,
            SensMouvementCaisse::SORTIE,
            1500,
            "Dépense d'avant-hier retrouvée",
            dateOperation: $avantHier,
        );

        $this->assertTrue($mouvement->date_operation->isToday());
        $this->assertSame($avantHier->toDateString(), $mouvement->date_origine->toDateString());

        // Et l'arrêté d'hier continue de dire vrai : le mouvement reporté
        // n'est pas entré dans son périmètre.
        $this->assertSame(
            0,
            $this->service->soldeTheorique($this->section, $hier),
            "Un arrêté immuable ne doit pas voir son solde théorique changer après coup.",
        );
    }

    // === Y8 : l'écart se déduit, il ne se saisit pas ===

    public function test_un_ecart_fourni_a_la_creation_est_ignore_et_recalcule(): void
    {
        // Un écart nul annoncé sur des soldes qui ne se rejoignent pas
        // franchissait la garde de RG-26 sans commentaire.
        $arrete = ArreteCaisse::create([
            'caisse_id' => $this->caisse->id,
            'section_id' => $this->section->id,
            'date_arrete' => now()->toDateString(),
            'solde_theorique' => 0,
            'solde_physique' => 5000,
            'ecart' => 0,
            'commentaire_ecart' => 'Billet trouvé dans le tiroir.',
            'date_validation' => now(),
        ]);

        $this->assertSame(
            5000,
            $arrete->ecart,
            "L'écart est déduit des deux soldes, jamais repris de ce qu'on lui passe.",
        );
    }

    public function test_un_ecart_nul_annonce_sur_des_soldes_discordants_est_refuse(): void
    {
        $this->expectException(ArreteCaisseException::class);

        ArreteCaisse::create([
            'caisse_id' => $this->caisse->id,
            'section_id' => $this->section->id,
            'date_arrete' => now()->toDateString(),
            'solde_theorique' => 0,
            'solde_physique' => 5000,
            'ecart' => 0,
            'date_validation' => now(),
        ]);
    }
}
