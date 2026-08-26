<?php

namespace App\Import;

use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Lecture du registre transcrit : du fichier vers des lignes
 * exploitables.
 *
 * Le lecteur ne touche pas à la base. Il fait une seule chose, mais il
 * la fait sur la totalité du fichier avant que quoi que ce soit ne
 * s'écrive : il transforme mille cent quarante-neuf lignes de registre
 * manuscrit en objets, en disant pour chacune ce qu'il a dû supposer.
 *
 * **Pourquoi la lecture précède l'écriture.** Trois décisions de
 * l'import ne peuvent pas se prendre ligne à ligne : le prix d'un
 * produit est celui de sa **première** occurrence, la date de début
 * d'une occupation est celle de la **plus ancienne** vente de
 * l'artisan dans le local, et le rapprochement des noms d'artisans
 * suppose de les connaître tous. Écrire au fil de la lecture obligerait
 * à revenir corriger des enregistrements déjà posés — et un produit
 * dont le prix a été réécrit après coup n'est plus un prix figé.
 *
 * **Ce que le lecteur s'autorise.** Reprendre la valeur de la ligne
 * précédente quand la cellule porte un guillemet de répétition ;
 * compléter une année absente d'une date en `14/07` ; déduire la
 * troisième valeur quand deux des trois — quantité, prix, montant —
 * sont présentes. Chacune de ces trois libertés laisse une anomalie
 * attachée à la ligne.
 *
 * **La date est le seul cas où une cellule vide se reporte**, et c'est
 * une lecture du document, pas une facilité : un cahier de ventes ne
 * réécrit la date qu'au changement de jour, si bien qu'une cellule
 * laissée blanche y **signifie** « même jour ». Une désignation ou un
 * nom d'artisan laissés blancs ne signifient rien de tel : ils
 * signalent que le scribe n'a pas noté, et les compléter d'après la
 * ligne du dessus attribuerait une vente à quelqu'un au hasard. Un
 * guillemet de répétition, lui, se reporte partout, parce qu'il est une
 * écriture explicite de la source.
 *
 * **Ce qu'il refuse.** Deviner un nom d'artisan, deviner une
 * désignation, réparer un montant en retouchant le prix ou la quantité.
 */
class LecteurRegistre
{
    /**
     * Colonnes attendues, dans n'importe quel ordre.
     *
     * Vérifiées à l'ouverture plutôt que découvertes à la trente-septième
     * ligne : un fichier au mauvais format doit échouer avant la première
     * écriture, pas au milieu.
     *
     * @var array<int, string>
     */
    public const COLONNES = [
        'date',
        'code_boutique_source',
        'code_boutique_normalise',
        'nom_artisan_source',
        'designation',
        'conditionnement',
        'quantite',
        'prix_unitaire',
        'montant',
        'coherence',
        'vendeur_reference',
    ];

    /**
     * Colonnes lues si elles sont là, ignorées sinon.
     *
     * Elles ne viennent pas du cahier mais du travail de rattachement
     * de la coordination : l'espace locatif de l'artisan, son nom
     * officiel au parc, et la redevance convenue pour cet espace. Les
     * exiger ferait échouer la lecture d'un registre transcrit avant
     * que ce rattachement n'existe — or l'ordre des travaux est bien
     * celui-là : on transcrit, puis on rattache.
     *
     * @var array<int, string>
     */
    public const COLONNES_FACULTATIVES = [
        'espace_locatif',
        'nom_artisan_officiel',
        'redevance_convenue',
    ];

    /**
     * Valeurs qui ne désignent rien : le registre les emploie là où le
     * scribe n'a pas su.
     *
     * @var array<int, string>
     */
    protected const NON_RENSEIGNE = ['?', '-', '--', 'N/A', 'NA', 'NEANT', 'NÉANT'];

    /**
     * Fenêtre de vraisemblance des dates, en années autour de l'année
     * courante. Voir `estVraisemblable()` pour le raisonnement.
     */
    protected const ANNEES_EN_ARRIERE = 20;

    protected const ANNEES_EN_AVANT = 1;

    /**
     * @return array<int, LigneRegistre>
     */
    public function lire(string $chemin): array
    {
        if (! is_file($chemin) || ! is_readable($chemin)) {
            throw new RuntimeException("Registre introuvable ou illisible : {$chemin}");
        }

        $fichier = basename($chemin);
        $flux = fopen($chemin, 'r');

        if ($flux === false) {
            throw new RuntimeException("Ouverture impossible du registre : {$chemin}");
        }

        try {
            $entete = $this->lireEntete($flux, $chemin);

            $lignes = [];
            $numero = 0;

            // Report des cellules répétées. Le registre est tenu à la
            // main : une colonne laissée vide veut dire « comme
            // au-dessus », et c'est ainsi qu'il se lit sur papier.
            $dateCourante = null;
            $codeBoutiqueCourant = '';
            $nomArtisanCourant = null;
            $designationCourante = '';
            $conditionnementCourant = '';

            while (($cellules = fgetcsv($flux, 0, ',', '"', '')) !== false) {
                // Ligne entièrement vide : le fichier en porte parfois
                // en fin de parcours. Elle n'est pas une ligne du
                // registre et ne doit donc rien peser au rapport.
                if ($cellules === [null] || $this->estVide($cellules)) {
                    continue;
                }

                $numero++;
                $brut = $this->associer($entete, $cellules);

                $ligne = $this->composer(
                    $fichier,
                    $numero,
                    $brut,
                    $dateCourante,
                    $codeBoutiqueCourant,
                    $nomArtisanCourant,
                    $designationCourante,
                    $conditionnementCourant,
                );

                $lignes[] = $ligne;

                // Le report retient la valeur **effective** de la ligne
                // qui vient d'être lue, y compris quand elle est vide.
                // Un guillemet de répétition renvoie à la ligne du
                // dessus et à aucune autre : remonter jusqu'à la
                // dernière cellule renseignée ferait dire au registre
                // l'inverse de ce qu'il écrit — un conditionnement
                // laissé blanc trois lignes plus haut reviendrait
                // s'appliquer à un article qui n'en a pas.
                //
                // La date fait exception, et seulement au début du
                // fichier : tant qu'aucune date n'a pu être établie, il
                // n'y a rien à reporter.
                $dateCourante = $ligne->date ?? $dateCourante;
                $codeBoutiqueCourant = $ligne->codeBoutique;
                $nomArtisanCourant = $ligne->nomArtisan;
                $designationCourante = $ligne->designation;
                $conditionnementCourant = $ligne->conditionnement;
            }

            return $lignes;
        } finally {
            fclose($flux);
        }
    }

    /**
     * @param  resource  $flux
     * @return array<int, string>
     */
    protected function lireEntete($flux, string $chemin): array
    {
        $entete = fgetcsv($flux, 0, ',', '"', '');

        if ($entete === false || $entete === [null]) {
            throw new RuntimeException("Registre vide : {$chemin}");
        }

        // Marque d'ordre des octets déposée par les tableurs.
        $entete[0] = preg_replace('/^\x{FEFF}/u', '', (string) $entete[0]) ?? '';
        $entete = array_map(fn ($colonne) => trim((string) $colonne), $entete);

        $manquantes = array_diff(self::COLONNES, $entete);

        if ($manquantes !== []) {
            throw new RuntimeException(
                'Colonnes absentes du registre : '.implode(', ', $manquantes)
                .'. Attendues : '.implode(', ', self::COLONNES).'.'
            );
        }

        return $entete;
    }

    /**
     * @param  array<int, string|null>  $cellules
     */
    protected function estVide(array $cellules): bool
    {
        foreach ($cellules as $cellule) {
            if (trim((string) $cellule) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $entete
     * @param  array<int, string|null>  $cellules
     * @return array<string, string>
     */
    protected function associer(array $entete, array $cellules): array
    {
        $brut = [];

        foreach ($entete as $rang => $colonne) {
            $brut[$colonne] = trim((string) ($cellules[$rang] ?? ''));
        }

        return $brut;
    }

    /**
     * @param  array<string, string>  $brut
     */
    protected function composer(
        string $fichier,
        int $numero,
        array $brut,
        ?Carbon $dateCourante,
        string $codeBoutiqueCourant,
        ?string $nomArtisanCourant,
        string $designationCourante,
        string $conditionnementCourant,
    ): LigneRegistre {
        $anomalies = [];

        [$date, $anomalieDate] = $this->resoudreDate($brut['date'], $dateCourante);
        $this->ajouter($anomalies, $anomalieDate);

        [$codeBoutique, $anomalieBoutique] = $this->resoudreCodeBoutique(
            $brut['code_boutique_normalise'],
            $brut['code_boutique_source'],
            $codeBoutiqueCourant,
        );
        $this->ajouter($anomalies, $anomalieBoutique);

        [$nomArtisan, $anomalieArtisan] = $this->resoudreNomArtisan($brut['nom_artisan_source'], $nomArtisanCourant);
        $this->ajouter($anomalies, $anomalieArtisan);

        [$designation, $anomalieDesignation] = $this->resoudreRepetition($brut['designation'], $designationCourante);
        $this->ajouter($anomalies, $anomalieDesignation === null ? null : LigneRegistre::DESIGNATION_REPRISE);

        if ($designation === '') {
            $this->ajouter($anomalies, LigneRegistre::DESIGNATION_ABSENTE);
        }

        [$conditionnement] = $this->resoudreRepetition($brut['conditionnement'], $conditionnementCourant);

        [$quantite, $prix, $montant, $anomaliesMontants] = $this->resoudreMontants(
            $brut['quantite'],
            $brut['prix_unitaire'],
            $brut['montant'],
        );

        foreach ($anomaliesMontants as $anomalie) {
            $this->ajouter($anomalies, $anomalie);
        }

        $ecartSource = strtoupper(trim($brut['coherence'])) === 'ECART';

        if ($ecartSource) {
            $this->ajouter($anomalies, LigneRegistre::ECART_SIGNALE_A_LA_SOURCE);
        }

        $ligne = new LigneRegistre(
            numero: $numero,
            empreinte: $this->empreinte($fichier, $numero, $brut),
            brut: $brut,
            date: $date,
            codeBoutiqueSource: Normalisation::lisible($brut['code_boutique_source']),
            codeBoutique: $codeBoutique,
            nomArtisan: $nomArtisan,
            designation: $designation,
            conditionnement: $conditionnement,
            quantite: $quantite,
            prixUnitaire: $prix,
            montantTranscrit: $montant,
            ecartSignaleALaSource: $ecartSource,
            anomalies: $anomalies,
            espaceLocatif: Normalisation::codeBoutique($brut['espace_locatif'] ?? ''),
            nomArtisanOfficiel: Normalisation::lisible($brut['nom_artisan_officiel'] ?? ''),
            redevanceConvenue: Normalisation::entier($brut['redevance_convenue'] ?? ''),
        );

        // L'écart de calcul est constaté sur les valeurs retenues, et non
        // recopié de la colonne « coherence » du fichier. Le registre
        // signale cinquante lignes ; le contrôle, lui, les recompte
        // toutes — c'est ce qui permet de dire au rapport si la
        // transcription a manqué des écarts, plutôt que de la croire.
        if ($ligne->enEcartDeCalcul()) {
            $ligne->signaler(LigneRegistre::ECART_DE_CALCUL);
        }

        return $ligne;
    }

    /**
     * @param  array<int, string>  $anomalies
     */
    protected function ajouter(array &$anomalies, ?string $anomalie): void
    {
        if ($anomalie !== null && ! in_array($anomalie, $anomalies, strict: true)) {
            $anomalies[] = $anomalie;
        }
    }

    /**
     * @param  array<string, string>  $brut
     */
    protected function empreinte(string $fichier, int $numero, array $brut): string
    {
        // Le rang entre dans l'empreinte : deux lignes rigoureusement
        // identiques — le même miel, le même jour, au même prix — sont
        // deux ventes distinctes, et non un doublon à écarter.
        return hash('sha256', $fichier."\x1f".$numero."\x1f".implode("\x1f", $brut));
    }

    /**
     * Date de la ligne, et ce qu'il a fallu supposer pour l'obtenir.
     *
     * Quatre écritures cohabitent dans le registre : la date complète en
     * ISO, la date française à deux ou quatre chiffres d'année, le jour
     * et le mois seuls — le scribe ne réécrivait pas l'année à chaque
     * page — et la cellule vide ou répétée.
     *
     * @return array{0: ?Carbon, 1: ?string}
     */
    protected function resoudreDate(string $brut, ?Carbon $precedente): array
    {
        $valeur = trim($brut);

        if ($valeur === '' || Normalisation::estRepetition($valeur) || $this->estNonRenseigne($valeur)) {
            return $precedente
                ? [$precedente->copy(), LigneRegistre::DATE_REPRISE]
                : [null, LigneRegistre::DATE_INDETERMINABLE];
        }

        // Le format est reconnu par la forme de l'écriture avant d'être
        // analysé. Laisser `createFromFormat` deviner mènerait au pire
        // des résultats : `31/12/25` lu au format `d/m/Y` donne l'an 25
        // de notre ère, sans le moindre avertissement.
        $normalise = str_replace('-', '/', $valeur);

        $formats = [
            '#^\d{4}/\d{1,2}/\d{1,2}$#' => ['Y/m/d', $normalise],
            '#^\d{1,2}/\d{1,2}/\d{4}$#' => ['d/m/Y', $normalise],
            '#^\d{1,2}/\d{1,2}/\d{2}$#' => ['d/m/y', $normalise],
        ];

        foreach ($formats as $forme => [$format, $sujet]) {
            if (preg_match($forme, $sujet) !== 1) {
                continue;
            }

            $date = $this->parserStrict($sujet, $format);

            if ($date === null) {
                return $this->reporterDateInvalide($precedente, LigneRegistre::DATE_INVALIDE);
            }

            if (! $this->estVraisemblable($date)) {
                return $this->reporterDateInvalide($precedente, LigneRegistre::DATE_INVRAISEMBLABLE);
            }

            return [$date, null];
        }

        // Jour et mois seuls : l'année vient de la ligne précédente. Le
        // scribe ne réécrivait pas l'année à chaque page.
        if (preg_match('#^(\d{1,2})/(\d{1,2})$#', $normalise, $trouve) === 1 && $precedente) {
            $date = $this->parserStrict(
                sprintf('%04d/%02d/%02d', $precedente->year, (int) $trouve[2], (int) $trouve[1]),
                'Y/m/d',
            );

            if ($date !== null) {
                return [$date, LigneRegistre::DATE_ANNEE_DEDUITE];
            }
        }

        return $this->reporterDateInvalide($precedente, LigneRegistre::DATE_INVALIDE);
    }

    /**
     * Le registre porte un 30 février et un 4 août 1026. On ne les
     * corrige ni au 29, ni à 2026 : rien ne dit que le scribe visait le
     * dernier jour du mois plutôt que le premier du suivant, et choisir
     * pour lui reviendrait à inventer une donnée que personne ne
     * saurait plus distinguer d'une donnée relevée. La ligne précédente
     * fait foi, l'anomalie le dit, et la coordination tranche sur pièce.
     *
     * @return array{0: ?Carbon, 1: string}
     */
    protected function reporterDateInvalide(?Carbon $precedente, string $anomalie): array
    {
        return $precedente
            ? [$precedente->copy(), $anomalie]
            : [null, $anomalie];
    }

    /**
     * La date tombe-t-elle dans la période où un registre du village
     * peut avoir été tenu ?
     *
     * Le contrôle n'est pas une coquetterie. Le registre porte un
     * « 04/08/1026 », faute de frappe évidente sur 2026 — et sans
     * garde-fou, cette seule ligne devient la plus ancienne pièce du
     * fichier, l'import cherche alors un taux de commission en vigueur
     * au onzième siècle, n'en trouve aucun, et refuse de démarrer. Une
     * coquille de transcription bloquerait la reprise entière.
     *
     * Vingt ans en arrière et un an en avant : assez large pour ne
     * jamais écarter une pièce réelle — le village n'existait pas il y
     * a vingt ans — assez étroit pour attraper une erreur de siècle ou
     * une date saisie au clavier numérique.
     */
    protected function estVraisemblable(Carbon $date): bool
    {
        $annee = (int) $date->format('Y');
        $courante = (int) Carbon::now()->format('Y');

        return $annee >= $courante - self::ANNEES_EN_ARRIERE
            && $annee <= $courante + self::ANNEES_EN_AVANT;
    }

    /**
     * Analyse une date en refusant les débordements.
     *
     * `Carbon::createFromFormat` accepte volontiers le 30 février et le
     * reporte au 1er mars. Sur un registre transcrit à la main, ce
     * report transformerait une faute de copie en donnée plausible :
     * on préfère la refuser et la signaler.
     */
    protected function parserStrict(string $valeur, string $format): ?Carbon
    {
        $date = \DateTime::createFromFormat('!'.$format, $valeur);
        $erreurs = \DateTime::getLastErrors();

        if ($date === false) {
            return null;
        }

        if (is_array($erreurs) && (($erreurs['warning_count'] ?? 0) > 0 || ($erreurs['error_count'] ?? 0) > 0)) {
            return null;
        }

        return Carbon::instance($date)->startOfDay();
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    protected function resoudreCodeBoutique(string $normalise, string $source, string $precedent): array
    {
        $valeur = Normalisation::codeBoutique($normalise);

        if ($valeur === '' || $this->estNonRenseigne($valeur)) {
            $valeur = Normalisation::codeBoutique($source);
        }

        if (Normalisation::estRepetition($valeur)) {
            return $precedent !== ''
                ? [$precedent, LigneRegistre::BOUTIQUE_REPRISE]
                : ['', LigneRegistre::BOUTIQUE_ABSENTE];
        }

        if ($valeur === '' || $this->estNonRenseigne($valeur)) {
            return ['', LigneRegistre::BOUTIQUE_ABSENTE];
        }

        return [$valeur, null];
    }

    /**
     * Nom d'artisan, ou rien.
     *
     * La règle de l'énoncé est stricte : jamais de nom supposé. Une
     * cellule vide donne donc `null`, que l'import fera basculer sur
     * l'artisan « Non identifié ». Un guillemet de répétition, lui,
     * n'est pas une absence : c'est le registre qui désigne la ligne
     * du dessus.
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function resoudreNomArtisan(string $brut, ?string $precedent): array
    {
        $valeur = Normalisation::lisible($brut);

        if (Normalisation::estRepetition($brut)) {
            return $precedent !== null
                ? [$precedent, LigneRegistre::ARTISAN_REPRIS]
                : [null, LigneRegistre::ARTISAN_ABSENT];
        }

        if ($valeur === '' || $this->estNonRenseigne($valeur)) {
            return [null, LigneRegistre::ARTISAN_ABSENT];
        }

        return [$valeur, null];
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    protected function resoudreRepetition(string $brut, string $precedent): array
    {
        if (Normalisation::estRepetition($brut)) {
            return $precedent !== '' ? [$precedent, 'reprise'] : ['', null];
        }

        $valeur = Normalisation::lisible($brut);

        return [$this->estNonRenseigne($valeur) ? '' : $valeur, null];
    }

    /**
     * Quantité, prix et montant : deux valeurs suffisent à établir la
     * troisième.
     *
     * L'ordre des tentatives n'est pas indifférent. La quantité et le
     * prix sont les données que le scribe notait en premier et
     * relisait ; le montant est le résultat de son calcul mental,
     * c'est-à-dire l'endroit où l'erreur se loge. Quand les trois sont
     * là, on garde les trois — l'écart éventuel est un fait à
     * consigner, pas une contradiction à trancher. Quand il en manque
     * une, on la déduit des deux autres, et on le dit.
     *
     * @return array{0: ?int, 1: ?int, 2: ?int, 3: array<int, string>}
     */
    protected function resoudreMontants(string $quantiteBrute, string $prixBrut, string $montantBrut): array
    {
        $quantite = Normalisation::entier($quantiteBrute);
        $prix = Normalisation::entier($prixBrut);
        $montant = Normalisation::entier($montantBrut);

        $anomalies = [];

        if ($quantite !== null && $prix !== null) {
            if ($montant === null) {
                $anomalies[] = LigneRegistre::MONTANT_DEDUIT;
            }

            return [$quantite, $prix, $montant, $anomalies];
        }

        if ($quantite !== null && $montant !== null && $quantite > 0) {
            $anomalies[] = LigneRegistre::PRIX_DEDUIT;

            return [$quantite, (int) round($montant / $quantite), $montant, $anomalies];
        }

        if ($prix !== null && $montant !== null && $prix > 0) {
            $anomalies[] = LigneRegistre::QUANTITE_DEDUITE;

            return [max(1, (int) round($montant / $prix)), $prix, $montant, $anomalies];
        }

        $anomalies[] = LigneRegistre::VALEURS_INSUFFISANTES;

        return [$quantite, $prix, $montant, $anomalies];
    }

    protected function estNonRenseigne(string $valeur): bool
    {
        return in_array(mb_strtoupper($valeur), self::NON_RENSEIGNE, strict: true);
    }
}
