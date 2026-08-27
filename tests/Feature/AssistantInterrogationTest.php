<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Commerce\Enums\StatutValidationProduit;
use Modules\Commerce\Models\CategorieProduit;
use Modules\Commerce\Models\Produit;
use Modules\Pilotage\Assistant\CatalogueDIntentions;
use Modules\Pilotage\Assistant\ExtracteurDeParametres;
use Modules\Pilotage\Assistant\GardeDesChiffres;
use Modules\Pilotage\Assistant\Routeur;
use Modules\Pilotage\Enums\BrancheReponse;
use Modules\Pilotage\Enums\CategorieQuestion;
use Modules\Pilotage\Enums\TypeFicheLexicale;
use Modules\Pilotage\Recherche\MoteurMotsCles;
use Modules\Pilotage\Recherche\SegmentTrouve;
use Modules\Pilotage\Recommandation\MoteurLexical;
use Modules\Pilotage\Services\ServiceAssistant;
use Modules\Pilotage\Services\ServiceIndexationLexicale;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\VillageArtisanal;
use Tests\TestCase;

/**
 * Éprouve l'assistant d'interrogation : le routage, les trois
 * garde-fous, l'extraction des paramètres et les deux moteurs de
 * recherche.
 *
 * **Le test central est celui de la frontière.** Une question
 * d'agrégation ne doit jamais atteindre la recherche, une question
 * descriptive ne doit jamais atteindre `RapportService`. Tout le reste
 * en découle : sans cette garantie, la promesse « aucun montant produit
 * par proximité textuelle » n'est qu'une intention.
 */
class AssistantInterrogationTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected CorpsMetier $vannerie;

    protected CorpsMetier $sculpture;

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

        $this->vannerie = CorpsMetier::create([
            'code' => 'VAN',
            'libelle' => 'Vannerie',
            'description' => 'Tressage de fibres végétales en paniers et corbeilles',
        ]);

        $this->sculpture = CorpsMetier::create([
            'code' => 'SCU',
            'libelle' => 'Sculpture',
            'description' => 'Taille du bois et de la pierre',
        ]);

        $this->vannier = Artisan::create([
            'nom' => 'Kamdem',
            'prenom' => 'Jean',
            'corps_metier_id' => $this->vannerie->id,
            'village_id' => $this->village->id,
        ]);

        $this->boutique = Boutique::create(['numero' => 'B-04', 'village_id' => $this->village->id]);
        $this->paniers = CategorieProduit::create(['code' => 'PAN', 'libelle' => 'Paniers']);
    }

    protected function assistant(): ServiceAssistant
    {
        return app(ServiceAssistant::class);
    }

    protected function produit(string $designation, ?Artisan $artisan = null): Produit
    {
        $produit = Produit::create([
            'designation' => $designation,
            'prix_unitaire' => 10000,
            'categorie_id' => $this->paniers->id,
            'artisan_id' => ($artisan ?? $this->vannier)->id,
            'boutique_id' => $this->boutique->id,
        ]);

        $produit->changerStatut(StatutValidationProduit::VALIDE);
        $produit->changerStatut(StatutValidationProduit::EXPOSE);

        return $produit->fresh();
    }

    protected function indexer(): void
    {
        app(ServiceIndexationLexicale::class)->reindexer();
    }

    // =================================================================
    //  LA FRONTIÈRE
    // =================================================================

    public function test_une_question_d_agregation_ne_passe_jamais_par_la_recherche(): void
    {
        $this->produit('Panier tressé');
        $this->indexer();

        $reponse = $this->assistant()->repondre("Quel est le chiffre d'affaires ?");

        $this->assertSame(CategorieQuestion::AGREGATION, $reponse->categorie);
        $this->assertSame(BrancheReponse::CALCUL, $reponse->branche);
        $this->assertSame('chiffre_affaires', $reponse->intention);
        $this->assertTrue(
            $reponse->sources->isEmpty(),
            'Un chiffre vient d\'un calcul, pas d\'un extrait : lui attacher des sources documentaires laisserait croire à un rapprochement.',
        );
        $this->assertSame('rapport_service', $reponse->moteurCle);
    }

    public function test_une_question_descriptive_ne_passe_jamais_par_le_service_de_rapport(): void
    {
        $this->produit('Panier tressé');
        $this->indexer();

        $reponse = $this->assistant()->repondre('Quels artisans travaillent la vannerie ?');

        $this->assertSame(CategorieQuestion::DESCRIPTIVE, $reponse->categorie);
        $this->assertNull($reponse->intention, 'Aucune intention d\'agrégation ne doit être reconnue.');
        $this->assertNotSame('rapport_service', $reponse->moteurCle);
    }

    public function test_aucun_montant_ne_sort_de_la_branche_descriptive(): void
    {
        $this->produit('Panier tressé');
        $this->indexer();

        $reponse = $this->assistant()->repondre('Quels artisans travaillent la vannerie ?');

        if (! $reponse->aRepondu()) {
            $this->assertSame(BrancheReponse::REFUS, $reponse->branche);

            return;
        }

        $this->assertDoesNotMatchRegularExpression(
            '/\bFCFA\b/',
            $reponse->texte,
            'Une réponse descriptive ne libelle aucun montant.',
        );

        $this->assertTrue(
            app(GardeDesChiffres::class)->estAdosse($reponse->texte, $reponse->sources),
            'Tout chiffre de la réponse doit figurer dans un extrait retrouvé.',
        );
    }

    public function test_le_routeur_classe_les_vingt_et_une_intentions_sans_ambiguite(): void
    {
        $routeur = app(Routeur::class);

        $attendus = [
            "Quel est le chiffre d'affaires ?" => 'chiffre_affaires',
            'Quelles sont les recettes de commission ?' => 'recettes_commission',
            'Combien de ventes ont été enregistrées ?' => 'nombre_ventes',
            'Quel est le panier moyen ?' => 'panier_moyen',
            'Quelles sont les dettes envers les artisans ?' => 'dettes_artisans',
            'Quel est le solde de caisse ?' => 'solde_caisse',
            'Quel est le dernier reversement ?' => 'dernier_reversement',
            'Quelles sont les ventes par boutique ?' => 'ventes_par_boutique',
            'Quelles sont les ventes par artisan ?' => 'ventes_par_artisan',
            'Quelles sont les ventes par vendeur ?' => 'ventes_par_vendeur',
            'Quelle est la provenance des clients ?' => 'provenance_clients',
            'Quel artisan vend le plus ?' => 'meilleur_artisan',
            'Quelle boutique vend le plus ?' => 'meilleure_boutique',
            "Quelle est la situation de l'artisan ?" => 'situation_artisan',
            "Quelle est l'activité de la boutique ?" => 'activite_boutique',
            "Quel est le taux d'occupation du parc ?" => 'taux_occupation',
            'Quels produits sont en rupture de stock ?' => 'produits_sous_seuil',
            'Combien de produits en rupture ?' => 'nombre_produits_sous_seuil',
            'Quels sont les produits isolés du catalogue ?' => 'produits_isoles',
            'Quels sont les segments saturés ?' => 'segments_satures',
        ];

        foreach ($attendus as $question => $cle) {
            $routage = $routeur->classer($question);

            $this->assertTrue($routage->estAgregation(), "« {$question} » doit être une agrégation.");
            $this->assertSame($cle, $routage->intention?->cle, "« {$question} » doit reconnaître {$cle}.");
        }
    }

    public function test_le_catalogue_couvre_entre_quinze_et_vingt_cinq_intentions(): void
    {
        $nombre = app(CatalogueDIntentions::class)->nombre();

        $this->assertGreaterThanOrEqual(15, $nombre);
        $this->assertLessThanOrEqual(25, $nombre);
    }

    public function test_une_question_descriptive_ne_reconnait_aucune_intention(): void
    {
        $routeur = app(Routeur::class);

        foreach ([
            'Quels artisans travaillent la vannerie ?',
            'Qui fait de la sculpture au village ?',
            'Quels artisans soufflent le verre de Murano ?',
            'Quel est le taux de change du yen japonais ?',
        ] as $question) {
            $routage = $routeur->classer($question);

            $this->assertSame(
                CategorieQuestion::DESCRIPTIVE,
                $routage->categorie,
                "« {$question} » ne doit pas être prise pour une agrégation.",
            );
        }
    }

    // =================================================================
    //  GARDE-FOU 1 — RIEN SOUS LE SEUIL
    // =================================================================

    public function test_sous_le_seuil_l_assistant_refuse_et_ne_formule_rien(): void
    {
        $this->produit('Panier tressé');
        $this->indexer();

        $reponse = $this->assistant()->repondre('Quels artisans soufflent le verre de Murano ?');

        $this->assertSame(BrancheReponse::REFUS, $reponse->branche);
        $this->assertFalse($reponse->aRepondu());
        $this->assertTrue($reponse->sources->isEmpty());
        $this->assertStringContainsString('pas disponible', $reponse->texte);
    }

    public function test_sans_index_la_branche_descriptive_refuse_au_lieu_d_echouer(): void
    {
        $this->produit('Panier tressé');

        // Volontairement pas d'indexation.
        $reponse = $this->assistant()->repondre('Quels artisans travaillent la vannerie ?');

        $this->assertSame(BrancheReponse::REFUS, $reponse->branche);
        $this->assertStringContainsString('varbaf:indexer', $reponse->texte);
    }

    public function test_une_question_vide_est_refusee(): void
    {
        $reponse = $this->assistant()->repondre('   ');

        $this->assertSame(BrancheReponse::REFUS, $reponse->branche);
    }

    // =================================================================
    //  GARDE-FOU 2 — AUCUN CHIFFRE SANS SOURCE
    // =================================================================

    public function test_la_garde_repere_un_chiffre_absent_des_extraits(): void
    {
        $garde = app(GardeDesChiffres::class);

        $sources = collect([
            new SegmentTrouve(1, TypeFicheLexicale::PRODUIT, 1, 'BTQ04-0001 — Panier', 'Panier tressé de 40 cm', 0.8),
        ]);

        $this->assertTrue($garde->estAdosse('Le panier mesure 40 cm.', $sources));
        $this->assertFalse($garde->estAdosse('Le village compte 137 artisans.', $sources));
        $this->assertSame(['137'], $garde->chiffresSansSource('Le village compte 137 artisans.', $sources));
    }

    public function test_la_garde_ignore_les_separateurs_de_milliers(): void
    {
        $garde = app(GardeDesChiffres::class);

        $sources = collect([
            new SegmentTrouve(1, TypeFicheLexicale::PRODUIT, 1, 'Panier', 'Vendu 12000 francs', 0.8),
        ]);

        $this->assertTrue(
            $garde->estAdosse('Il coûte 12 000 francs.', $sources),
            '« 12 000 » et « 12000 » désignent le même nombre.',
        );
    }

    public function test_une_reponse_descriptive_ne_cite_que_ses_extraits(): void
    {
        $this->produit('Panier tressé raphia');
        $this->indexer();

        $reponse = $this->assistant()->repondre('Quels produits en vannerie ?');

        if ($reponse->aRepondu()) {
            $this->assertTrue(app(GardeDesChiffres::class)->estAdosse($reponse->texte, $reponse->sources));
        } else {
            $this->assertSame(BrancheReponse::REFUS, $reponse->branche);
        }
    }

    // =================================================================
    //  GARDE-FOU 3 — LES SOURCES ACCOMPAGNENT LA RÉPONSE
    // =================================================================

    public function test_toute_reponse_descriptive_est_accompagnee_de_ses_sources(): void
    {
        $this->produit('Panier tressé');
        $this->produit('Corbeille tressée');
        $this->indexer();

        $reponse = $this->assistant()->repondre('Quels produits en vannerie ?');

        $this->assertTrue($reponse->aRepondu(), 'La vannerie est au corpus : la recherche doit trouver.');
        $this->assertGreaterThan(0, $reponse->sources->count());

        foreach ($reponse->sources as $source) {
            $this->assertNotSame('', $source->titre, 'Une source sans titre n\'est pas vérifiable.');
            $this->assertNotSame('', $source->extrait, 'Une source sans extrait ne prouve rien.');
        }
    }

    /**
     * La réponse nomme le moteur qui l'a produite.
     *
     * Dans la configuration livrée, l'ordre ne retient que le lexical —
     * la branche dense a été écartée le 27/08 sur mesure, le motif est
     * en commentaire dans le fichier de configuration du Pilotage. Le
     * nom affiché doit donc désigner le lexical, sans mention d'une
     * fusion qui n'a pas eu lieu.
     */
    public function test_le_moteur_qui_a_repondu_est_nomme(): void
    {
        $this->produit('Panier tressé');
        $this->indexer();

        $reponse = $this->assistant()->repondre('Quels produits en vannerie ?');

        $this->assertSame('lexical', $reponse->moteurCle);
        $this->assertStringContainsString('TF-IDF', (string) $reponse->moteur);
        $this->assertStringNotContainsString('⊕', (string) $reponse->moteur);
    }

    /**
     * Et quand l'hybride répond, il dit laquelle de ses branches a servi.
     *
     * **L'ordre est posé ici, pas lu dans la configuration.** Ce test
     * éprouve le nommage du moteur composite, pas la préférence de
     * déploiement : lier les deux l'a fait échouer le 27/08 pour une
     * décision qui ne le concernait pas.
     *
     * Aucun fournisseur d'embeddings ne tourne pendant les tests — c'est
     * délibéré, une suite qui exigerait un service lancé ne s'exécuterait
     * chez personne d'autre. L'hybride se réduit donc à sa branche
     * lexicale, et le nom affiché doit le **dire** : c'est toute la
     * différence entre un système dégradé qui s'annonce et un système
     * dégradé qui se tait.
     */
    public function test_l_hybride_annonce_sa_branche_lexicale_quand_le_dense_se_tait(): void
    {
        config(['pilotage.moteur.ordre' => ['hybride', 'lexical']]);

        $this->produit('Panier tressé');
        $this->indexer();

        $reponse = $this->assistant()->repondre('Quels produits en vannerie ?');

        $this->assertSame('hybride', $reponse->moteurCle);
        $this->assertStringContainsString('branche lexical seule', (string) $reponse->moteur);
        $this->assertStringContainsString('TF-IDF', (string) $reponse->moteur);
    }

    // =================================================================
    //  EXTRACTION DES PARAMÈTRES
    // =================================================================

    public function test_un_mois_nomme_borne_la_periode(): void
    {
        $parametres = app(ExtracteurDeParametres::class)->extraire("Quel chiffre d'affaires en juillet 2024 ?");

        $this->assertTrue($parametres->periodeExplicite);
        $this->assertSame('2024-07-01', $parametres->filtre->du?->toDateString());
        $this->assertSame('2024-07-31', $parametres->filtre->au?->toDateString());
        $this->assertStringContainsString('juillet', $parametres->libellePeriode);
    }

    public function test_une_annee_seule_borne_l_annee_entiere(): void
    {
        $parametres = app(ExtracteurDeParametres::class)->extraire("Quel chiffre d'affaires en 2024 ?");

        $this->assertTrue($parametres->periodeExplicite);
        $this->assertSame('2024-01-01', $parametres->filtre->du?->toDateString());
        $this->assertSame('2024-12-31', $parametres->filtre->au?->toDateString());
    }

    public function test_sans_periode_nommee_l_exercice_courant_s_applique_et_le_dit(): void
    {
        $parametres = app(ExtracteurDeParametres::class)->extraire("Quel est le chiffre d'affaires ?");

        $this->assertFalse(
            $parametres->periodeExplicite,
            'Le silence doit rester distinguable d\'une période nommée.',
        );
        $this->assertNotNull($parametres->filtre->exerciceId);
        $this->assertStringContainsString('exercice', $parametres->libellePeriode);
    }

    public function test_un_artisan_nomme_est_reconnu_contre_la_base(): void
    {
        $parametres = app(ExtracteurDeParametres::class)->extraire('Quelle est la situation de Kamdem ?');

        $this->assertSame($this->vannier->id, $parametres->artisanId);
        $this->assertStringContainsString('Kamdem', (string) $parametres->artisanNom);
        $this->assertSame($this->vannier->matricule, $parametres->artisanMatricule);
    }

    public function test_une_boutique_est_reconnue_par_son_numero(): void
    {
        foreach (['Activité de la boutique B04 ?', 'Activité de la boutique B-04 ?', 'Activité de la boutique 4 ?'] as $question) {
            $parametres = app(ExtracteurDeParametres::class)->extraire($question);

            $this->assertSame($this->boutique->id, $parametres->boutiqueId, "« {$question} » doit désigner B-04.");
        }
    }

    public function test_un_corps_de_metier_est_reconnu(): void
    {
        $parametres = app(ExtracteurDeParametres::class)->extraire('Quels artisans font de la vannerie ?');

        $this->assertSame($this->vannerie->id, $parametres->corpsMetierId);
        $this->assertSame('Vannerie', $parametres->corpsMetierLibelle);
    }

    // =================================================================
    //  DEMANDE DE PRÉCISION
    // =================================================================

    public function test_une_intention_a_parametre_manquant_demande_la_precision(): void
    {
        $reponse = $this->assistant()->repondre("Quelle est la situation de l'artisan ?");

        $this->assertSame(CategorieQuestion::AGREGATION, $reponse->categorie);
        $this->assertSame(BrancheReponse::PRECISION, $reponse->branche);
        $this->assertTrue($reponse->demandeUnePrecision());
        $this->assertStringContainsString('nom de l\'artisan', $reponse->texte);
        $this->assertStringNotContainsString('FCFA', $reponse->texte, 'Rien ne doit être calculé.');
    }

    public function test_la_precision_fournie_debloque_le_calcul(): void
    {
        $reponse = $this->assistant()->repondre('Quelle est la situation de l\'artisan Kamdem ?');

        $this->assertSame(BrancheReponse::CALCUL, $reponse->branche);
        $this->assertSame('situation_artisan', $reponse->intention);
        $this->assertStringContainsString('Kamdem', $reponse->texte);
    }

    public function test_une_boutique_manquante_demande_aussi_la_precision(): void
    {
        $reponse = $this->assistant()->repondre("Quelle est l'activité de la boutique ?");

        $this->assertSame(BrancheReponse::PRECISION, $reponse->branche);
        $this->assertStringContainsString('numéro de la boutique', $reponse->texte);
    }

    // =================================================================
    //  LES DEUX MOTEURS — COMPARAISON H3
    // =================================================================

    public function test_les_deux_moteurs_partagent_la_meme_tokenisation(): void
    {
        $this->produit('Panier tressé raphia');
        $this->indexer();

        $lexical = app(MoteurLexical::class)->rechercher('panier raphia', 5, 0.0);
        $motsCles = app(MoteurMotsCles::class)->rechercher('panier raphia', 5, 0.0);

        $this->assertGreaterThan(0, $lexical->count());
        $this->assertGreaterThan(0, $motsCles->count());
    }

    public function test_le_moteur_temoin_se_nomme_et_ne_sert_pas_de_repli(): void
    {
        $temoin = app(MoteurMotsCles::class);

        $this->assertSame('mots_cles', $temoin->cle());
        $this->assertStringContainsString('témoin', $temoin->nom());
        $this->assertNotContains(
            'mots_cles',
            (array) config('pilotage.moteur.ordre'),
            'Le témoin est un instrument de mesure, pas un moteur de repli.',
        );
    }

    public function test_le_moteur_impose_est_celui_qui_repond(): void
    {
        $this->produit('Panier tressé');
        $this->indexer();

        $reponse = $this->assistant()->repondre('Quels produits en vannerie ?', app(MoteurMotsCles::class));

        $this->assertSame('mots_cles', $reponse->moteurCle);
    }

    // =================================================================
    //  LA COMMANDE D'ÉVALUATION
    // =================================================================

    public function test_la_commande_d_evaluation_produit_les_trois_mesures(): void
    {
        $this->produit('Panier tressé');
        $this->indexer();

        $this->artisan('varbaf:evaluer-assistant')
            ->expectsOutputToContain('Table 4.3')
            ->assertSuccessful();
    }

    public function test_la_commande_d_evaluation_refuse_un_fichier_absent(): void
    {
        $this->artisan('varbaf:evaluer-assistant', ['fichier' => 'docs/introuvable.csv'])->assertFailed();
    }
}
