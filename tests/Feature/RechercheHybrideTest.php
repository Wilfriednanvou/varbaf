<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Commerce\Enums\StatutValidationProduit;
use Modules\Commerce\Models\CategorieProduit;
use Modules\Commerce\Models\Produit;
use Modules\Pilotage\Contracts\FournisseurDEmbeddings;
use Modules\Pilotage\Embeddings\ClientOllama;
use Modules\Pilotage\Embeddings\Vecteurs;
use Modules\Pilotage\Enums\TypeFicheLexicale;
use Modules\Pilotage\Recherche\FusionReciproque;
use Modules\Pilotage\Recherche\MoteurDense;
use Modules\Pilotage\Recherche\MoteurHybride;
use Modules\Pilotage\Recherche\SegmentTrouve;
use Modules\Pilotage\Recommandation\MoteurLexical;
use Modules\Pilotage\Recommandation\ResolveurDeMoteur;
use Modules\Pilotage\Services\ServiceIndexationDense;
use Modules\Pilotage\Services\ServiceIndexationLexicale;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\VillageArtisanal;
use Tests\Doubles\FournisseurDEmbeddingsDeTest;
use Tests\TestCase;

/**
 * La recherche hybride : deux façons de chercher, une seule réponse.
 *
 * **Ce que ce fichier doit établir, dans l'ordre.** Que la branche dense
 * trouve ce que le lexical ne peut pas trouver — sans quoi elle ne
 * servirait à rien. Que la fusion préfère l'accord de deux techniques à
 * l'enthousiasme d'une seule — c'est la règle de classement, et elle est
 * éprouvée sur des rangs synthétiques plutôt que sur un corpus, parce
 * qu'une règle de classement se démontre, elle ne s'observe pas. Et que
 * l'absence du fournisseur d'embeddings dégrade le système **en le
 * disant**, ce qui est toute la différence entre un repli et une panne
 * silencieuse.
 *
 * Aucun test n'exige qu'un modèle tourne sur la machine : le port
 * `FournisseurDEmbeddings` existe d'abord pour cela.
 */
class RechercheHybrideTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Artisan $artisan;

    protected Boutique $boutique;

    protected CategorieProduit $categorie;

    protected function setUp(): void
    {
        parent::setUp();

        // Aucun appel sortant ne doit sortir d'un test : sans ce garde,
        // le résultat dépendrait de la présence d'un Ollama sur la
        // machine qui exécute la suite.
        //
        // Pas de `Http::fake('*')` global ici, et c'est délibéré : les
        // doublures s'empilent dans leur ordre d'enregistrement et la
        // première qui répond gagne. Un joker posé en `setUp()` rendrait
        // inatteignables les doublures précises des trois tests du
        // client — qui échoueraient alors pour une raison sans aucun
        // rapport avec ce qu'ils vérifient.
        Http::preventStrayRequests();

        $this->village = VillageArtisanal::create([
            'code' => 'VARBAF',
            'nom' => 'Village Artisanal Régional de Bafoussam',
            'categorie' => CategorieVillage::REGIONAL,
            'region' => 'Ouest',
            'actif' => true,
        ]);

        $corpsMetier = CorpsMetier::create([
            'code' => 'POT',
            'libelle' => 'Poterie',
            'description' => 'Façonnage de la terre',
        ]);

        $this->artisan = Artisan::create([
            'nom' => 'Kamdem',
            'prenom' => 'Jean',
            'corps_metier_id' => $corpsMetier->id,
            'village_id' => $this->village->id,
        ]);

        $this->boutique = Boutique::create(['numero' => 'B-04', 'village_id' => $this->village->id]);
        $this->categorie = CategorieProduit::create(['code' => 'DIV', 'libelle' => 'Divers']);
    }

    // =================================================================
    //  CE QUE LA BRANCHE DENSE APPORTE
    // =================================================================

    /**
     * Le défaut que le dense corrige, montré sur le cas qui le montre.
     *
     * « objets pour la cuisine » et « Marmite en terre cuite » n'ont pas
     * un mot en commun. Le TF-IDF ne peut structurellement pas les
     * rapprocher : sa similarité est nulle, et ce n'est pas un réglage à
     * ajuster mais la définition de ce qu'il mesure.
     */
    public function test_la_branche_dense_trouve_ce_que_le_lexical_ne_peut_pas_trouver(): void
    {
        $marmite = $this->produit('Marmite en terre cuite');
        $panier = $this->produit('Panier tressé en raphia');
        $this->indexer();

        $question = 'objets pour la cuisine';

        $this->assertTrue(
            app(MoteurLexical::class)->rechercher($question, 5)->isEmpty(),
            'Sans mot commun, le TF-IDF ne peut rien rapprocher — c\'est le défaut que le dense corrige.',
        );

        $dense = $this->moteurDense();

        $this->assertTrue($dense->estDisponible());

        $trouves = $dense->rechercher($question, 5);

        $this->assertTrue(
            $this->contientLeProduit($trouves, $marmite->id),
            'La marmite relève du thème « cuisine » et doit être retrouvée sans partager un mot avec la question.',
        );
        $this->assertFalse(
            $this->contientLeProduit($trouves, $panier->id),
            'Le dense ne doit pas tout rapprocher : le panier est hors du thème.',
        );
    }

    public function test_un_vecteur_calcule_par_un_autre_modele_est_ignore(): void
    {
        $this->produit('Marmite en terre cuite');
        $this->indexer();
        $this->vectoriser(FournisseurDEmbeddingsDeTest::parTheme(modele: 'modele-a'));

        // Le même corpus, interrogé par un moteur qui n'emploie pas le
        // modèle qui l'a vectorisé. Deux modèles définissent des espaces
        // sans rapport : les comparer rendrait des rapprochements
        // plausibles et faux, ce qui est pire que pas de rapprochement
        // du tout puisque rien ne le signalerait.
        $autre = new MoteurDense(FournisseurDEmbeddingsDeTest::parTheme(modele: 'modele-b'));

        $this->assertFalse(
            $autre->estDisponible(),
            'Un index vectorisé par un autre modèle doit compter pour un corpus vide.',
        );
        $this->assertTrue($autre->rechercher('objets pour la cuisine', 5)->isEmpty());
    }

    // =================================================================
    //  LA RÈGLE DE CLASSEMENT
    // =================================================================

    /**
     * Le cœur de la fusion : l'accord vaut mieux que l'enthousiasme.
     *
     * Un passage classé troisième par un moteur et premier par l'autre
     * passe devant un passage classé premier par un seul. C'est la
     * propriété que la fusion existe pour produire, et elle s'éprouve
     * sur des rangs, pas sur un corpus — un corpus la montrerait par
     * accident, il ne la démontrerait pas.
     */
    public function test_un_passage_connu_des_deux_branches_passe_devant_un_favori_solitaire(): void
    {
        $fusion = FusionReciproque::fusionner(
            [
                'lexical' => $this->segments([1, 2, 3]),
                'dense' => $this->segments([3, 4]),
            ],
            ['lexical' => 1.0, 'dense' => 1.0],
            60,
            5,
        );

        $this->assertSame(
            3,
            $fusion->first()->ficheId,
            'Troisième chez l\'un et premier chez l\'autre doit primer sur premier chez un seul.',
        );
    }

    public function test_le_score_vaut_un_quand_les_deux_branches_le_classent_premier(): void
    {
        $fusion = FusionReciproque::fusionner(
            [
                'lexical' => $this->segments([7]),
                'dense' => $this->segments([7]),
            ],
            ['lexical' => 1.0, 'dense' => 1.0],
            60,
            5,
        );

        $this->assertEqualsWithDelta(1.0, $fusion->first()->similarite, 0.0001);
    }

    public function test_un_passage_connu_d_une_seule_branche_sur_deux_plafonne_a_la_moitie(): void
    {
        $fusion = FusionReciproque::fusionner(
            [
                'lexical' => $this->segments([7]),
                'dense' => $this->segments([9]),
            ],
            ['lexical' => 1.0, 'dense' => 1.0],
            60,
            5,
        );

        // Le score n'est pas une similarité mais un degré d'accord :
        // 0,5 se lit « une branche sur deux le connaît ».
        $this->assertEqualsWithDelta(0.5, $fusion->first()->similarite, 0.0001);
    }

    /**
     * Une seule branche debout ne doit pas plafonner les scores.
     *
     * Sinon un village dont le service d'embeddings est arrêté verrait
     * tous ses résultats à 50 %, et croirait sa recherche dégradée alors
     * que la branche lexicale a fait exactement son travail habituel.
     */
    public function test_le_maximum_ne_compte_que_les_branches_qui_ont_repondu(): void
    {
        $fusion = FusionReciproque::fusionner(
            [
                'lexical' => $this->segments([7]),
                'dense' => new Collection(),
            ],
            ['lexical' => 1.0, 'dense' => 1.0],
            60,
            5,
        );

        $this->assertEqualsWithDelta(1.0, $fusion->first()->similarite, 0.0001);
    }

    // =================================================================
    //  LE REPLI, ET LE FAIT QU'IL SE VOIE
    // =================================================================

    public function test_l_hybride_repond_encore_quand_le_fournisseur_est_arrete(): void
    {
        $panier = $this->produit('Panier tressé en raphia');
        $this->indexer();

        $hybride = $this->moteurHybride(FournisseurDEmbeddingsDeTest::parTheme(disponible: false));

        $this->assertTrue(
            $hybride->estDisponible(),
            'Une branche suffit : refuser de chercher parce qu\'il en manque une serait une panne inventée.',
        );

        $trouves = $hybride->rechercher('panier raphia', 5);

        $this->assertNotEmpty($trouves);
        $this->assertTrue(
            $this->contientLeProduit($trouves, $panier->id),
            'Avec la seule branche lexicale, l\'hybride doit rendre ce que le lexical rendait.',
        );
    }

    /**
     * Le repli doit se voir à l'écran, pas seulement dans les journaux.
     *
     * C'est la différence entre un système dégradé qui s'annonce et un
     * système dégradé qui se tait — et, devant un jury, entre une
     * démonstration et un tour de passe-passe.
     */
    public function test_l_hybride_nomme_la_branche_qui_a_reellement_repondu(): void
    {
        $this->produit('Marmite en terre cuite');
        $this->indexer();

        $arrete = $this->moteurHybride(FournisseurDEmbeddingsDeTest::parTheme(disponible: false));

        $this->assertStringContainsString('branche lexical seule', $arrete->nom());
        $this->assertStringNotContainsString('⊕', $arrete->nom());

        $this->vectoriser(FournisseurDEmbeddingsDeTest::parTheme());
        $lesDeux = $this->moteurHybride(FournisseurDEmbeddingsDeTest::parTheme());

        $this->assertStringContainsString('⊕', $lesDeux->nom());
        $this->assertStringContainsString('TF-IDF', $lesDeux->nom());
    }

    /**
     * Le résolveur sait désigner l'hybride — si on le lui demande.
     *
     * **L'ordre est posé par le test, et ce n'est pas un détail.** Cette
     * méthode éprouve le mécanisme de résolution, pas la préférence de
     * déploiement du jour : elle affirmait « hybride » en lisant la
     * configuration de production, et elle est tombée le 27/08 quand
     * cette configuration a changé pour un motif qui ne la concernait
     * pas. Un test qui échoue parce qu'une décision a été prise ailleurs
     * ne dit rien sur le code qu'il couvre.
     */
    public function test_le_resolveur_designe_l_hybride_quand_l_ordre_le_demande(): void
    {
        config(['pilotage.moteur.ordre' => ['hybride', 'lexical']]);

        $this->produit('Panier tressé en raphia');
        $this->indexer();

        // Aucun vecteur n'a été calculé : `MoteurDense` vérifie l'index
        // avant de sonder le réseau, aucun appel sortant n'a donc lieu.
        $this->assertSame('hybride', app(ResolveurDeMoteur::class)->resoudre()->cle());
    }

    /**
     * L'ordre livré est le seul lexical, et cela s'affirme.
     *
     * La branche dense a été construite, mesurée, puis écartée le 27/08 :
     * rappel@5 inchangé et taux de refus correct tombé de 100 % à 0 % sur
     * les 48 questions du jeu d'évaluation. Le motif complet est en
     * commentaire dans « Modules/Pilotage/config/config.php ».
     *
     * Ce test ne couvre pas un mécanisme — il **retient une décision**.
     * Remettre l'hybride en tête sans refaire la mesure le fera échouer,
     * ce qui est exactement le service attendu : le code qui rendait le
     * refus inopérant est toujours là, enregistré au catalogue, et rien
     * d'autre n'empêcherait qu'on l'y remette par mégarde.
     */
    public function test_l_ordre_livre_ne_retient_que_le_lexical(): void
    {
        $this->assertSame(
            ['lexical'],
            app(ResolveurDeMoteur::class)->ordre(),
            'La branche dense a été écartée sur mesure : voir config.php du Pilotage.',
        );
    }

    // =================================================================
    //  LE CLIENT OLLAMA
    // =================================================================

    public function test_le_client_ollama_se_tait_quand_le_service_ne_repond_pas(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $client = new ClientOllama;

        $this->assertFalse($client->estDisponible());
        $this->assertNull($client->vecteur('un texte quelconque'));
    }

    public function test_le_client_ollama_reconnait_un_modele_etiquete(): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => [['name' => 'nomic-embed-text:latest']]]),
            '*/api/embed' => Http::response(['embeddings' => [[3.0, 4.0]]]),
        ]);

        config()->set('pilotage.dense.ollama.modele', 'nomic-embed-text');

        $client = new ClientOllama;

        // Ollama nomme ses modèles « famille:étiquette ». Une
        // configuration qui dit « nomic-embed-text » doit reconnaître
        // « nomic-embed-text:latest », faute de quoi le cas le plus
        // courant serait déclaré absent.
        $this->assertTrue($client->estDisponible());
        $this->assertSame([3.0, 4.0], $client->vecteur('une marmite'));
    }

    public function test_le_client_ollama_bascule_sur_l_ancien_point_d_entree(): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => [['name' => 'nomic-embed-text']]]),
            '*/api/embed' => Http::response('', 404),
            '*/api/embeddings' => Http::response(['embedding' => [0.0, 1.0]]),
        ]);

        config()->set('pilotage.dense.ollama.modele', 'nomic-embed-text');

        // Un Ollama d'il y a quelques versions n'expose que
        // `/api/embeddings`. Sans cette bascule, la branche dense serait
        // muette sur ces postes sans raison lisible.
        $this->assertSame([0.0, 1.0], (new ClientOllama)->vecteur('une marmite'));
    }

    // =================================================================
    //  LA NORMALISATION
    // =================================================================

    public function test_un_vecteur_norme_ramene_le_cosinus_a_un_produit_scalaire(): void
    {
        $norme = Vecteurs::normer([3.0, 4.0]);

        $this->assertEqualsWithDelta(0.6, $norme[0], 0.0001);
        $this->assertEqualsWithDelta(0.8, $norme[1], 0.0001);
        $this->assertEqualsWithDelta(1.0, Vecteurs::cosinus($norme, $norme), 0.0001);
    }

    /**
     * Un vecteur nul n'a pas de direction à conserver. Le diviser par sa
     * norme produirait des `NAN` qui se propageraient jusqu'au
     * classement sans jamais lever.
     */
    public function test_un_vecteur_nul_ne_produit_pas_de_nan(): void
    {
        $norme = Vecteurs::normer([0.0, 0.0]);

        $this->assertSame([0.0, 0.0], $norme);
        $this->assertSame(0.0, Vecteurs::cosinus($norme, [1.0, 0.0]));
    }

    public function test_deux_vecteurs_de_dimensions_differentes_ne_se_comparent_pas(): void
    {
        // Sommer sur la plus courte des deux rendrait une valeur
        // d'apparence normale pour une comparaison qui n'a pas de sens.
        $this->assertSame(0.0, Vecteurs::cosinus([1.0, 0.0], [1.0, 0.0, 0.0]));
    }

    // =================================================================
    //  HELPERS
    // =================================================================

    protected function produit(string $designation): Produit
    {
        $produit = Produit::create([
            'designation' => $designation,
            'prix_unitaire' => 10000,
            'categorie_id' => $this->categorie->id,
            'artisan_id' => $this->artisan->id,
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

    /**
     * La fiche d'un produit figure-t-elle parmi les passages trouvés ?
     *
     * L'identifiant plutôt que le titre : le corpus contient aussi une
     * fiche **artisan**, qui reprend les désignations des produits de
     * cet artisan. Chercher « Marmite » dans les titres retrouverait donc
     * la fiche de Kamdem autant que celle de la marmite, et le test
     * passerait pour la mauvaise raison.
     *
     * @param  Collection<int, SegmentTrouve>  $trouves
     */
    protected function contientLeProduit(Collection $trouves, int $produitId): bool
    {
        return $trouves->contains(
            fn (SegmentTrouve $segment): bool => $segment->type === TypeFicheLexicale::PRODUIT
                && $segment->sourceId === $produitId,
        );
    }

    protected function vectoriser(FournisseurDEmbeddings $fournisseur): void
    {
        (new ServiceIndexationDense($fournisseur))->reindexer();
    }

    /**
     * Le moteur dense sur un corpus déjà vectorisé par le même double.
     */
    protected function moteurDense(?FournisseurDEmbeddings $fournisseur = null): MoteurDense
    {
        $fournisseur ??= FournisseurDEmbeddingsDeTest::parTheme();

        $this->vectoriser($fournisseur);

        return new MoteurDense($fournisseur);
    }

    /**
     * L'hybride construit à la main plutôt que résolu par le conteneur :
     * le double doit être en place avant que le moteur ne soit bâti, et
     * le passer au constructeur le dit plus clairement qu'une liaison
     * posée au bon moment.
     */
    protected function moteurHybride(FournisseurDEmbeddings $fournisseur): MoteurHybride
    {
        return new MoteurHybride(app(MoteurLexical::class), new MoteurDense($fournisseur));
    }

    /**
     * Des passages synthétiques, dans l'ordre où un moteur les rendrait.
     *
     * @param  array<int, int>  $identifiants
     * @return Collection<int, SegmentTrouve>
     */
    protected function segments(array $identifiants): Collection
    {
        return new Collection(array_map(
            static fn (int $id): SegmentTrouve => new SegmentTrouve(
                ficheId: $id,
                type: TypeFicheLexicale::PRODUIT,
                sourceId: $id,
                titre: "Fiche {$id}",
                extrait: "Extrait de la fiche {$id}",
                similarite: 0.5,
            ),
            $identifiants,
        ));
    }
}
