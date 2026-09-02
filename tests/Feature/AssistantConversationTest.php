<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Commerce\Enums\StatutValidationProduit;
use Modules\Commerce\Models\CategorieProduit;
use Modules\Commerce\Models\Produit;
use Modules\Pilotage\Contracts\ModeleDeLangage;
use Modules\Pilotage\Enums\BrancheReponse;
use Modules\Pilotage\Services\ServiceAssistant;
use Modules\Pilotage\Services\ServiceIndexationLexicale;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\VillageArtisanal;
use Tests\Doubles\ModeleDeLangageDeTest;
use Tests\TestCase;

/**
 * L'assistant conversationnel — et la frontière qu'il ne franchit pas.
 *
 * Deux capacités sont ajoutées ici, et chacune est bordée par ce qu'elle
 * n'a **pas** le droit de faire.
 *
 * L'**accueil** répond à ce qui n'est pas une question sur le village.
 * Le modèle n'y reçoit aucune donnée : il ne peut donc rien affirmer, et
 * comme il n'y a aucun extrait à confronter, tout chiffre dans sa sortie
 * la fait rejeter — un contrôle plus strict qu'ailleurs, pas plus souple.
 *
 * La **reformulation** rend une question de suite autonome. Le modèle
 * produit une *question*, jamais une réponse : elle repart dans le
 * routeur déterministe, et le chiffre reste calculé par
 * `RapportService`. Ce n'est pas le `classer()` écarté le 27/08, où le
 * modèle choisissait quelle branche répond.
 *
 * Le test qui compte le plus est celui qui vérifie qu'une des huit
 * questions du jeu d'évaluation continue de **refuser** : c'est lui qui
 * dit que l'accueil n'a pas ouvert une porte dérobée dans le refus.
 */
class AssistantConversationTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Artisan $vannier;

    protected Boutique $boutique;

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

        $vannerie = CorpsMetier::create([
            'code' => 'VAN',
            'libelle' => 'Vannerie',
            'description' => 'Tressage de fibres végétales en paniers et corbeilles',
        ]);

        $this->vannier = Artisan::create([
            'nom' => 'Kamdem',
            'prenom' => 'Jean',
            'corps_metier_id' => $vannerie->id,
            'village_id' => $this->village->id,
        ]);

        $this->boutique = Boutique::create(['numero' => 'B-04', 'village_id' => $this->village->id]);
        $this->paniers = CategorieProduit::create(['code' => 'PAN', 'libelle' => 'Paniers']);

        $this->produit('Panier tressé');
        $this->indexer();
    }

    // =================================================================
    //  L'ACCUEIL
    // =================================================================

    public function test_une_salutation_est_accueillie_et_non_refusee(): void
    {
        $reponse = $this->assistant()->repondre('bonjour');

        // Le refus dit « j'ai cherché et je n'ai rien ». Ici rien n'a
        // été cherché : aucun mot de la saisie n'est au vocabulaire, le
        // moteur s'est arrêté avant. Servir le message de refus serait
        // un diagnostic faux pour une décision juste.
        $this->assertSame(BrancheReponse::ACCUEIL, $reponse->branche);
        $this->assertStringNotContainsString('seuil de similarité', $reponse->texte);
    }

    /**
     * Le test qui borde l'accueil — et qui a démenti une déduction.
     *
     * Cette question est reprise **mot pour mot** du jeu d'évaluation,
     * où elle est marquée `refus_attendu = oui`.
     *
     * Le premier aiguillage écrit pour l'accueil reposait sur « aucun
     * mot de la saisie n'est au vocabulaire », et le raisonnement qui
     * l'accompagnait affirmait que les huit questions à refuser
     * portaient, elles, des mots connus — « artisans », « village ».
     * C'était faux : les fiches du corpus portent des désignations, des
     * catégories, des métiers et des *noms* d'artisans ; le mot
     * « artisan » n'y figure nulle part. Cette question était donc
     * accueillie au lieu d'être refusée, et le taux de refus correct de
     * la table 4.3 serait tombé sans qu'aucune autre alerte ne se
     * déclenche.
     *
     * Le critère est désormais la **forme** de la saisie, pas son
     * vocabulaire : un point d'interrogation ou un mot interrogatif
     * signifie une demande, et une demande sans réponse se refuse.
     */
    public function test_une_question_hors_corpus_refuse_toujours(): void
    {
        $reponse = $this->assistant()->repondre('Quels artisans soufflent le verre de Murano ?');

        $this->assertSame(BrancheReponse::REFUS, $reponse->branche);
        $this->assertStringContainsString('seuil de similarité', $reponse->texte);
    }

    /**
     * Une demande courte, sans un mot connu, se refuse quand même.
     *
     * C'est le cas limite du critère : trois mots, aucun vocabulaire, et
     * pourtant une question. Le point d'interrogation suffit à écarter
     * l'accueil — et il doit suffire, sans quoi il resterait une porte
     * par laquelle une question à refuser sortirait en salutation.
     */
    public function test_une_demande_courte_et_inconnue_est_refusee_et_non_accueillie(): void
    {
        $reponse = $this->assistant()->repondre('des skis ?');

        $this->assertSame(BrancheReponse::REFUS, $reponse->branche);
    }

    public function test_un_remerciement_est_accueilli(): void
    {
        $reponse = $this->assistant()->repondre('merci beaucoup');

        $this->assertSame(BrancheReponse::ACCUEIL, $reponse->branche);
    }

    public function test_sans_modele_l_accueil_sert_une_phrase_fixe(): void
    {
        $this->app->instance(ModeleDeLangage::class, ModeleDeLangageDeTest::muet());

        $reponse = $this->assistant()->repondre('bonjour');

        $this->assertSame(BrancheReponse::ACCUEIL, $reponse->branche);
        $this->assertStringContainsString('artisans', $reponse->texte);
        // Rien n'a été rédigé : le dire, plutôt que de laisser croire
        // qu'un modèle est passé sur un texte qu'il n'a pas vu.
        $this->assertNull($reponse->redacteur);
    }

    /**
     * Le contrôle qui remplace `GardeDesChiffres` sur ce chemin.
     *
     * L'accueil n'a aucun extrait à confronter : la règle ne peut donc
     * pas être « tout chiffre doit figurer dans une source », elle est
     * « aucun chiffre ». Un modèle qui répondrait « Bonjour ! Le village
     * compte 47 artisans » serait plausible, aimable, et faux — et rien
     * dans la consigne ne peut l'en empêcher de manière garantie.
     */
    public function test_un_accueil_qui_avance_un_chiffre_est_ecarte(): void
    {
        $this->app->instance(
            ModeleDeLangage::class,
            (new ModeleDeLangageDeTest)->accueilAvecChiffre('Bonjour ! Le village compte 47 artisans.'),
        );

        $reponse = $this->assistant()->repondre('bonjour');

        $this->assertSame(BrancheReponse::ACCUEIL, $reponse->branche);
        $this->assertStringNotContainsString('47', $reponse->texte);
        $this->assertNull($reponse->redacteur, 'La tournure ayant été écartée, aucun rédacteur ne doit être annoncé.');
    }

    // =================================================================
    //  LA REFORMULATION
    // =================================================================

    public function test_une_question_de_suite_est_reformulee_et_la_reformulation_est_exposee(): void
    {
        $this->app->instance(
            ModeleDeLangage::class,
            (new ModeleDeLangageDeTest)->reformulantEn('Quels artisans travaillent la vannerie ?'),
        );

        $historique = [[
            'question' => 'Quels produits en vannerie sont exposés ?',
            'reponse' => 'Le corpus mentionne un panier tressé.',
        ]];

        $reponse = $this->assistant()->repondre('et les artisans ?', historique: $historique);

        // La reformulation s'affiche : une question reconstruite en
        // silence serait un endroit où le sens peut changer sans que
        // personne le voie.
        $this->assertSame('Quels artisans travaillent la vannerie ?', $reponse->questionReformulee);

        // Et c'est bien elle qui a été traitée, pas la saisie.
        $this->assertSame('Quels artisans travaillent la vannerie ?', $reponse->question);
    }

    public function test_sans_historique_aucune_reformulation_n_est_tentee(): void
    {
        $this->app->instance(
            ModeleDeLangage::class,
            (new ModeleDeLangageDeTest)->reformulantEn('Une question inventée de toutes pièces.'),
        );

        $reponse = $this->assistant()->repondre('et les artisans ?');

        // Le premier tour d'une conversation n'a rien à reconstruire :
        // le modèle ne doit pas être consulté, et surtout sa sortie ne
        // doit pas se substituer à ce que l'utilisateur a tapé.
        $this->assertNull($reponse->questionReformulee);
        $this->assertSame('et les artisans ?', $reponse->question);
    }

    /**
     * Le filtre déterministe qui décide s'il faut reformuler.
     *
     * Une saisie de plus de six mots se suffit presque toujours à
     * elle-même. Le filtre évite un aller-retour réseau sur chaque
     * question complète — le budget est de huit secondes, deux appels en
     * feraient seize — et il est tenu par le code, non par le modèle.
     */
    public function test_une_question_deja_autonome_n_est_pas_reformulee(): void
    {
        $this->app->instance(
            ModeleDeLangage::class,
            (new ModeleDeLangageDeTest)->reformulantEn('Une question inventée de toutes pièces.'),
        );

        $historique = [[
            'question' => 'Quels produits en vannerie sont exposés ?',
            'reponse' => 'Le corpus mentionne un panier tressé.',
        ]];

        $question = 'Quels artisans du village travaillent la vannerie aujourd\'hui ?';

        $reponse = $this->assistant()->repondre($question, historique: $historique);

        $this->assertNull($reponse->questionReformulee);
        $this->assertSame($question, $reponse->question);
    }

    public function test_sans_modele_la_saisie_brute_est_conservee(): void
    {
        $this->app->instance(ModeleDeLangage::class, ModeleDeLangageDeTest::muet());

        $historique = [[
            'question' => 'Quels produits en vannerie sont exposés ?',
            'reponse' => 'Le corpus mentionne un panier tressé.',
        ]];

        $reponse = $this->assistant()->repondre('et les artisans ?', historique: $historique);

        // Un système dégradé répond moins bien, il ne répond pas à côté :
        // faute de reformulation, la saisie part telle quelle et sera
        // mal comprise — ce que le refus dira.
        $this->assertNull($reponse->questionReformulee);
        $this->assertSame('et les artisans ?', $reponse->question);
    }

    // =================================================================

    protected function assistant(): ServiceAssistant
    {
        return app(ServiceAssistant::class);
    }

    protected function produit(string $designation): Produit
    {
        $produit = Produit::create([
            'designation' => $designation,
            'prix_unitaire' => 10000,
            'categorie_id' => $this->paniers->id,
            'artisan_id' => $this->vannier->id,
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
}
