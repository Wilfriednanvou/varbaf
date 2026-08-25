<?php

namespace App\Import;

use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Rapport d'import : ce que la reprise a fait, et ce qu'elle n'a pas su
 * faire.
 *
 * **Le rapport n'est pas un journal d'exécution.** Il ne dit pas
 * combien de temps l'import a duré ni combien de requêtes il a émises :
 * il dit ce que la coordination doit reprendre à la main. Toutes les
 * parts sont rapportées au nombre de lignes traitées, parce que c'est
 * la seule base qui ait un sens pour juger de la qualité d'une
 * transcription — mille cent quarante-neuf lignes dont cinquante en
 * écart, ce n'est pas la même chose que mille cent quarante-neuf lignes
 * dont cinquante importées.
 *
 * **Deux fichiers plutôt qu'un.** Le premier porte les indicateurs
 * demandés, le second la liste ligne à ligne des signalements. Les
 * mélanger dans un seul CSV donnerait un fichier qu'aucun tableur
 * n'ouvre proprement : un tableau de synthèse et un tableau de détail
 * n'ont pas les mêmes colonnes. Le second est en outre ce qu'on ouvre
 * réellement pour travailler — c'est lui qui liste les lignes en écart
 * que l'énoncé demande de lister.
 */
class RapportImport
{
    // === Lignes ===
    public const LIGNES_TRAITEES = 'lignes_traitees';

    public const LIGNES_IMPORTEES = 'lignes_importees';

    public const LIGNES_SIGNALEES = 'lignes_signalees';

    public const LIGNES_NON_IMPORTEES = 'lignes_non_importees';

    public const LIGNES_DEJA_REPRISES = 'lignes_deja_reprises';

    // === Qualité de la transcription ===
    public const ECARTS_A_LA_SOURCE = 'ecarts_a_la_source';

    public const ECARTS_DE_CALCUL = 'ecarts_de_calcul';

    public const LIGNES_SANS_ARTISAN = 'lignes_sans_artisan';

    public const LIGNES_SANS_DATE_PROPRE = 'lignes_sans_date_propre';

    public const LIGNES_VALEURS_DEDUITES = 'lignes_valeurs_deduites';

    // === Rapprochement ===
    public const ARTISANS_ECRITURES = 'artisans_ecritures';

    public const ARTISANS_REGROUPES = 'artisans_regroupes';

    public const ARTISANS_DISTINCTS = 'artisans_distincts';

    public const ARTISANS_DOUTES = 'artisans_doutes';

    public const BOUTIQUES_ECRITURES = 'boutiques_ecritures';

    public const BOUTIQUES_RETENUES = 'boutiques_retenues';

    public const BOUTIQUES_REGROUPEES = 'boutiques_regroupees';

    // === Créations ===
    public const ARTISANS_CREES = 'artisans_crees';

    public const ESPACES_CREES = 'espaces_crees';

    public const ESPACES_HORS_PARC = 'espaces_hors_parc';

    public const ATTRIBUTIONS_CREEES = 'attributions_creees';

    public const PRODUITS_CREES = 'produits_crees';

    public const DEPOTS_CREES = 'depots_crees';

    public const VENTES_CREEES = 'ventes_creees';

    // === À compléter à la main ===
    public const ARTISANS_SANS_SECTEUR = 'artisans_sans_secteur';

    public const PRODUITS_SANS_CATEGORIE = 'produits_sans_categorie';

    public const ATTRIBUTIONS_SANS_REDEVANCE = 'attributions_sans_redevance';

    /** @var array<string, int> */
    protected array $compteurs = [];

    /** @var array<int, array<string, string>> */
    protected array $signalements = [];

    /** @var array<int, array{code: string, espace: string}> */
    protected array $horsParc = [];

    /** @var array<int, array{nom: string, candidat: string, score: float}> */
    protected array $doutes = [];

    public function __construct(
        public readonly string $fichier,
        public readonly float $seuil,
        public readonly float $marge,
    ) {}

    public function incrementer(string $cle, int $pas = 1): void
    {
        $this->compteurs[$cle] = ($this->compteurs[$cle] ?? 0) + $pas;
    }

    public function fixer(string $cle, int $valeur): void
    {
        $this->compteurs[$cle] = $valeur;
    }

    public function valeur(string $cle): int
    {
        return $this->compteurs[$cle] ?? 0;
    }

    public function ajouterSignalement(LigneRegistre $ligne, string $statut): void
    {
        $this->signalements[] = [
            'ligne' => (string) $ligne->numero,
            'date' => $ligne->date?->format('d/m/Y') ?? '',
            'code_boutique_source' => $ligne->codeBoutiqueSource,
            'artisan_source' => $ligne->nomArtisan ?? '',
            'designation' => $ligne->designation,
            'quantite' => $ligne->quantite === null ? '' : (string) $ligne->quantite,
            'prix_unitaire' => $ligne->prixUnitaire === null ? '' : (string) $ligne->prixUnitaire,
            'montant_transcrit' => $ligne->montantTranscrit === null ? '' : (string) $ligne->montantTranscrit,
            'montant_retenu' => $ligne->montantCalcule() === null ? '' : (string) $ligne->montantCalcule(),
            'ecart' => $ligne->ecartDeCalcul() === null ? '' : (string) $ligne->ecartDeCalcul(),
            'statut' => $statut,
            'anomalies' => $ligne->libelleAnomalies(),
        ];
    }

    public function signalerEspaceHorsParc(string $codeSource, string $codeEspace): void
    {
        $this->horsParc[] = ['code' => $codeSource, 'espace' => $codeEspace];
    }

    /**
     * @param  array<int, array{nom: string, candidat: string, score: float}>  $doutes
     */
    public function fixerDoutes(array $doutes): void
    {
        $this->doutes = $doutes;
        $this->fixer(self::ARTISANS_DOUTES, count($doutes));
    }

    /**
     * @return array<int, array{nom: string, candidat: string, score: float}>
     */
    public function doutes(): array
    {
        return $this->doutes;
    }

    /**
     * @return array<int, array{code: string, espace: string}>
     */
    public function horsParc(): array
    {
        return $this->horsParc;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function signalements(): array
    {
        return $this->signalements;
    }

    public function part(string $cle): string
    {
        $base = $this->valeur(self::LIGNES_TRAITEES);

        if ($base === 0) {
            return '—';
        }

        return number_format($this->valeur($cle) * 100 / $base, 1, ',', ' ').' %';
    }

    /**
     * Indicateurs du rapport, dans l'ordre où ils se lisent.
     *
     * @return array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    public function indicateurs(): array
    {
        return [
            ['Lignes', 'Lignes traitées', (string) $this->valeur(self::LIGNES_TRAITEES), '100,0 %'],
            ['Lignes', 'Lignes importées', (string) $this->valeur(self::LIGNES_IMPORTEES), $this->part(self::LIGNES_IMPORTEES)],
            ['Lignes', 'Lignes signalées', (string) $this->valeur(self::LIGNES_SIGNALEES), $this->part(self::LIGNES_SIGNALEES)],
            ['Lignes', 'Lignes non importées', (string) $this->valeur(self::LIGNES_NON_IMPORTEES), $this->part(self::LIGNES_NON_IMPORTEES)],
            ['Lignes', 'Lignes déjà reprises lors d\'un import antérieur', (string) $this->valeur(self::LIGNES_DEJA_REPRISES), $this->part(self::LIGNES_DEJA_REPRISES)],

            ['Transcription', 'Lignes en écart de calcul', (string) $this->valeur(self::ECARTS_DE_CALCUL), $this->part(self::ECARTS_DE_CALCUL)],
            ['Transcription', 'Lignes marquées ECART à la source', (string) $this->valeur(self::ECARTS_A_LA_SOURCE), $this->part(self::ECARTS_A_LA_SOURCE)],
            ['Transcription', 'Lignes sans artisan identifiable', (string) $this->valeur(self::LIGNES_SANS_ARTISAN), $this->part(self::LIGNES_SANS_ARTISAN)],
            ['Transcription', 'Lignes dont la date a dû être reprise ou déduite', (string) $this->valeur(self::LIGNES_SANS_DATE_PROPRE), $this->part(self::LIGNES_SANS_DATE_PROPRE)],
            ['Transcription', 'Lignes dont une valeur a été déduite des deux autres', (string) $this->valeur(self::LIGNES_VALEURS_DEDUITES), $this->part(self::LIGNES_VALEURS_DEDUITES)],

            ['Artisans', 'Écritures distinctes dans le registre', (string) $this->valeur(self::ARTISANS_ECRITURES), '—'],
            ['Artisans', 'Écritures regroupées automatiquement', (string) $this->valeur(self::ARTISANS_REGROUPES), $this->partDe(self::ARTISANS_REGROUPES, self::ARTISANS_ECRITURES)],
            ['Artisans', 'Écritures restées distinctes', (string) $this->valeur(self::ARTISANS_DISTINCTS), $this->partDe(self::ARTISANS_DISTINCTS, self::ARTISANS_ECRITURES)],
            ['Artisans', "Rapprochements écartés (sous le seuil de {$this->seuilLisible()})", (string) $this->valeur(self::ARTISANS_DOUTES), '—'],

            ['Boutiques', 'Écritures distinctes dans le registre', (string) $this->valeur(self::BOUTIQUES_ECRITURES), '—'],
            ['Boutiques', 'Écritures regroupées', (string) $this->valeur(self::BOUTIQUES_REGROUPEES), $this->partDe(self::BOUTIQUES_REGROUPEES, self::BOUTIQUES_ECRITURES)],
            ['Boutiques', 'Emplacements retenus', (string) $this->valeur(self::BOUTIQUES_RETENUES), '—'],

            ['Créations', 'Artisans créés', (string) $this->valeur(self::ARTISANS_CREES), '—'],
            ['Créations', 'Espaces locatifs créés', (string) $this->valeur(self::ESPACES_CREES), '—'],
            ['Créations', 'dont espaces hors parc (boutique technique)', (string) $this->valeur(self::ESPACES_HORS_PARC), '—'],
            ['Créations', 'Attributions créées', (string) $this->valeur(self::ATTRIBUTIONS_CREEES), '—'],
            ['Créations', 'Produits créés', (string) $this->valeur(self::PRODUITS_CREES), '—'],
            ['Créations', 'Dépôts créés', (string) $this->valeur(self::DEPOTS_CREES), '—'],
            ['Créations', 'Ventes créées', (string) $this->valeur(self::VENTES_CREEES), '—'],

            ['À compléter', 'Artisans sans secteur d\'activité', (string) $this->valeur(self::ARTISANS_SANS_SECTEUR), '—'],
            ['À compléter', 'Produits sans catégorie', (string) $this->valeur(self::PRODUITS_SANS_CATEGORIE), '—'],
            ['À compléter', 'Attributions sans redevance convenue', (string) $this->valeur(self::ATTRIBUTIONS_SANS_REDEVANCE), '—'],
        ];
    }

    public function seuilLisible(): string
    {
        return number_format($this->seuil, 1, ',', ' ').' %';
    }

    protected function partDe(string $cle, string $base): string
    {
        $total = $this->valeur($base);

        if ($total === 0) {
            return '—';
        }

        return number_format($this->valeur($cle) * 100 / $total, 1, ',', ' ').' %';
    }

    /**
     * Écrit les deux fichiers et retourne leurs chemins.
     *
     * @return array{0: string, 1: string}
     */
    public function exporter(string $repertoire, ?Carbon $horodatage = null): array
    {
        if (! is_dir($repertoire) && ! mkdir($repertoire, 0775, true) && ! is_dir($repertoire)) {
            throw new RuntimeException("Répertoire de rapport inaccessible : {$repertoire}");
        }

        $marque = ($horodatage ?? Carbon::now())->format('Ymd-His');
        $base = rtrim($repertoire, '/\\').DIRECTORY_SEPARATOR."rapport-import-{$marque}";

        $synthese = $base.'.csv';
        $detail = $base.'-signalements.csv';

        $this->ecrire($synthese, ['Rubrique', 'Indicateur', 'Valeur', 'Part'], $this->indicateurs());

        $this->ecrire(
            $detail,
            ['Ligne', 'Date', 'Code boutique source', 'Artisan source', 'Désignation', 'Quantité',
                'Prix unitaire', 'Montant transcrit', 'Montant retenu', 'Écart', 'Statut', 'Anomalies'],
            array_map('array_values', $this->signalements),
        );

        return [$synthese, $detail];
    }

    /**
     * @param  array<int, string>  $entete
     * @param  array<int, array<int, string>>  $lignes
     */
    protected function ecrire(string $chemin, array $entete, array $lignes): void
    {
        $flux = fopen($chemin, 'w');

        if ($flux === false) {
            throw new RuntimeException("Écriture impossible du rapport : {$chemin}");
        }

        try {
            // Marque d'ordre des octets : sans elle, Excel affiche les
            // accents en mojibake et la coordination croit le fichier
            // corrompu.
            fwrite($flux, "\xEF\xBB\xBF");

            // Point-virgule : c'est le séparateur qu'attend un tableur
            // configuré en français, où la virgule est le séparateur
            // décimal.
            fputcsv($flux, $entete, ';', '"', '');

            foreach ($lignes as $ligne) {
                fputcsv($flux, $ligne, ';', '"', '');
            }
        } finally {
            fclose($flux);
        }
    }
}
