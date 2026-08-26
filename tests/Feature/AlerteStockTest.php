<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Commerce\Listeners\NotifierSeuilAlerte;
use Modules\Commerce\Models\CategorieProduit;
use Modules\Commerce\Models\Produit;
use Modules\Commerce\Services\ServiceMouvementStock;
use Modules\Socle\Database\Seeders\PermissionSeeder;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Règle 15 : la remise de l'alerte de rupture.
 *
 * `MouvementStockTest` éprouve déjà l'**émission** — au franchissement,
 * une seule fois, jamais sur un produit non surveillé. Ce test-ci
 * éprouve ce qui manquait : que quelqu'un la reçoive, et que ce soit
 * bien les sections nommées par la règle.
 *
 * La distinction n'est pas académique. Un événement émis sans auditeur
 * passe tous les tests d'émission du monde et ne prévient personne.
 */
class AlerteStockTest extends TestCase
{
    use RefreshDatabase;

    protected Produit $produit;

    protected ServiceMouvementStock $service;

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

        $corpsMetier = CorpsMetier::create(['code' => 'VAN', 'libelle' => 'Vannerie']);

        $artisan = Artisan::create([
            'nom' => 'Kamdem',
            'prenom' => 'Léonard',
            'corps_metier_id' => $corpsMetier->id,
            'village_id' => $village->id,
        ]);

        $boutique = Boutique::create(['numero' => 'B-12', 'village_id' => $village->id]);
        $categorie = CategorieProduit::create(['code' => 'VAN-PAN', 'libelle' => 'Paniers']);

        $this->produit = Produit::create([
            'designation' => 'Panier tressé en raphia',
            'prix_unitaire' => 8000,
            'seuil_alerte' => 3,
            'categorie_id' => $categorie->id,
            'artisan_id' => $artisan->id,
            'boutique_id' => $boutique->id,
        ]);

        $this->service = app(ServiceMouvementStock::class);
    }

    /**
     * Les rôles sont créés à la volée plutôt que par le jeu d'amorçage
     * complet : ce sont leurs **noms** qui comptent ici, et un test
     * séparé vérifie que ces noms sont bien ceux du seeder.
     */
    protected function utilisateur(string $role, bool $actif = true): Utilisateur
    {
        $utilisateur = Utilisateur::create([
            'name' => $role,
            'email' => str_replace('_', '.', $role).($actif ? '' : '.inactif').'@varbaf.local',
            'password' => bcrypt('motdepasse'),
            'actif' => $actif,
        ]);

        $utilisateur->assignRole(Role::findOrCreate($role, 'web'));

        return $utilisateur;
    }

    /**
     * Amène le stock à 3, c'est-à-dire au seuil : 10 puis −7.
     */
    protected function franchirLeSeuil(): void
    {
        $this->service->deposer($this->produit, 10);
        $this->service->retirer($this->produit, 7, 'Ventes de la semaine');
    }

    protected function notificationsDe(Utilisateur $utilisateur): int
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $utilisateur->getMorphClass())
            ->where('notifiable_id', $utilisateur->getKey())
            ->count();
    }

    // =================================================================
    //  DESTINATAIRES
    // =================================================================

    public function test_le_franchissement_notifie_les_deux_sections(): void
    {
        $production = $this->utilisateur('chef_section_production');
        $commercialisation = $this->utilisateur('chef_section_promotion_commercialisation');

        $this->franchirLeSeuil();

        $this->assertSame(1, $this->notificationsDe($production));
        $this->assertSame(1, $this->notificationsDe($commercialisation));
    }

    public function test_une_section_etrangere_a_la_regle_n_est_pas_notifiee(): void
    {
        $formation = $this->utilisateur('chef_section_formation');

        $this->franchirLeSeuil();

        $this->assertSame(0, $this->notificationsDe($formation));
    }

    /**
     * Un compte désactivé garde ses rôles — la désactivation est
     * préférée à la suppression pour conserver les traces d'audit. Il
     * ne doit pas pour autant continuer à recevoir des alertes.
     */
    public function test_un_compte_desactive_ne_recoit_rien(): void
    {
        $parti = $this->utilisateur('chef_section_production', actif: false);

        $this->franchirLeSeuil();

        $this->assertSame(0, $this->notificationsDe($parti));
    }

    public function test_sans_destinataire_le_franchissement_ne_leve_pas(): void
    {
        $this->franchirLeSeuil();

        $this->assertSame(0, DatabaseNotification::count());
        $this->assertSame(3, $this->produit->getQuantiteEnStock());
    }

    // =================================================================
    //  MOMENT
    // =================================================================

    public function test_aucune_notification_avant_le_franchissement(): void
    {
        $this->utilisateur('chef_section_production');

        $this->service->deposer($this->produit, 10);
        $this->service->retirer($this->produit, 6, 'Ventes');  // 10 → 4, au-dessus du seuil

        $this->assertSame(0, DatabaseNotification::count());
    }

    /**
     * Le corollaire du test d'émission : le stock qui s'enfonce sous le
     * seuil ne renotifie pas. C'est ce qui distingue une alerte d'un
     * harcèlement — et ce qui décide si les sections la lisent encore
     * au bout d'un mois.
     */
    public function test_le_stock_qui_reste_sous_le_seuil_ne_renotifie_pas(): void
    {
        $this->utilisateur('chef_section_production');

        $this->franchirLeSeuil();
        $this->service->retirer($this->produit, 2, 'Ventes');  // 3 → 1

        $this->assertSame(1, DatabaseNotification::count());
    }

    // =================================================================
    //  MESSAGE
    // =================================================================

    /**
     * Le message doit suffire à agir sans ouvrir un écran : quel
     * produit, quel artisan appeler, quelle boutique, combien il reste.
     */
    public function test_le_message_nomme_le_produit_l_artisan_et_la_boutique(): void
    {
        $this->utilisateur('chef_section_production');

        $this->franchirLeSeuil();

        $donnees = DatabaseNotification::query()->firstOrFail()->data;

        $this->assertSame('Stock au seuil d\'alerte', $donnees['title']);
        $this->assertStringContainsString($this->produit->reference, $donnees['body']);
        $this->assertStringContainsString('Panier tressé en raphia', $donnees['body']);
        $this->assertStringContainsString('Kamdem', $donnees['body']);
        $this->assertStringContainsString('B-12', $donnees['body']);
        $this->assertStringContainsString('3 en stock pour un seuil de 3', $donnees['body']);
    }

    /**
     * Le format « filament » est ce qui permet à la cloche du panneau
     * de rendre la notification. Une notification écrite au format brut
     * de Laravel s'enregistrerait sans erreur et ne s'afficherait pas.
     */
    public function test_la_notification_est_lisible_par_la_cloche_du_panneau(): void
    {
        $this->utilisateur('chef_section_production');

        $this->franchirLeSeuil();

        $donnees = DatabaseNotification::query()->firstOrFail()->data;

        $this->assertSame('filament', $donnees['format']);
        $this->assertSame('warning', $donnees['status']);
    }

    // =================================================================
    //  CONFIGURATION
    // =================================================================

    /**
     * Le repli en dur de l'écouteur ne doit jamais être ce qui
     * travaille : s'il l'était, changer la configuration n'aurait plus
     * aucun effet et personne ne s'en apercevrait.
     */
    public function test_la_configuration_du_module_est_enregistree(): void
    {
        $this->assertSame(
            NotifierSeuilAlerte::ROLES_PAR_DEFAUT,
            config('commerce.alerte_stock.roles_destinataires'),
        );
    }

    public function test_les_roles_destinataires_sont_configurables(): void
    {
        config()->set('commerce.alerte_stock.roles_destinataires', ['chef_section_formation']);

        $production = $this->utilisateur('chef_section_production');
        $formation = $this->utilisateur('chef_section_formation');

        $this->franchirLeSeuil();

        $this->assertSame(0, $this->notificationsDe($production));
        $this->assertSame(1, $this->notificationsDe($formation));
    }

    /**
     * Le garde-fou du renommage : la configuration doit désigner des
     * rôles qui existent réellement dans le jeu d'amorçage. Un rôle
     * renommé dans `PermissionSeeder` sans l'être ici rendrait l'alerte
     * muette en production, sans qu'aucun autre test ne le voie.
     */
    public function test_les_roles_par_defaut_existent_dans_le_jeu_d_amorcage(): void
    {
        $this->seed(PermissionSeeder::class);

        foreach (NotifierSeuilAlerte::ROLES_PAR_DEFAUT as $role) {
            $this->assertTrue(
                Role::query()->where('name', $role)->where('guard_name', 'web')->exists(),
                "Le rôle « {$role} » n'existe pas dans PermissionSeeder.",
            );
        }
    }
}
