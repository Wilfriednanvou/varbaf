<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Artisanat\Enums\EtatEspaceLocatif;
use Modules\Artisanat\Enums\StatutAttribution;
use Modules\Artisanat\Enums\ZoneBoutique;
use Modules\Artisanat\Exceptions\AttributionChevauchanteException;
use Modules\Artisanat\Exceptions\AttributionInvalideException;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\AttributionEspace;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Artisanat\Models\EspaceLocatif;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\VillageArtisanal;
use Tests\TestCase;

/**
 * Vérifie la règle non négociable, à la maille corrigée : un **espace
 * locatif** ne peut porter deux attributions actives qui se chevauchent
 * — mais une boutique peut parfaitement abriter plusieurs artisans en
 * même temps.
 *
 * C'est le cœur de la correction structurelle. Tant que la règle
 * regardait la boutique, elle se trompait deux fois dans le même
 * mouvement : elle refusait la cohabitation, qui est la situation
 * ordinaire du village, et elle ne disait rien du partage d'un même
 * espace, qui est la vraie faute.
 *
 * Les tests attaquent le modèle et non la ressource Filament : c'est le
 * modèle qui porte la garantie, et c'est donc lui qu'il faut mettre en
 * défaut. Les cas couverts sont ceux où un contrôle naïf se trompe —
 * bornes jointives, période englobante, attribution sans terme,
 * modification d'une ligne face à elle-même.
 */
class AttributionEspaceTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Exercice $exercice;

    protected Boutique $boutique;

    protected EspaceLocatif $espace;

    protected EspaceLocatif $espaceVoisin;

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
            'nombre_boutiques' => 17,
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
            'numero' => 'B01',
            'emplacement' => ZoneBoutique::ZONE_A,
            'village_id' => $this->village->id,
        ]);

        // Deux places de vente dans le même local : la configuration que
        // le modèle précédent rendait impossible à saisir.
        $this->espace = EspaceLocatif::create(['boutique_id' => $this->boutique->id]);
        $this->espaceVoisin = EspaceLocatif::create(['boutique_id' => $this->boutique->id]);

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
        ?EspaceLocatif $espace = null,
        int $redevance = 15000,
    ): AttributionEspace {
        return AttributionEspace::create([
            'date_debut' => $debut,
            'date_fin' => $fin,
            'redevance_convenue' => $redevance,
            'artisan_id' => ($artisan ?? $this->artisan)->id,
            'espace_locatif_id' => ($espace ?? $this->espace)->id,
            'exercice_id' => $this->exercice->id,
        ]);
    }

    // === Le code de l'espace se dérive de la boutique ===

    public function test_le_code_de_l_espace_suit_le_numero_de_la_boutique(): void
    {
        $this->assertSame('B0101', $this->espace->code);
        $this->assertSame('B0102', $this->espaceVoisin->code);
    }

    public function test_le_compteur_est_propre_a_chaque_boutique(): void
    {
        $autreBoutique = Boutique::create(['numero' => 'B07', 'village_id' => $this->village->id]);

        $premier = EspaceLocatif::create(['boutique_id' => $autreBoutique->id]);

        $this->assertSame('B0701', $premier->code);
    }

    public function test_le_matricule_de_l_artisan_est_genere_automatiquement(): void
    {
        $this->assertMatchesRegularExpression('/^ART-\d{4}-0001$/', $this->artisan->matricule);
        $this->assertMatchesRegularExpression('/^ART-\d{4}-0002$/', $this->autreArtisan->matricule);
    }

    // === Ce que la correction structurelle rend possible ===

    /**
     * Le cas qui a motivé toute la refonte.
     *
     * Au village, plusieurs artisans se partagent un même local : chacun
     * loue sa place. Tant que l'attribution portait la boutique, la
     * seconde était refusée comme un chevauchement — le système
     * interdisait la réalité qu'il devait enregistrer.
     */
    public function test_deux_artisans_occupent_deux_espaces_d_une_meme_boutique(): void
    {
        $premiere = $this->attribuer('2026-01-01', null, $this->artisan, $this->espace);
        $seconde = $this->attribuer('2026-01-01', null, $this->autreArtisan, $this->espaceVoisin);

        $this->assertTrue($premiere->exists);
        $this->assertTrue($seconde->exists);
        $this->assertDatabaseCount('attributions_espaces', 2);

        $this->assertSame(
            $this->boutique->id,
            $premiere->espaceLocatif->boutique_id,
            'Les deux espaces sont bien dans la même boutique.',
        );
        $this->assertSame($this->boutique->id, $seconde->espaceLocatif->boutique_id);
    }

    /**
     * Et la contrepartie : c'est le partage d'un même espace qui reste
     * une faute.
     */
    public function test_deux_attributions_actives_sur_le_meme_espace_sont_refusees(): void
    {
        $this->attribuer('2026-01-01', null, $this->artisan, $this->espace);

        $this->expectException(AttributionChevauchanteException::class);

        $this->attribuer('2026-01-01', null, $this->autreArtisan, $this->espace);
    }

    // === Chevauchement de périodes ===

    public function test_une_attribution_sans_conflit_est_acceptee(): void
    {
        $attribution = $this->attribuer('2026-01-01', '2026-06-30');

        $this->assertSame(StatutAttribution::ACTIVE, $attribution->statut);
        $this->assertDatabaseCount('attributions_espaces', 1);
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
        $this->assertDatabaseCount('attributions_espaces', 2);
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

    public function test_une_attribution_resiliee_ne_bloque_plus_l_espace(): void
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

    // === Facturation ===

    public function test_la_facturation_commence_un_mois_apres_l_entree(): void
    {
        $attribution = $this->attribuer('2026-03-15', '2026-12-31');

        $this->assertSame(
            '2026-04-15',
            $attribution->date_debut_facturation->toDateString(),
            'Le premier mois d\'occupation est offert.'
        );
    }

    public function test_la_date_de_facturation_suit_une_correction_de_la_date_d_entree(): void
    {
        $attribution = $this->attribuer('2026-03-15', '2026-12-31');

        $attribution->date_debut = '2026-05-01';
        $attribution->save();

        $this->assertSame('2026-06-01', $attribution->fresh()->date_debut_facturation->toDateString());
    }

    public function test_le_dossier_est_incomplet_par_defaut_et_sans_validateur(): void
    {
        $attribution = $this->attribuer('2026-01-01', '2026-06-30');

        $this->assertFalse($attribution->dossier_complet);
        $this->assertNull($attribution->validee_par);
    }

    // === Barème de la redevance ===

    /**
     * La redevance ne se calcule plus : elle se négocie. Rien ne la
     * corrigerait après coup puisqu'elle est figée sur le contrat, et le
     * barème est donc la seule barrière contre la faute de frappe.
     */
    public function test_la_redevance_convenue_est_un_montant_entier(): void
    {
        $attribution = $this->attribuer('2026-01-01', '2026-06-30', redevance: 12500);

        $this->assertSame(12500, $attribution->fresh()->redevance_convenue);
    }

    public function test_une_redevance_sous_le_plancher_du_bareme_est_refusee(): void
    {
        $this->expectException(AttributionInvalideException::class);

        $this->attribuer('2026-01-01', '2026-06-30', redevance: 1500);
    }

    public function test_une_redevance_au_dessus_du_plafond_du_bareme_est_refusee(): void
    {
        $this->expectException(AttributionInvalideException::class);

        $this->attribuer('2026-01-01', '2026-06-30', redevance: 75000);
    }

    public function test_les_bornes_du_bareme_sont_acceptees(): void
    {
        $plancher = $this->attribuer(
            '2026-01-01', '2026-06-30',
            redevance: AttributionEspace::REDEVANCE_MINIMALE,
        );

        $plafond = $this->attribuer(
            '2026-01-01', '2026-06-30',
            $this->autreArtisan,
            $this->espaceVoisin,
            AttributionEspace::REDEVANCE_MAXIMALE,
        );

        $this->assertTrue($plancher->exists);
        $this->assertTrue($plafond->exists);
    }

    // === État de l'espace ===

    public function test_l_espace_passe_a_occupe_puis_redevient_disponible(): void
    {
        $attribution = $this->attribuer(now()->subMonth()->toDateString(), null);

        $this->assertSame(EtatEspaceLocatif::OCCUPE, $this->espace->fresh()->etat);

        $attribution->resilier('Fin de collaboration');

        $this->assertSame(EtatEspaceLocatif::DISPONIBLE, $this->espace->fresh()->etat);
    }

    /**
     * L'espace voisin n'a pas à bouger : c'est tout l'intérêt d'avoir
     * quitté la maille boutique.
     */
    public function test_occuper_un_espace_ne_marque_pas_son_voisin(): void
    {
        $this->attribuer(now()->subMonth()->toDateString(), null);

        $this->assertSame(EtatEspaceLocatif::OCCUPE, $this->espace->fresh()->etat);
        $this->assertSame(EtatEspaceLocatif::DISPONIBLE, $this->espaceVoisin->fresh()->etat);
    }

    public function test_un_espace_indisponible_ne_peut_pas_etre_attribue(): void
    {
        $this->espace->update(['etat' => EtatEspaceLocatif::INDISPONIBLE]);

        $this->expectException(AttributionInvalideException::class);

        $this->attribuer('2026-01-01', '2026-06-30');
    }

    // === Conditions d'attribution ===

    public function test_un_artisan_desactive_ne_peut_pas_recevoir_d_espace(): void
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

    // === Immuabilité du code de l'espace ===

    public function test_un_espace_portant_une_attribution_ne_se_supprime_pas(): void
    {
        $this->attribuer('2026-01-01', '2026-06-30');

        $this->expectException(\Modules\Artisanat\Exceptions\EspaceLocatifException::class);

        $this->espace->delete();
    }
}
