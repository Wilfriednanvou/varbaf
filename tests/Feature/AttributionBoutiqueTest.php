<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Artisanat\Enums\EtatBoutique;
use Modules\Artisanat\Enums\StatutAttribution;
use Modules\Artisanat\Exceptions\AttributionChevauchanteException;
use Modules\Artisanat\Exceptions\AttributionInvalideException;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\AttributionBoutique;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\VillageArtisanal;
use Tests\TestCase;

/**
 * Vérifie la règle non négociable : une boutique ne peut porter deux
 * attributions actives qui se chevauchent.
 *
 * Les tests attaquent le modèle et non la ressource Filament : c'est le
 * modèle qui porte la garantie, et c'est donc lui qu'il faut mettre en
 * défaut. Les cas couverts sont ceux où un contrôle naïf se trompe —
 * bornes jointives, période englobante, attribution sans terme,
 * modification d'une ligne face à elle-même.
 */
class AttributionBoutiqueTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Exercice $exercice;

    protected Boutique $boutique;

    protected Artisan $artisan;

    protected Artisan $autreArtisan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->village = VillageArtisanal::create([
            'code' => 'VARBAF',
            'nom' => 'Village Artisanal Régional de Bafoussam',
            'categorie' => CategorieVillage::REGIONAL,
            'region' => 'Ouest',
            'nombre_boutiques' => 24,
            'actif' => true,
        ]);

        $this->exercice = Exercice::create([
            'libelle' => '2026',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-12-31',
            'en_cours' => true,
            'village_id' => $this->village->id,
        ]);

        $corpsMetier = CorpsMetier::create(['code' => 'VAN', 'libelle' => 'Vannerie']);

        $this->boutique = Boutique::create([
            'numero' => 'B-01',
            'emplacement' => 'Rez-de-chaussée',
            'village_id' => $this->village->id,
        ]);

        $this->artisan = Artisan::create([
            'nom' => 'Kamdem',
            'prenom' => 'Jean',
            'corps_metier_id' => $corpsMetier->id,
            'village_id' => $this->village->id,
        ]);

        $this->autreArtisan = Artisan::create([
            'nom' => 'Tchouta',
            'prenom' => 'Marie',
            'corps_metier_id' => $corpsMetier->id,
            'village_id' => $this->village->id,
        ]);
    }

    protected function attribuer(
        string $debut,
        ?string $fin = null,
        ?Artisan $artisan = null,
    ): AttributionBoutique {
        return AttributionBoutique::create([
            'date_debut' => $debut,
            'date_fin' => $fin,
            'redevance_convenue' => 15000,
            'artisan_id' => ($artisan ?? $this->artisan)->id,
            'boutique_id' => $this->boutique->id,
            'exercice_id' => $this->exercice->id,
        ]);
    }

    public function test_le_matricule_de_l_artisan_est_genere_automatiquement(): void
    {
        $this->assertMatchesRegularExpression('/^ART-\d{4}-0001$/', $this->artisan->matricule);
        $this->assertMatchesRegularExpression('/^ART-\d{4}-0002$/', $this->autreArtisan->matricule);
    }

    public function test_une_attribution_sans_conflit_est_acceptee(): void
    {
        $attribution = $this->attribuer('2026-01-01', '2026-06-30');

        $this->assertSame(StatutAttribution::ACTIVE, $attribution->statut);
        $this->assertDatabaseCount('attributions_boutiques', 1);
    }

    public function test_deux_periodes_qui_se_recouvrent_sont_refusees(): void
    {
        $this->attribuer('2026-01-01', '2026-06-30');

        $this->expectException(AttributionChevauchanteException::class);

        $this->attribuer('2026-03-01', '2026-09-30', $this->autreArtisan);
    }

    public function test_une_periode_englobante_est_refusee(): void
    {
        $this->attribuer('2026-03-01', '2026-04-30');

        $this->expectException(AttributionChevauchanteException::class);

        $this->attribuer('2026-01-01', '2026-12-31', $this->autreArtisan);
    }

    public function test_deux_periodes_jointives_sont_acceptees(): void
    {
        $this->attribuer('2026-01-01', '2026-06-30');
        $suivante = $this->attribuer('2026-07-01', '2026-12-31', $this->autreArtisan);

        $this->assertTrue($suivante->exists);
        $this->assertDatabaseCount('attributions_boutiques', 2);
    }

    public function test_une_attribution_sans_terme_bloque_toute_periode_ulterieure(): void
    {
        $this->attribuer('2026-03-01', null);

        $this->expectException(AttributionChevauchanteException::class);

        $this->attribuer('2027-01-01', '2027-12-31', $this->autreArtisan);
    }

    public function test_une_attribution_sans_terme_ne_bloque_pas_une_periode_anterieure(): void
    {
        $this->attribuer('2026-03-01', null);
        $anterieure = $this->attribuer('2026-01-01', '2026-02-01', $this->autreArtisan);

        $this->assertTrue($anterieure->exists);
    }

    public function test_une_attribution_resiliee_ne_bloque_plus_la_boutique(): void
    {
        $premiere = $this->attribuer('2026-01-01', '2026-06-30');
        $premiere->resilier('Départ de l\'artisan');

        $seconde = $this->attribuer('2026-03-01', '2026-09-30', $this->autreArtisan);

        $this->assertSame(StatutAttribution::RESILIEE, $premiere->fresh()->statut);
        $this->assertTrue($seconde->exists);
    }

    public function test_une_attribution_peut_etre_modifiee_sans_se_chevaucher_elle_meme(): void
    {
        $attribution = $this->attribuer('2026-01-01', '2026-06-30');

        $attribution->date_fin = '2026-08-31';
        $attribution->save();

        $this->assertSame('2026-08-31', $attribution->fresh()->date_fin->toDateString());
    }

    public function test_la_boutique_passe_a_occupee_puis_redevient_disponible(): void
    {
        $attribution = $this->attribuer(now()->subMonth()->toDateString(), null);

        $this->assertSame(EtatBoutique::OCCUPEE, $this->boutique->fresh()->etat);

        $attribution->resilier('Fin de collaboration');

        $this->assertSame(EtatBoutique::DISPONIBLE, $this->boutique->fresh()->etat);
    }

    public function test_une_boutique_indisponible_ne_peut_pas_etre_attribuee(): void
    {
        $this->boutique->update(['etat' => EtatBoutique::INDISPONIBLE]);

        $this->expectException(AttributionInvalideException::class);

        $this->attribuer('2026-01-01', '2026-06-30');
    }

    public function test_un_artisan_desactive_ne_peut_pas_recevoir_de_boutique(): void
    {
        $this->artisan->update(['actif' => false]);

        $this->expectException(AttributionInvalideException::class);

        $this->attribuer('2026-01-01', '2026-06-30');
    }

    public function test_un_exercice_cloture_n_accepte_plus_d_attribution(): void
    {
        $this->exercice->cloturer();

        $this->expectException(AttributionInvalideException::class);

        $this->attribuer('2026-01-01', '2026-06-30');
    }

    /**
     * Le cas qui justifie de conditionner les contrôles au statut
     * ACTIVE : un artisan désactivé après coup est précisément celui
     * dont il faut pouvoir résilier le contrat.
     */
    public function test_une_attribution_reste_resiliable_apres_desactivation_de_l_artisan(): void
    {
        $attribution = $this->attribuer('2026-01-01', '2026-06-30');

        $this->artisan->update(['actif' => false]);

        $this->assertTrue($attribution->resilier('Artisan parti du village'));
        $this->assertSame(StatutAttribution::RESILIEE, $attribution->fresh()->statut);
    }
}
