<?php

namespace App\Import;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Artisanat\Enums\StatutAttribution;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\AttributionEspace;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\EspaceLocatif;
use Modules\Commerce\Enums\ModeReglement;
use Modules\Commerce\Models\Depot;
use Modules\Commerce\Models\LigneDepot;
use Modules\Commerce\Models\Produit;
use Modules\Commerce\Models\TauxCommission;
use Modules\Commerce\Services\ServiceValidationProduit;
use Modules\Commerce\Services\ServiceVente;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\VillageArtisanal;
use Throwable;

/**
 * Reprise du registre de ventes transcrit du village.
 *
 * **Le service n'écrit rien lui-même.** Ni dans `mouvements_stock`, ni
 * dans `ventes`, ni dans `mouvements_caisse` : le dépôt passe par
 * `Depot::valider()`, la vente par `ServiceVente`, la validation d'un
 * produit par `ServiceValidationProduit`. Ce n'est pas une coquetterie
 * d'architecture. Un import qui écrirait en direct contournerait
 * exactement les trois règles que le système existe pour tenir —
 * journal unique de stock, journal unique de caisse, figement des
 * valeurs de vente — et il le ferait sur les seules données réelles du
 * projet. Le seul chemin qu'il emprunte est celui qu'un agent
 * emprunterait au comptoir ; il est simplement mille fois plus rapide.
 *
 * **Deux temps.** Le registre est lu en entier avant que rien ne
 * s'écrive : le prix d'un produit est celui de sa première occurrence,
 * la date d'entrée d'un artisan dans un local celle de sa plus ancienne
 * vente, et le rapprochement des noms suppose de les connaître tous.
 * Décider au fil de l'eau obligerait à réécrire des enregistrements
 * déjà posés, et un prix réécrit n'est plus un prix figé.
 *
 * **Une ligne, un dépôt, une vente.** Le registre ne dit pas quand les
 * biens ont été confiés au village, seulement quand ils ont été vendus.
 * Créer un dépôt de la quantité exacte, à la date de la vente, est la
 * seule reconstitution qui laisse le journal de stock exact : le solde
 * de chaque produit revient à zéro après sa vente, sans jamais passer
 * par un négatif que `ServiceMouvementStock` refuserait de toute façon.
 * C'est une reconstitution, et le rapport ne prétend pas autre chose.
 *
 * **Limite connue et assumée.** Les mouvements de stock et les écritures
 * de caisse portent la date de l'import, non celle de la vente : ce sont
 * les points d'entrée uniques qui horodatent, et les détourner pour la
 * reprise reviendrait à leur ôter la garantie qui fait leur intérêt. La
 * vente, elle, porte bien sa date d'origine — c'est elle qui fait foi
 * pour les états et les campagnes de reversement. Une journée de caisse
 * arrêtée reste par ailleurs verrouillée en date (RG-27), ce qui ferait
 * échouer toute tentative d'antidater.
 *
 * Toutes les ventes reprises sont par ailleurs rattachées à l'exercice
 * **en cours**, y compris celles de 2023 et 2024 : le village n'a jamais
 * ouvert d'exercice pour ces années-là dans le système, et en fabriquer
 * un rétroactivement — qu'il faudrait ensuite clôturer sans jamais avoir
 * arrêté ses caisses — reviendrait à inventer un acte de gestion. La
 * date de la vente reste la sienne ; c'est elle que les états
 * interrogent.
 */
class ServiceImportRegistre
{
    /**
     * Nom sous lequel sont regroupées les ventes dont le registre ne
     * nomme pas l'artisan.
     *
     * Un artisan unique et explicite, et non un rattachement au hasard :
     * trois cent quinze lignes sans nom, c'est un quart du registre, et
     * les répartir « au mieux » entre les artisans connus fabriquerait
     * des parts à reverser qui n'ont jamais existé.
     */
    public const ARTISAN_NON_IDENTIFIE = 'Non identifié';

    /**
     * Numéro de la boutique qui accueille les emplacements hors parc.
     *
     * Le registre nomme des points de vente qui ne sont pas des
     * boutiques — le hall, une vitrine, une galerie, une salle
     * d'innovation — et des codes en B19, B23 ou B52 qui débordent des
     * dix-sept locaux relevés. Les uns comme les autres ont bien produit
     * des ventes : les écarter ferait disparaître ces recettes.
     *
     * Ils sont donc rattachés à une boutique technique, distincte du
     * parc. Créer B19 à B52 pour les accueillir gonflerait le parc de
     * dix-sept à cinquante-deux locaux et fausserait tout taux
     * d'occupation ; les jeter perdrait de l'argent encaissé. La
     * boutique technique est le seul endroit où ces ventes existent sans
     * mentir sur le parc, et le rapport les liste une à une.
     */
    public const BOUTIQUE_TECHNIQUE = 'HORS-PARC';

    /**
     * Nombre d'échecs consécutifs au-delà duquel l'import renonce.
     */
    protected const TENTATIVES_AVANT_ABANDON = 20;

    protected VillageArtisanal $village;

    protected Exercice $exercice;

    protected string $fichierCourant = '';

    protected ?Boutique $boutiqueTechnique = null;

    /**
     * Numéros du parc, relus une seule fois par import.
     *
     * Propriété d'instance et non variable statique : deux imports dans
     * le même processus — c'est le cas des tests — doivent voir chacun
     * le parc de leur propre base.
     *
     * @var array<int, string>|null
     */
    protected ?array $numerosDuParc = null;

    /** @var array<string, Artisan> */
    protected array $artisans = [];

    /** @var array<string, array{boutique: Boutique, libelle: ?string}> */
    protected array $emplacements = [];

    /** @var array<string, EspaceLocatif> */
    protected array $espaces = [];

    /** @var array<string, true> */
    protected array $attributions = [];

    /** @var array<string, Produit> */
    protected array $produits = [];

    public function __construct(
        protected LecteurRegistre $lecteur,
        protected ServiceVente $ventes,
        protected ServiceValidationProduit $validation,
    ) {}

    /**
     * @param  callable(int, int): void|null  $progression
     */
    public function importer(
        string $chemin,
        float $seuil = 85.0,
        float $marge = 10.0,
        ?callable $progression = null,
    ): RapportImport {
        $this->fichierCourant = basename($chemin);

        $lignes = $this->lecteur->lire($chemin);
        $rapport = new RapportImport($this->fichierCourant, $seuil, $marge);

        $this->preparerLeContexte($lignes);

        $rapprochement = (new RapprochementArtisans($seuil, $marge))->regrouper(
            array_values(array_filter(array_map(fn (LigneRegistre $ligne) => $ligne->nomArtisan, $lignes)))
        );

        $this->compterLesRapprochements($rapport, $rapprochement, $lignes);

        $profils = $this->profilerLesProduits($lignes, $rapprochement);
        $entrees = $this->daterLesOccupations($lignes, $rapprochement);
        $deja = $this->empreintesDejaReprises($this->fichierCourant);

        $echecsConsecutifs = 0;
        $dernierEchec = '';
        $total = count($lignes);
        $rang = 0;

        foreach ($lignes as $ligne) {
            $rang++;
            $rapport->incrementer(RapportImport::LIGNES_TRAITEES);
            $this->compterLaQualiteDeLaLigne($rapport, $ligne);
            $this->signalerLeDouteDeRapprochement($ligne, $rapprochement);

            if (isset($deja[$ligne->empreinte])) {
                $rapport->incrementer(RapportImport::LIGNES_DEJA_REPRISES);
                $this->cloturerLaLigne($rapport, $ligne, 'Déjà reprise');
                $this->avancer($progression, $rang, $total);

                continue;
            }

            if (! $ligne->estVendable()) {
                $this->tracer($ligne, TraceLigneImportee::STATUT_NON_IMPORTEE);
                $rapport->incrementer(RapportImport::LIGNES_NON_IMPORTEES);
                $this->cloturerLaLigne($rapport, $ligne, 'Non importée');
                $this->avancer($progression, $rang, $total);

                continue;
            }

            try {
                $this->reprendreLaLigne($ligne, $rapport, $rapprochement, $profils, $entrees);

                $rapport->incrementer(RapportImport::LIGNES_IMPORTEES);
                $this->cloturerLaLigne($rapport, $ligne, 'Importée');
                $echecsConsecutifs = 0;
            } catch (Throwable $erreur) {
                $ligne->signaler('Rejet technique : '.$erreur->getMessage());
                $this->tracer($ligne, TraceLigneImportee::STATUT_NON_IMPORTEE);
                $rapport->incrementer(RapportImport::LIGNES_NON_IMPORTEES);
                $this->cloturerLaLigne($rapport, $ligne, 'Non importée');

                $echecsConsecutifs++;
                $dernierEchec = $erreur->getMessage();

                if ($echecsConsecutifs >= self::TENTATIVES_AVANT_ABANDON) {
                    throw ImportImpossibleException::environnementInexploitable(
                        self::TENTATIVES_AVANT_ABANDON,
                        $dernierEchec,
                    );
                }
            }

            $this->avancer($progression, $rang, $total);
        }

        return $rapport;
    }

    // =================================================================
    //  Contexte et contrôles préalables
    // =================================================================

    /**
     * @param  array<int, LigneRegistre>  $lignes
     */
    protected function preparerLeContexte(array $lignes): void
    {
        $utilisateur = Auth::user();

        if (! $utilisateur || ! $utilisateur->agent) {
            throw ImportImpossibleException::sansCompte((string) ($utilisateur?->email ?? 'anonyme'));
        }

        $exercice = Exercice::courant();

        if (! $exercice) {
            throw ImportImpossibleException::sansExercice();
        }

        $this->exercice = $exercice;

        $village = $exercice->village ?? VillageArtisanal::query()->first();

        if (! $village) {
            throw ImportImpossibleException::sansVillage();
        }

        $this->village = $village;

        // Le taux applicable est celui en vigueur à la date de la vente
        // (règle 10), et la plus ancienne pièce du registre remonte à
        // 2023. Si aucun acte ne couvre cette date, toutes les lignes
        // échoueraient l'une après l'autre : autant le dire d'emblée.
        $plusAncienne = $this->plusAncienneDate($lignes);

        if ($plusAncienne && ! TauxCommission::existeUnTauxEnVigueur($plusAncienne)) {
            throw ImportImpossibleException::sansTaux($plusAncienne->format('d/m/Y'));
        }
    }

    /**
     * @param  array<int, LigneRegistre>  $lignes
     */
    protected function plusAncienneDate(array $lignes): ?Carbon
    {
        $plusAncienne = null;

        foreach ($lignes as $ligne) {
            if ($ligne->date && (! $plusAncienne || $ligne->date->lessThan($plusAncienne))) {
                $plusAncienne = $ligne->date->copy();
            }
        }

        return $plusAncienne;
    }

    /**
     * @return array<string, true>
     */
    protected function empreintesDejaReprises(string $fichier): array
    {
        return TraceLigneImportee::query()
            ->where('fichier', $fichier)
            ->pluck('empreinte')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    // =================================================================
    //  Indicateurs calculés sur le fichier, indépendamment des écritures
    // =================================================================

    /**
     * @param  array<int, LigneRegistre>  $lignes
     */
    protected function compterLesRapprochements(
        RapportImport $rapport,
        ResultatRapprochement $rapprochement,
        array $lignes,
    ): void {
        $rapport->fixer(RapportImport::ARTISANS_ECRITURES, $rapprochement->nombreEcritures());
        $rapport->fixer(RapportImport::ARTISANS_REGROUPES, $rapprochement->nombreRegroupees());
        $rapport->fixer(RapportImport::ARTISANS_DISTINCTS, $rapprochement->nombreDistincts());
        $rapport->fixerDoutes($rapprochement->doutes());

        // Le regroupement des boutiques se compte sur le fichier et non
        // sur les écritures : relancé sur une base déjà reprise, l'import
        // doit rendre le même chiffre. « N° 2 », « B02 » et « B-02 »
        // désignent un seul local, et c'est cela que la coordination
        // veut lire.
        $ecritures = [];
        $retenus = [];

        foreach ($lignes as $ligne) {
            // Une cellule qui ne porte qu'un guillemet de répétition
            // n'est pas une écriture de plus : elle renvoie à celle du
            // dessus, déjà comptée.
            $ecriture = Normalisation::comparable($ligne->codeBoutiqueSource);

            if ($ecriture !== '') {
                $ecritures[$ecriture] = true;
            }

            $retenus[$this->emplacementRetenu($ligne->codeBoutique)] = true;
        }

        $rapport->fixer(RapportImport::BOUTIQUES_ECRITURES, count($ecritures));
        $rapport->fixer(RapportImport::BOUTIQUES_RETENUES, count($retenus));
        $rapport->fixer(
            RapportImport::BOUTIQUES_REGROUPEES,
            max(0, count($ecritures) - count($retenus)),
        );
    }

    protected function compterLaQualiteDeLaLigne(RapportImport $rapport, LigneRegistre $ligne): void
    {
        if ($ligne->ecartSignaleALaSource) {
            $rapport->incrementer(RapportImport::ECARTS_A_LA_SOURCE);
        }

        if ($ligne->enEcartDeCalcul()) {
            $rapport->incrementer(RapportImport::ECARTS_DE_CALCUL);
        }

        if ($ligne->nomArtisan === null) {
            $rapport->incrementer(RapportImport::LIGNES_SANS_ARTISAN);
        }

        foreach ([LigneRegistre::DATE_REPRISE, LigneRegistre::DATE_ANNEE_DEDUITE,
            LigneRegistre::DATE_INVALIDE, LigneRegistre::DATE_INVRAISEMBLABLE,
            LigneRegistre::DATE_INDETERMINABLE] as $anomalie) {
            if ($ligne->porte($anomalie)) {
                $rapport->incrementer(RapportImport::LIGNES_SANS_DATE_PROPRE);

                break;
            }
        }

        foreach ([LigneRegistre::QUANTITE_DEDUITE, LigneRegistre::PRIX_DEDUIT,
            LigneRegistre::MONTANT_DEDUIT] as $anomalie) {
            if ($ligne->porte($anomalie)) {
                $rapport->incrementer(RapportImport::LIGNES_VALEURS_DEDUITES);

                break;
            }
        }
    }

    protected function signalerLeDouteDeRapprochement(LigneRegistre $ligne, ResultatRapprochement $rapprochement): void
    {
        $doute = $rapprochement->doutePour($ligne->nomArtisan);

        if ($doute === null) {
            return;
        }

        $ligne->signaler(sprintf(
            '%s : « %s » à %s %%',
            LigneRegistre::ARTISAN_RAPPROCHEMENT_ECARTE,
            $doute['candidat'],
            number_format($doute['score'], 1, ',', ' '),
        ));
    }

    protected function cloturerLaLigne(RapportImport $rapport, LigneRegistre $ligne, string $statut): void
    {
        if (! $ligne->estSignalee()) {
            return;
        }

        $rapport->incrementer(RapportImport::LIGNES_SIGNALEES);
        $rapport->ajouterSignalement($ligne, $statut);
    }

    // =================================================================
    //  Pré-calculs du premier temps
    // =================================================================

    /**
     * Le prix d'un produit est celui de sa **première** occurrence.
     *
     * Le registre vend le même miel à 2 500 puis à 3 000 F : c'est une
     * hausse, pas une erreur, et le prix courant du catalogue doit être
     * l'un des deux. Retenir le premier plutôt que le dernier ou la
     * moyenne est arbitraire mais explicite ; surtout, la vente porte de
     * toute façon le prix réellement pratiqué ce jour-là (RG-10), si
     * bien que le choix n'affecte ni les recettes ni les reversements.
     *
     * @param  array<int, LigneRegistre>  $lignes
     * @return array<string, array{designation: string, conditionnement: string, prix: int}>
     */
    protected function profilerLesProduits(array $lignes, ResultatRapprochement $rapprochement): array
    {
        $profils = [];

        foreach ($lignes as $ligne) {
            if (! $ligne->estVendable()) {
                continue;
            }

            $cle = $this->cleProduit($ligne, $rapprochement);

            if (isset($profils[$cle])) {
                continue;
            }

            $profils[$cle] = [
                'designation' => $ligne->designation,
                'conditionnement' => $ligne->conditionnement,
                'prix' => (int) $ligne->prixUnitaire,
            ];
        }

        return $profils;
    }

    /**
     * Date d'entrée d'un artisan dans un emplacement : sa plus ancienne
     * vente qui y a été faite.
     *
     * C'est la seule chose que le registre établisse. Il ne dit pas
     * quand le contrat a été signé — il dit à partir de quand
     * l'occupation est attestée, ce qui est plus modeste et plus sûr.
     *
     * @param  array<int, LigneRegistre>  $lignes
     * @return array<string, Carbon>
     */
    protected function daterLesOccupations(array $lignes, ResultatRapprochement $rapprochement): array
    {
        $entrees = [];

        foreach ($lignes as $ligne) {
            if ($ligne->date === null) {
                continue;
            }

            $cle = $this->cleOccupation($ligne, $rapprochement);

            if (! isset($entrees[$cle]) || $ligne->date->lessThan($entrees[$cle])) {
                $entrees[$cle] = $ligne->date->copy();
            }
        }

        return $entrees;
    }

    protected function nomRetenu(LigneRegistre $ligne, ResultatRapprochement $rapprochement): string
    {
        return $rapprochement->canonique($ligne->nomArtisan) ?? self::ARTISAN_NON_IDENTIFIE;
    }

    /**
     * Emplacement retenu pour un code du registre, sous forme d'un
     * libellé stable.
     *
     * Deux issues seulement : un numéro du parc, ou un emplacement hors
     * parc préfixé. Le préfixe évite qu'un jour un local « HALL » du
     * parc ne se confonde avec l'emplacement technique du même nom.
     */
    protected function emplacementRetenu(string $codeBoutique): string
    {
        $code = $codeBoutique === '' ? 'SANS CODE' : $codeBoutique;
        $numero = $this->numeroDuParc($code);

        return $numero ?? self::BOUTIQUE_TECHNIQUE.' : '.$code;
    }

    /**
     * Le code désigne-t-il un local du parc, et sous quel numéro ?
     *
     * « N° 2 », « B2 », « B-02 » et « B02 » désignent le même local :
     * c'est ici que le regroupement des écritures de boutiques a lieu.
     */
    protected function numeroDuParc(string $code): ?string
    {
        if (preg_match('/^B\s*-?\s*(\d{1,2})$/', $code, $trouve) !== 1) {
            return null;
        }

        $numero = 'B'.str_pad($trouve[1], 2, '0', STR_PAD_LEFT);

        return in_array($numero, $this->numerosDuParc(), strict: true) ? $numero : null;
    }

    /**
     * @return array<int, string>
     */
    protected function numerosDuParc(): array
    {
        return $this->numerosDuParc ??= Boutique::query()
            ->where('village_id', $this->village->getKey())
            ->where('numero', '!=', self::BOUTIQUE_TECHNIQUE)
            ->pluck('numero')
            ->all();
    }

    protected function cleOccupation(LigneRegistre $ligne, ResultatRapprochement $rapprochement): string
    {
        return $this->emplacementRetenu($ligne->codeBoutique).'|'.$this->nomRetenu($ligne, $rapprochement);
    }

    protected function cleProduit(LigneRegistre $ligne, ResultatRapprochement $rapprochement): string
    {
        return implode('|', [
            $this->cleOccupation($ligne, $rapprochement),
            Normalisation::comparable($ligne->designation),
            Normalisation::comparable($ligne->conditionnement),
        ]);
    }

    // =================================================================
    //  Second temps : les écritures
    // =================================================================

    /**
     * @param  array<string, array{designation: string, conditionnement: string, prix: int}>  $profils
     * @param  array<string, Carbon>  $entrees
     */
    protected function reprendreLaLigne(
        LigneRegistre $ligne,
        RapportImport $rapport,
        ResultatRapprochement $rapprochement,
        array $profils,
        array $entrees,
    ): void {
        $artisan = $this->resoudreArtisan($this->nomRetenu($ligne, $rapprochement), $rapport);
        [$boutique, $libelleEspace] = $this->resoudreBoutique($ligne->codeBoutique);

        $espace = $this->resoudreEspace($boutique, $artisan, $libelleEspace, $rapport);

        $cleOccupation = $this->cleOccupation($ligne, $rapprochement);
        $this->resoudreAttribution(
            $artisan,
            $espace,
            $entrees[$cleOccupation] ?? $ligne->date ?? Carbon::now(),
            $rapport,
        );

        $profil = $profils[$this->cleProduit($ligne, $rapprochement)] ?? [
            'designation' => $ligne->designation,
            'conditionnement' => $ligne->conditionnement,
            'prix' => (int) $ligne->prixUnitaire,
        ];

        $produit = $this->resoudreProduit($boutique, $artisan, $profil, $rapport);

        // Le dépôt, la vente et la trace tiennent dans une transaction :
        // un dépôt validé sans la vente qui l'accompagne laisserait du
        // stock fantôme, et une vente sans trace serait recréée au
        // prochain passage.
        DB::transaction(function () use ($ligne, $artisan, $boutique, $espace, $produit): void {
            $this->deposer($ligne, $artisan, $boutique, $produit);

            $vente = $this->ventes->enregistrer(
                lignes: [[
                    'produit_id' => $produit->getKey(),
                    'quantite' => (int) $ligne->quantite,
                    // Le prix réellement pratiqué ce jour-là, et non le
                    // prix courant du catalogue : c'est le figement de
                    // RG-10, et c'est ce qui permet d'importer les
                    // lignes en écart telles quelles.
                    'prix_unitaire' => (int) $ligne->prixUnitaire,
                ]],
                modeReglement: ModeReglement::ESPECES,
                client: [],
                dateVente: $ligne->date,
            );

            $this->tracer($ligne, TraceLigneImportee::STATUT_IMPORTEE, [
                'vente_id' => $vente->getKey(),
                'produit_id' => $produit->getKey(),
                'artisan_id' => $artisan->getKey(),
                'espace_locatif_id' => $espace->getKey(),
            ]);
        });

        // Les compteurs sont incrémentés une fois la transaction
        // confirmée, et non depuis son intérieur : une transaction
        // annulée laisserait sinon le rapport annoncer des dépôts et des
        // ventes que la base ne porte pas.
        $rapport->incrementer(RapportImport::DEPOTS_CREES);
        $rapport->incrementer(RapportImport::VENTES_CREEES);
    }

    protected function deposer(
        LigneRegistre $ligne,
        Artisan $artisan,
        Boutique $boutique,
        Produit $produit,
    ): void {
        $depot = Depot::create([
            'date_depot' => $ligne->date->toDateString(),
            'observations' => "Reprise du registre transcrit, ligne {$ligne->numero}.",
            'artisan_id' => $artisan->getKey(),
            'boutique_id' => $boutique->getKey(),
            'exercice_id' => $this->exercice->getKey(),
        ]);

        // La référence et la désignation sont recopiées du produit par
        // le modèle : on ne les passe pas.
        LigneDepot::create([
            'depot_id' => $depot->getKey(),
            'produit_id' => $produit->getKey(),
            'quantite' => (int) $ligne->quantite,
        ]);

        $depot->valider();
    }

    protected function resoudreArtisan(string $nom, RapportImport $rapport): Artisan
    {
        if (isset($this->artisans[$nom])) {
            return $this->artisans[$nom];
        }

        $artisan = Artisan::firstOrCreate(
            ['village_id' => $this->village->getKey(), 'nom' => $nom],
            [
                'actif' => true,
                // Le registre ne porte pas le secteur d'activité, et
                // l'inventer polluerait un référentiel dont le seeder
                // fait autorité. Voir la migration
                // « rendre_facultatives_les_donnees_absentes_du_registre ».
                'corps_metier_id' => null,
                'autorisation_publication' => false,
            ],
        );

        if ($artisan->wasRecentlyCreated) {
            $rapport->incrementer(RapportImport::ARTISANS_CREES);

            if ($artisan->corps_metier_id === null) {
                $rapport->incrementer(RapportImport::ARTISANS_SANS_SECTEUR);
            }
        }

        return $this->artisans[$nom] = $artisan;
    }

    /**
     * @return array{0: Boutique, 1: ?string} Boutique, et libellé à donner
     *                                        à l'espace quand il est hors parc
     */
    protected function resoudreBoutique(string $codeBoutique): array
    {
        $code = $codeBoutique === '' ? 'SANS CODE' : $codeBoutique;

        if (isset($this->emplacements[$code])) {
            return [$this->emplacements[$code]['boutique'], $this->emplacements[$code]['libelle']];
        }

        $numero = $this->numeroDuParc($code);

        if ($numero !== null) {
            $boutique = Boutique::query()
                ->where('village_id', $this->village->getKey())
                ->where('numero', $numero)
                ->firstOrFail();

            $this->emplacements[$code] = ['boutique' => $boutique, 'libelle' => null];

            return [$boutique, null];
        }

        $boutique = $this->boutiqueTechnique();
        $libelle = mb_substr($code, 0, 120);

        $this->emplacements[$code] = ['boutique' => $boutique, 'libelle' => $libelle];

        return [$boutique, $libelle];
    }

    protected function boutiqueTechnique(): Boutique
    {
        return $this->boutiqueTechnique ??= Boutique::firstOrCreate(
            ['village_id' => $this->village->getKey(), 'numero' => self::BOUTIQUE_TECHNIQUE],
            ['superficie' => null, 'emplacement' => null],
        );
    }

    /**
     * Un espace locatif par couple artisan / emplacement.
     *
     * C'est la maille réelle du village : plusieurs artisans se
     * partagent le même local, chacun sur son espace, et c'est
     * précisément ce que la correction structurelle de l'attribution a
     * rendu représentable. Un seul espace par boutique ferait échouer la
     * deuxième attribution sur un chevauchement — c'est-à-dire refuser
     * la situation normale du parc.
     *
     * L'espace déjà semé pour chaque boutique est réutilisé par le
     * premier artisan rencontré, plutôt que doublé : la coordination
     * n'a pas à voir apparaître un B0601 vide à côté d'un B0602 occupé.
     */
    protected function resoudreEspace(
        Boutique $boutique,
        Artisan $artisan,
        ?string $libelle,
        RapportImport $rapport,
    ): EspaceLocatif {
        $cle = $boutique->getKey().'|'.$artisan->getKey().'|'.($libelle ?? '');

        if (isset($this->espaces[$cle])) {
            return $this->espaces[$cle];
        }

        $requete = fn () => EspaceLocatif::query()
            ->where('boutique_id', $boutique->getKey())
            ->when(
                $libelle === null,
                fn ($sousRequete) => $sousRequete->whereNull('libelle'),
                fn ($sousRequete) => $sousRequete->where('libelle', $libelle),
            );

        // Déjà attribué à cet artisan : c'est le cas d'une relance.
        $espace = $requete()
            ->whereHas('attributions', fn ($sousRequete) => $sousRequete
                ->where('artisan_id', $artisan->getKey())
                ->where('statut', StatutAttribution::ACTIVE->value))
            ->orderBy('code')
            ->first();

        // Sinon, un espace encore libre du même emplacement.
        $espace ??= $requete()
            ->whereDoesntHave('attributions', fn ($sousRequete) => $sousRequete
                ->where('statut', StatutAttribution::ACTIVE->value))
            ->orderBy('code')
            ->first();

        if (! $espace) {
            $espace = EspaceLocatif::create([
                'boutique_id' => $boutique->getKey(),
                'libelle' => $libelle,
            ]);

            $rapport->incrementer(RapportImport::ESPACES_CREES);

            if ($libelle !== null) {
                $rapport->incrementer(RapportImport::ESPACES_HORS_PARC);
                $rapport->signalerEspaceHorsParc($libelle, (string) $espace->code);
            }
        }

        return $this->espaces[$cle] = $espace;
    }

    protected function resoudreAttribution(
        Artisan $artisan,
        EspaceLocatif $espace,
        Carbon $dateDebut,
        RapportImport $rapport,
    ): void {
        $cle = $artisan->getKey().'|'.$espace->getKey();

        if (isset($this->attributions[$cle])) {
            return;
        }

        $existante = AttributionEspace::query()
            ->where('artisan_id', $artisan->getKey())
            ->where('espace_locatif_id', $espace->getKey())
            ->where('exercice_id', $this->exercice->getKey())
            ->exists();

        if (! $existante) {
            AttributionEspace::create([
                // Occupation attestée, non contractuelle : la date est
                // celle de la plus ancienne vente relevée.
                'date_debut' => $dateDebut->toDateString(),
                'date_fin' => null,
                // Le registre ne porte pas la redevance négociée, et un
                // montant inventé serait figé puis facturé.
                'redevance_convenue' => null,
                'dossier_complet' => false,
                'statut' => StatutAttribution::ACTIVE,
                'artisan_id' => $artisan->getKey(),
                'espace_locatif_id' => $espace->getKey(),
                'exercice_id' => $this->exercice->getKey(),
            ]);

            $rapport->incrementer(RapportImport::ATTRIBUTIONS_CREEES);
            $rapport->incrementer(RapportImport::ATTRIBUTIONS_SANS_REDEVANCE);
        }

        $this->attributions[$cle] = true;
    }

    /**
     * @param  array{designation: string, conditionnement: string, prix: int}  $profil
     */
    protected function resoudreProduit(
        Boutique $boutique,
        Artisan $artisan,
        array $profil,
        RapportImport $rapport,
    ): Produit {
        $description = $profil['conditionnement'] !== ''
            ? 'Conditionnement : '.$profil['conditionnement']
            : null;

        $cle = implode('|', [
            $boutique->getKey(),
            $artisan->getKey(),
            Normalisation::comparable($profil['designation']),
            Normalisation::comparable($profil['conditionnement']),
        ]);

        if (isset($this->produits[$cle])) {
            return $this->produits[$cle];
        }

        $produit = Produit::query()
            ->where('boutique_id', $boutique->getKey())
            ->where('artisan_id', $artisan->getKey())
            ->where('designation', $profil['designation'])
            ->when(
                $description === null,
                fn ($requete) => $requete->whereNull('description'),
                fn ($requete) => $requete->where('description', $description),
            )
            ->first();

        if (! $produit) {
            $produit = Produit::create([
                'designation' => $profil['designation'],
                // Le conditionnement n'est pas une colonne du produit :
                // il est ce qui distingue « Miel / Bouteille » de
                // « Miel / Sachet », donc il doit rester lisible sur la
                // fiche. La description est le seul endroit du modèle
                // qui puisse le porter sans inventer de colonne.
                'description' => $description,
                'prix_unitaire' => $profil['prix'],
                'seuil_alerte' => null,
                'piece_unique' => false,
                'actif' => true,
                // Voir la migration
                // « rendre_la_categorie_de_produit_facultative ».
                'categorie_id' => null,
                'artisan_id' => $artisan->getKey(),
                'boutique_id' => $boutique->getKey(),
            ]);

            // Un produit soumis n'est pas vendable (règle 14). La
            // validation passe par son service, qui vérifie
            // l'habilitation du compte et constate le validateur :
            // écrire le statut en direct contournerait les deux.
            $this->validation->valider($produit);

            $rapport->incrementer(RapportImport::PRODUITS_CREES);
            $rapport->incrementer(RapportImport::PRODUITS_SANS_CATEGORIE);
        }

        return $this->produits[$cle] = $produit;
    }

    /**
     * @param  callable(int, int): void|null  $progression
     */
    protected function avancer(?callable $progression, int $rang, int $total): void
    {
        if ($progression !== null) {
            $progression($rang, $total);
        }
    }

    /**
     * @param  array<string, int|null>  $rattachements
     */
    protected function tracer(LigneRegistre $ligne, string $statut, array $rattachements = []): void
    {
        TraceLigneImportee::create(array_merge([
            'fichier' => $this->fichierCourant,
            'numero_ligne' => $ligne->numero,
            'empreinte' => $ligne->empreinte,
            'statut' => $statut,
            'anomalies' => $ligne->anomalies,
        ], $rattachements));
    }
}
