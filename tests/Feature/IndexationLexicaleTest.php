<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Commerce\Models\CategorieProduit;
use Modules\Commerce\Models\Produit;
use Modules\Pilotage\Enums\TypeFicheLexicale;
use Modules\Pilotage\Indexation\CompositeurDeFiches;
use Modules\Pilotage\Indexation\Normalisateur;
use Modules\Pilotage\Models\FicheLexicale;
use Modules\Pilotage\Models\TermeLexical;
use Modules\Pilotage\Models\TermeVocabulaire;
use Modules\Pilotage\Services\ServiceIndexationLexicale;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\VillageArtisanal;
use Tests\TestCase;

/**
 * Éprouve la branche lexicale du volet analytique : composition des
 * fiches, tokenisation, pondération TF-IDF et idempotence de la
 * réindexation.
 *
 * Le corpus est fabriqué ici et non repris du registre : il faut des
 * fréquences connues à l'avance pour vérifier qu'un terme rare pèse plus
 * qu'un terme banal. Ce sont des fixtures de test, pas des données
 * d'amorçage — la règle de CLAUDE.md sur les données fictives vise les
 * seeders.
 */
class IndexationLexicaleTest extends TestCase
{
    use RefreshDatabase;

    protected VillageArtisanal $village;

    protected Boutique $boutique;

    protected CorpsMetier $vannerie;

    protected CategorieProduit $paniers;

    protected Artisan $vannier;

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

        $this->vannerie = CorpsMetier::create([
            'code' => 'VAN',
            'libelle' => 'Vannerie',
            'description' => 'Tressage de fibres végétales en paniers et corbeilles',
        ]);

        $this->vannier = Artisan::create([
            'nom' => 'Kamdem',
            'prenom' => 'Jean',
            'corps_metier_id' => $this->vannerie->id,
            'village_id' => $this->village->id,
        ]);

        $this->boutique = Boutique::create(['numero' => 'B-04', 'village_id' => $this->village->id]);

        $racine = CategorieProduit::create(['code' => 'VAN', 'libelle' => 'Vannerie']);

        $this->paniers = CategorieProduit::create([
            'code' => 'VAN-PAN',
            'libelle' => 'Paniers',
            'categorie_parent_id' => $racine->id,
        ]);
    }

    protected function deposer(string $designation, ?string $description = null, ?int $categorieId = null): Produit
    {
        return Produit::create([
            'designation' => $designation,
            'description' => $description,
            'prix_unitaire' => 12000,
            'categorie_id' => $categorieId ?? $this->paniers->id,
            'artisan_id' => $this->vannier->id,
            'boutique_id' => $this->boutique->id,
        ]);
    }

    protected function indexer(?array $types = null, bool $force = false)
    {
        return app(ServiceIndexationLexicale::class)->reindexer($types, $force);
    }

    protected function ficheDe(Produit $produit): FicheLexicale
    {
        return FicheLexicale::query()
            ->where('type', TypeFicheLexicale::PRODUIT->value)
            ->where('source_id', $produit->id)
            ->firstOrFail();
    }

    // =================================================================
    //  TOKENISATION
    // =================================================================

    public function test_les_accents_la_casse_et_la_ponctuation_sont_normalises(): void
    {
        $normalisateur = new Normalisateur;

        $this->assertSame(
            ['panier', 'tresse', 'main'],
            $normalisateur->decouper('Panier tressé à la main !'),
        );
    }

    public function test_les_mots_vides_et_les_termes_trop_courts_sont_ecartes(): void
    {
        $normalisateur = new Normalisateur;

        $this->assertSame(
            ['miel', 'ruche', 'acacia'],
            $normalisateur->decouper('le miel de la ruche d\'acacia'),
            'Les mots outils et les termes de moins de trois lettres ne discriminent rien.',
        );
    }

    public function test_le_pluriel_est_ramene_au_singulier_sans_mutiler_les_singuliers_en_s(): void
    {
        $normalisateur = new Normalisateur;

        $this->assertSame(['panier'], $normalisateur->decouper('paniers'));
        $this->assertSame(['bois'], $normalisateur->decouper('bois'), '« bois » est un singulier.');
        $this->assertSame(['prix'], $normalisateur->decouper('prix'), '« prix » aussi.');
    }

    public function test_un_nombre_nu_est_ecarte_mais_pas_une_unite_collee(): void
    {
        $normalisateur = new Normalisateur;

        $this->assertSame(
            ['miel', '50cl', 'bouteille'],
            $normalisateur->decouper('Miel 500 50cl bouteille'),
            '« 500 » se retrouve dans un prix comme dans une année ; « 50cl » porte une unité.',
        );
    }

    public function test_le_poids_multiplie_la_frequence(): void
    {
        $normalisateur = new Normalisateur;

        $this->assertSame(['panier' => 3], $normalisateur->frequences('panier', 3));
        $this->assertSame(['panier' => 6], $normalisateur->frequences('panier panier', 3));
    }

    // =================================================================
    //  COMPOSITION DES FICHES
    // =================================================================

    public function test_la_fiche_produit_reprend_les_six_champs(): void
    {
        $produit = $this->deposer('Panier tressé', 'Fibres de raphia teintes');

        $fiche = app(CompositeurDeFiches::class)->pourProduit($produit->fresh());

        $this->assertSame(TypeFicheLexicale::PRODUIT, $fiche->type);
        // `caracteristiques` porte les rubriques de la fiche technique
        // depuis le 30/08. C'est ce qui rend le stockage en base
        // preferable a une piece jointe : un .docx accroche au produit
        // serait invisible au corpus.
        $this->assertSame(
            ['designation', 'categorie', 'corps_metier', 'description', 'caracteristiques', 'artisan'],
            array_keys($fiche->champs),
        );

        // Un produit sans fiche technique compose exactement la meme
        // fiche lexicale qu'avant : le champ est nul, et `array_filter`
        // l'ecarte en aval.
        $this->assertNull($fiche->champs['caracteristiques']);
        $this->assertSame('Panier tressé', $fiche->champs['designation']);
        $this->assertSame('Paniers Vannerie', $fiche->champs['categorie'], 'La catégorie porte aussi sa parente.');
        $this->assertStringContainsString('Vannerie', (string) $fiche->champs['corps_metier']);
        $this->assertSame('Fibres de raphia teintes', $fiche->champs['description']);
        $this->assertStringContainsString('Kamdem', (string) $fiche->champs['artisan']);
    }

    public function test_la_fiche_artisan_agrege_ses_produits(): void
    {
        $this->deposer('Panier tressé');
        $this->deposer('Corbeille à pain');

        $fiche = app(CompositeurDeFiches::class)->pourArtisan($this->vannier->fresh());

        $this->assertSame(TypeFicheLexicale::ARTISAN, $fiche->type);
        $this->assertStringContainsString('Panier tressé', (string) $fiche->champs['designations_produits']);
        $this->assertStringContainsString('Corbeille à pain', (string) $fiche->champs['designations_produits']);
        $this->assertSame('Paniers', $fiche->champs['categories_produits']);
    }

    // =================================================================
    //  LE CHAMP VIDE
    // =================================================================

    public function test_un_champ_vide_ne_contribue_aucun_terme_et_ne_casse_pas_l_indexation(): void
    {
        $avecDescription = $this->deposer('Panier tressé', 'Fibres de raphia teintes');
        $sansDescription = $this->deposer('Corbeille tressée', null);

        $rapport = $this->indexer([TypeFicheLexicale::PRODUIT]);

        $this->assertSame(2, $rapport->fichesRecomposees);
        $this->assertSame(0, $rapport->fichesSansTerme);

        $fiche = $this->ficheDe($sansDescription);

        $this->assertGreaterThan(0, $fiche->nombre_termes, 'Les autres champs indexent normalement.');
        $this->assertStringNotContainsString('raphia', (string) $fiche->texte);
        $this->assertSame(
            0,
            TermeLexical::query()->where('fiche_id', $fiche->id)->where('terme', 'raphia')->count(),
        );

        // Et le champ renseigné de l'autre fiche a bien été pris.
        $this->assertSame(
            1,
            TermeLexical::query()->where('fiche_id', $this->ficheDe($avecDescription)->id)
                ->where('terme', 'raphia')->count(),
        );
    }

    public function test_une_fiche_sans_aucun_terme_est_conservee_mais_rendue_incomparable(): void
    {
        $artisanMuet = Artisan::create([
            'nom' => 'Ba',
            'prenom' => null,
            'corps_metier_id' => null,
            'village_id' => $this->village->id,
        ]);

        $rapport = $this->indexer([TypeFicheLexicale::ARTISAN]);

        $fiche = FicheLexicale::query()
            ->where('type', TypeFicheLexicale::ARTISAN->value)
            ->where('source_id', $artisanMuet->id)
            ->firstOrFail();

        $this->assertSame(0, $fiche->nombre_termes, '« Ba » fait deux lettres : aucun terme retenu.');
        $this->assertSame(0.0, $fiche->norme);
        $this->assertFalse($fiche->estComparable());
        $this->assertGreaterThanOrEqual(1, $rapport->fichesSansTerme);
    }

    public function test_un_champ_vide_ne_fausse_pas_l_idf(): void
    {
        $this->deposer('Panier tressé', 'raphia teint');
        $this->deposer('Corbeille tressée', null);
        $this->deposer('Tabouret sculpté', null);

        $this->indexer([TypeFicheLexicale::PRODUIT]);

        $raphia = TermeVocabulaire::query()->where('terme', 'raphia')->firstOrFail();

        $this->assertSame(
            1,
            $raphia->documents,
            'Les deux descriptions vides ne comptent ni comme portant le terme, ni comme documents fantômes.',
        );

        $this->assertSame(
            3,
            FicheLexicale::query()->deType(TypeFicheLexicale::PRODUIT)->count(),
            'Les trois produits sont bien au corpus : un champ vide ne retire pas la fiche.',
        );
    }

    // =================================================================
    //  PONDÉRATION TF-IDF
    // =================================================================

    public function test_un_terme_rare_pese_plus_qu_un_terme_repandu(): void
    {
        $rare = $this->deposer('Panier raphia');
        $this->deposer('Panier bambou');
        $this->deposer('Panier rotin');

        $this->indexer([TypeFicheLexicale::PRODUIT]);

        $vocabulaire = TermeVocabulaire::query()->pluck('idf', 'terme');

        $this->assertGreaterThan(
            $vocabulaire['panier'],
            $vocabulaire['raphia'],
            'Présent dans une fiche sur trois, « raphia » discrimine ; « panier », partout, non.',
        );

        $termes = TermeLexical::query()->where('fiche_id', $this->ficheDe($rare)->id)->pluck('poids', 'terme');

        $this->assertGreaterThan($termes['panier'], $termes['raphia']);
    }

    public function test_la_norme_est_la_racine_de_la_somme_des_carres_des_poids(): void
    {
        $produit = $this->deposer('Panier raphia');
        $this->deposer('Corbeille bambou');

        $this->indexer([TypeFicheLexicale::PRODUIT]);

        $fiche = $this->ficheDe($produit);

        $attendue = sqrt(
            TermeLexical::query()
                ->where('fiche_id', $fiche->id)
                ->get()
                ->sum(fn (TermeLexical $terme): float => $terme->poids ** 2),
        );

        $this->assertEqualsWithDelta($attendue, $fiche->norme, 0.000001);
    }

    public function test_la_designation_pese_plus_que_la_description(): void
    {
        // Le même terme, une fois en désignation (poids 3), une fois en
        // description (poids 1) : c'est le poids du champ qui les sépare.
        $enDesignation = $this->deposer('Raphia naturel', 'objet courant');
        $enDescription = $this->deposer('Corbeille ronde', 'raphia naturel');

        $this->indexer([TypeFicheLexicale::PRODUIT]);

        $poidsDesignation = TermeLexical::query()
            ->where('fiche_id', $this->ficheDe($enDesignation)->id)
            ->where('terme', 'raphia')->value('poids');

        $poidsDescription = TermeLexical::query()
            ->where('fiche_id', $this->ficheDe($enDescription)->id)
            ->where('terme', 'raphia')->value('poids');

        $this->assertGreaterThan((float) $poidsDescription, (float) $poidsDesignation);
    }

    // =================================================================
    //  IDEMPOTENCE ET CYCLE DE VIE
    // =================================================================

    public function test_relancer_l_indexation_ne_cree_aucun_doublon(): void
    {
        $this->deposer('Panier tressé', 'Fibres de raphia');
        $this->deposer('Corbeille à pain');

        $premier = $this->indexer();

        $fiches = FicheLexicale::query()->count();
        $termes = TermeLexical::query()->count();
        $vocabulaire = TermeVocabulaire::query()->count();

        $second = $this->indexer();

        $this->assertSame($fiches, FicheLexicale::query()->count());
        $this->assertSame($termes, TermeLexical::query()->count());
        $this->assertSame($vocabulaire, TermeVocabulaire::query()->count());

        $this->assertSame($premier->fichesLues, $second->fichesLues);
        $this->assertSame(0, $second->fichesRecomposees, 'Rien n\'a changé : rien n\'est retokenisé.');
        $this->assertSame($premier->fichesLues, $second->fichesInchangees);
    }

    public function test_l_option_force_retokenise_meme_les_fiches_inchangees(): void
    {
        $this->deposer('Panier tressé');

        $this->indexer();
        $force = $this->indexer(null, force: true);

        $this->assertSame(0, $force->fichesInchangees);
        $this->assertGreaterThan(0, $force->fichesRecomposees);
    }

    public function test_une_fiche_modifiee_est_recomposee_et_les_anciens_termes_disparaissent(): void
    {
        $produit = $this->deposer('Panier raphia');

        $this->indexer([TypeFicheLexicale::PRODUIT]);

        $produit->update(['designation' => 'Corbeille bambou']);

        $rapport = $this->indexer([TypeFicheLexicale::PRODUIT]);

        $this->assertSame(1, $rapport->fichesRecomposees);

        $termes = TermeLexical::query()->where('fiche_id', $this->ficheDe($produit)->id)->pluck('terme');

        $this->assertTrue($termes->contains('bambou'));
        $this->assertFalse($termes->contains('raphia'), 'Les termes de l\'ancienne désignation sont retirés.');
    }

    public function test_une_fiche_dont_la_source_disparait_est_retiree_du_corpus(): void
    {
        $produit = $this->deposer('Panier tressé');
        $this->deposer('Corbeille à pain');

        $this->indexer([TypeFicheLexicale::PRODUIT]);
        $this->assertSame(2, FicheLexicale::query()->deType(TypeFicheLexicale::PRODUIT)->count());

        $ficheId = $this->ficheDe($produit)->id;
        $produit->delete();

        $rapport = $this->indexer([TypeFicheLexicale::PRODUIT]);

        $this->assertSame(1, $rapport->fichesSupprimees);
        $this->assertSame(1, FicheLexicale::query()->deType(TypeFicheLexicale::PRODUIT)->count());
        $this->assertSame(
            0,
            TermeLexical::query()->where('fiche_id', $ficheId)->count(),
            'La cascade emporte les termes avec la fiche.',
        );
    }

    public function test_reindexer_un_seul_type_repondere_tout_le_corpus(): void
    {
        $this->deposer('Panier raphia');

        $this->indexer();

        $avant = TermeVocabulaire::query()->where('terme', 'raphia')->value('documents');
        $this->assertSame(2, (int) $avant, 'Le terme figure sur la fiche produit et sur celle de l\'artisan.');

        // On ajoute un produit et on ne réindexe que les produits.
        $this->deposer('Corbeille raphia');
        $this->indexer([TypeFicheLexicale::PRODUIT]);

        $this->assertSame(
            3,
            (int) TermeVocabulaire::query()->where('terme', 'raphia')->value('documents'),
            'L\'IDF est recalculé sur tout le corpus, y compris les fiches artisan non recomposées.',
        );
    }

    // =================================================================
    //  LA COMMANDE
    // =================================================================

    public function test_la_commande_indexe_et_rend_compte(): void
    {
        $this->deposer('Panier tressé', 'Fibres de raphia');

        $this->artisan('varbaf:indexer')
            ->expectsOutputToContain('Indexation lexicale')
            ->assertSuccessful();

        $this->assertSame(2, FicheLexicale::query()->count(), 'Un produit et son artisan.');
        $this->assertGreaterThan(0, TermeVocabulaire::query()->count());
    }

    public function test_la_commande_limite_le_corpus_recompose_au_type_demande(): void
    {
        $this->deposer('Panier tressé');

        $this->artisan('varbaf:indexer', ['--type' => 'produit'])->assertSuccessful();

        $this->assertSame(1, FicheLexicale::query()->count());
        $this->assertSame(1, FicheLexicale::query()->deType(TypeFicheLexicale::PRODUIT)->count());
    }

    public function test_la_commande_refuse_un_type_inconnu(): void
    {
        $this->artisan('varbaf:indexer', ['--type' => 'boutique'])->assertFailed();

        $this->assertSame(0, FicheLexicale::query()->count());
    }

    public function test_la_commande_previent_quand_le_corpus_est_vide(): void
    {
        // Le jeu de fixtures pose un artisan : sans lui, le corpus n'est
        // pas vide mais réduit à une fiche. On le retire pour éprouver
        // le cas qui se présentera vraiment — une base fraîchement semée,
        // avant toute reprise du registre.
        $this->vannier->delete();

        $this->artisan('varbaf:indexer')
            ->expectsOutputToContain('corpus est vide')
            ->assertSuccessful();

        $this->assertSame(0, FicheLexicale::query()->count());
        $this->assertSame(0, TermeVocabulaire::query()->count());
    }
}
