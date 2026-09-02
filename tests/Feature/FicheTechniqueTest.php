<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Commerce\Fiches\AnalyseurFicheTechnique;
use Modules\Commerce\Fiches\FicheAnalysee;
use Modules\Commerce\Models\Produit;
use Modules\Pilotage\Indexation\CompositeurDeFiches;
use Modules\Artisanat\Enums\NatureContenant;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Models\VillageArtisanal;
use Tests\TestCase;

/**
 * La fiche technique du produit, éprouvée sur les pièces réelles du
 * village.
 *
 * Les trois documents de `tests/Fixtures/fiches/` ne sont pas des
 * gabarits fabriqués pour le test : ce sont les fiches remises par la
 * coordination le 30/08, avec leur mise en forme d'origine, leurs
 * coquilles et leurs zones de texte Word. Un analyseur validé sur des
 * documents qu'on aurait écrits soi-même ne prouverait que la cohérence
 * de ses propres hypothèses — c'est exactement le défaut que le journal
 * du 27/08 recense sous « quelque chose qui a l'air de vérifier et qui
 * regarde ailleurs ».
 *
 * La troisième pièce est là pour la même raison : « My Soy » présente
 * une entreprise et deux produits, pas un produit. Elle doit se dégrader
 * et non casser, et c'est ce cas-là qui décide si l'écran reste
 * utilisable le jour où un artisan remet un document hors forme.
 */
class FicheTechniqueTest extends TestCase
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

    private function analyser(string $fichier): FicheAnalysee
    {
        return (new AnalyseurFicheTechnique)->analyser(
            base_path("tests/Fixtures/fiches/{$fichier}")
        );
    }

    public function test_la_fiche_du_tabouret_rend_ses_dix_rubriques_dans_l_ordre(): void
    {
        $fiche = $this->analyser('tabouret-royal.docx');

        $this->assertCount(10, $fiche->rubriques);

        // L'ordre est porteur de sens : la désignation ouvre la fiche,
        // le producteur la ferme. Le stockage en liste ordonnée n'a
        // d'intérêt que s'il est effectivement tenu.
        $this->assertSame('Désignation du produit', $fiche->rubriques[0]['rubrique']);
        $this->assertSame('Producteur / Fabricant', $fiche->rubriques[9]['rubrique']);

        $this->assertStringContainsString('Bois de colatier', $fiche->rubriques[2]['contenu']);
        $this->assertStringContainsString('Poids : 7 kg', $fiche->rubriques[3]['contenu']);
    }

    public function test_le_titre_du_document_n_est_pas_une_rubrique(): void
    {
        $fiche = $this->analyser('tabouret-royal.docx');

        // Word pose « FICHE TECHNIQUE DU PRODUIT » dans une zone de
        // texte, et le duplique dans son `mc:Fallback`. Sans le filtre,
        // le titre revient quatre fois et fabrique quatre rubriques vides.
        $this->assertSame(['FICHE TECHNIQUE DU PRODUIT', 'TABOURET ROYAL'], $fiche->titres);

        foreach ($fiche->rubriques as $rubrique) {
            $this->assertNotSame('', trim($rubrique['contenu']));
        }
    }

    public function test_la_designation_est_reprise_du_couple_nom(): void
    {
        $this->assertSame('Tabouret Royal', $this->analyser('tabouret-royal.docx')->designation);
        $this->assertSame('Huile d’amande de palmiste', $this->analyser('huile-palmiste.docx')->designation);
    }

    /**
     * Le point qui a décidé de toute la conception.
     *
     * La fiche de l'huile porte trois prix — 1 L à 6 000, 0,5 L à 3 000,
     * 0,25 L à 1 500 — parce que le produit est une famille de
     * conditionnements, que le modèle ne porte pas (DT-13). Un
     * `prix_unitaire` pré-rempli à 6 000 serait faux deux fois sur trois,
     * et faux en silence : l'agent verrait un champ rempli et n'aurait
     * aucune raison de le rouvrir.
     */
    public function test_les_montants_sont_signales_et_jamais_reportes(): void
    {
        $fiche = $this->analyser('huile-palmiste.docx');

        $this->assertSame(['6 000', '3 000', '1 500'], $fiche->montants);
        $this->assertContains(FicheAnalysee::PLUSIEURS_MONTANTS, $fiche->signalements);

        // L'analyseur n'expose aucun prix : il n'y a rien à reporter,
        // pas même par erreur d'appelant.
        $this->assertFalse(property_exists($fiche, 'prix'));
    }

    /**
     * La catégorie sort en texte, jamais rapprochée d'un référentiel.
     *
     * « Huile végétale » est le mot de l'artisan, pas un libellé de la
     * nomenclature du village. Le rapprochement par nom est le problème
     * qui a coûté le plus cher à la reprise du registre — 70 % puis 77 %
     * de couverture, chaque arbitrage consigné avec son motif. L'écran
     * affiche ce texte à côté du `Select` ; c'est l'agent qui choisit.
     */
    public function test_la_categorie_est_rendue_en_texte_sans_rapprochement(): void
    {
        $fiche = $this->analyser('huile-palmiste.docx');

        $this->assertSame('Huile végétale', $fiche->categorieTexte);
        $this->assertNull($this->analyser('tabouret-royal.docx')->categorieTexte);
    }

    public function test_l_image_principale_est_extraite_de_l_archive(): void
    {
        $fiche = $this->analyser('huile-palmiste.docx');

        $this->assertNotNull($fiche->image);
        $this->assertSame('jpeg', $fiche->extensionImage);

        // Une image réelle, pas un fragment : les fiches du village
        // portent des photographies de 27 à 125 Ko.
        $this->assertGreaterThan(10_000, strlen($fiche->image));
        $this->assertNotContains(FicheAnalysee::IMAGE_ABSENTE, $fiche->signalements);
    }

    /**
     * Le cas qui décide de la robustesse de l'écran.
     *
     * « My Soy » présente une entreprise, deux produits et un procédé,
     * en chiffres romains et en prose. Elle ne nomme aucun produit
     * unique. L'attendu n'est pas qu'elle soit comprise — elle ne peut
     * pas l'être — mais qu'elle rende ce qu'elle porte, le dise, et
     * laisse le formulaire utilisable.
     */
    public function test_une_fiche_hors_forme_se_degrade_sans_casser(): void
    {
        $fiche = $this->analyser('my-soy.docx');

        $this->assertNotEmpty($fiche->rubriques);
        $this->assertNull($fiche->designation);
        $this->assertContains(FicheAnalysee::DESIGNATION_ABSENTE, $fiche->signalements);

        // Le signalement doit être lisible par l'agent, pas seulement
        // par le test : un code d'anomalie affiché tel quel à l'écran
        // demande une traduction que personne n'a.
        $this->assertNotEmpty($fiche->messages());
        $this->assertStringContainsString('désignation', implode(' ', $fiche->messages()));
    }

    public function test_un_fichier_illisible_ne_leve_pas(): void
    {
        $chemin = tempnam(sys_get_temp_dir(), 'fiche').'.docx';
        file_put_contents($chemin, 'ceci n’est pas une archive');

        $fiche = (new AnalyseurFicheTechnique)->analyser($chemin);

        $this->assertFalse($fiche->estExploitable());
        $this->assertContains(FicheAnalysee::STRUCTURE_NON_RECONNUE, $fiche->signalements);

        unlink($chemin);
    }

    /**
     * L'aller-retour en base conserve l'ordre des rubriques — et lui seul.
     *
     * **PostgreSQL normalise les clés d'un objet `jsonb`** : il les
     * réordonne à l'écriture, par longueur puis par octets, et ne
     * restitue jamais l'ordre de saisie. `{rubrique, contenu}` revient
     * `{contenu, rubrique}`. L'ordre des *éléments d'un tableau*, lui,
     * est conservé — et c'est celui-là qui porte du sens dans une fiche
     * technique, où l'identification ouvre et le producteur ferme.
     *
     * Le test énonce les deux séparément plutôt que de comparer les
     * tableaux en bloc. Une assertion qui tombe sur un détail que la
     * base a le droit de changer signale une régression là où il n'y en
     * a pas — et use la confiance qu'on accorde à la suite.
     */
    public function test_les_rubriques_survivent_a_l_aller_retour_en_base(): void
    {
        $produit = $this->produit();
        $rubriques = $this->analyser('tabouret-royal.docx')->pourStockage();

        $produit->update(['caracteristiques' => $rubriques]);

        $relu = Produit::query()->find($produit->getKey());

        $this->assertSame(
            array_column($rubriques, 'rubrique'),
            array_column($relu->caracteristiques, 'rubrique'),
            "L'ordre des rubriques porte du sens : il doit survivre au stockage.",
        );

        $this->assertSame('Entretien', $relu->caracteristiques[6]['rubrique']);
        $this->assertSame($rubriques[6]['contenu'], $relu->caracteristiques[6]['contenu']);

        // Les clés d'un objet reviennent dans l'ordre de PostgreSQL. Le
        // code les lit par nom — jamais par position — et cette
        // assertion retient qu'il doit continuer de le faire.
        $this->assertEqualsCanonicalizing(
            ['rubrique', 'contenu'],
            array_keys($relu->caracteristiques[0]),
        );
    }

    /**
     * Le gain qui justifie le stockage en base plutôt qu'en pièce jointe.
     *
     * Un `.docx` accroché au produit est un binaire : ni l'assistant ni
     * la recommandation par similarité ne peuvent le lire. Les rubriques
     * en texte entrent dans le corpus lexical, et les deux en profitent
     * sans qu'une ligne du Pilotage change ailleurs.
     */
    public function test_le_corpus_lexical_recoit_les_rubriques(): void
    {
        $produit = $this->produit();
        $produit->update(['caracteristiques' => $this->analyser('tabouret-royal.docx')->pourStockage()]);

        $fiche = app(CompositeurDeFiches::class)->pourProduit($produit->fresh());
        $texte = implode(' ', array_filter($fiche->champs));

        $this->assertStringContainsString('colatier', $texte);
        $this->assertStringContainsString('cauris', mb_strtolower($texte));
    }

    /**
     * Un produit posé sur un parc minimal mais complet.
     *
     * Le village n'est pas un décor : `artisans.village_id` et
     * `boutiques.village_id` sont obligatoires, parce que le Socle est
     * le module 1 et que rien du parc n'existe hors d'un village.
     */
    private function produit(): Produit
    {
        $metier = CorpsMetier::create(['code' => 'SCU', 'libelle' => 'Sculpture']);

        $artisan = Artisan::create([
            'nom' => 'TEDJANI',
            'prenom' => 'Mama',
            'corps_metier_id' => $metier->getKey(),
            'village_id' => $this->village->getKey(),
            'actif' => true,
        ]);

        $boutique = Boutique::create([
            'numero' => 'B01',
            'village_id' => $this->village->getKey(),
            'nature' => NatureContenant::BOUTIQUE,
        ]);

        return Produit::create([
            'designation' => 'Tabouret Royal',
            'prix_unitaire' => 75_000,
            'artisan_id' => $artisan->getKey(),
            'boutique_id' => $boutique->getKey(),
        ]);
    }
}
