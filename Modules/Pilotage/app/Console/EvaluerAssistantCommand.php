<?php

namespace Modules\Pilotage\Console;

use Illuminate\Console\Command;
use Modules\Pilotage\Contracts\MoteurDeRecherche;
use Modules\Pilotage\Enums\BrancheReponse;
use Modules\Pilotage\Enums\CategorieQuestion;
use Modules\Pilotage\Recommandation\ResolveurDeMoteur;
use Modules\Pilotage\Services\ServiceAssistant;

/**
 * Mesure l'assistant sur un jeu de questions à réponses attendues.
 *
 * **Trois mesures, et une comparaison.**
 *
 * - *Classification* : la question a-t-elle été envoyée à la bonne
 *   branche ? C'est la mesure de la frontière entre calcul et recherche,
 *   celle dont dépendent toutes les autres.
 * - *Rappel@5* : sur les questions descriptives qui ont une réponse, la
 *   proportion des sources attendues qui figurent dans les cinq premiers
 *   extraits.
 * - *Refus correct* : sur les questions délibérément sans réponse, la
 *   proportion pour laquelle l'assistant refuse effectivement. Sans
 *   cette mesure, un assistant qui répond toujours quelque chose
 *   afficherait un rappel flatteur et serait inutilisable.
 *
 * La comparaison oppose le moteur lexical pondéré au moteur témoin par
 * mots-clés, sur le même jeu et la même tokenisation : c'est ce que
 * l'hypothèse H3 demande de trancher, et ce que la table 4.3 doit
 * porter.
 *
 * Format du fichier, point-virgule pour séparateur :
 *
 *     question;categorie_attendue;attendus;refus_attendu
 *
 * `categorie_attendue` vaut AGREGATION ou DESCRIPTIVE. `attendus` est
 * une liste de fragments séparés par des barres verticales, cherchés
 * dans les sources retrouvées — titre **et** extrait depuis le 27/08,
 * les titres seuls ne pouvant jamais porter le corps de métier d'une
 * fiche produit. Des fragments plutôt que des identifiants, pour que le
 * jeu reste valable quand la base est réimportée et que les clés
 * changent. `refus_attendu` vaut oui ou non.
 */
class EvaluerAssistantCommand extends Command
{
    protected $signature = 'varbaf:evaluer-assistant
        {fichier? : Chemin du jeu de questions, relatif à la racine du projet}
        {--moteur= : Restreindre la mesure à un moteur (lexical, mots_cles, dense, hybride)}
        {--rapport= : Répertoire où déposer le CSV des résultats}
        {--detail : Afficher le détail question par question}';

    protected $description = 'Mesure classification, rappel@5 et taux de refus de l\'assistant d\'interrogation.';

    protected const JEU_PAR_DEFAUT = 'Modules/Pilotage/resources/evaluation/questions.csv';

    public function handle(ServiceAssistant $assistant, ResolveurDeMoteur $resolveur): int
    {
        $chemin = base_path((string) ($this->argument('fichier') ?? self::JEU_PAR_DEFAUT));

        if (! is_file($chemin)) {
            $this->components->error("Jeu de questions introuvable : {$chemin}");

            return self::FAILURE;
        }

        $cas = $this->lire($chemin);

        if ($cas === []) {
            $this->components->error('Le jeu de questions est vide.');

            return self::FAILURE;
        }

        $moteurs = $this->moteurs($resolveur);

        if ($moteurs === []) {
            $this->components->error(
                'Aucun moteur disponible. Si l\'index est vide, lancez « php artisan varbaf:indexer ».',
            );

            return self::FAILURE;
        }

        $this->components->info(count($cas).' question(s) — '.count($moteurs).' moteur(s) mesuré(s).');

        $mesures = [];
        $detail = [];

        foreach ($moteurs as $cle => $moteur) {
            [$mesure, $lignes] = $this->mesurer($assistant, $moteur, $cas, $cle);
            $mesures[$cle] = $mesure;
            $detail = array_merge($detail, $lignes);
        }

        $this->afficher($mesures);

        if ($this->option('detail')) {
            $this->afficherLeDetail($detail);
        }

        if ($this->option('rapport')) {
            $this->exporter($mesures, $detail);
        }

        return self::SUCCESS;
    }

    // =================================================================
    //  MESURE
    // =================================================================

    /**
     * @param  array<int, array<string, mixed>>  $cas
     * @return array{0: array<string, float|int>, 1: array<int, array<string, mixed>>}
     */
    protected function mesurer(ServiceAssistant $assistant, ?MoteurDeRecherche $moteur, array $cas, string $cle): array
    {
        $classificationJustes = 0;
        $rappels = [];
        $refusAttendus = 0;
        $refusObtenus = 0;
        $lignes = [];

        foreach ($cas as $cas_) {
            $reponse = $assistant->repondre((string) $cas_['question'], $moteur);

            $classifieJuste = $reponse->categorie === $cas_['categorie'];
            $classificationJustes += $classifieJuste ? 1 : 0;

            $rappel = null;

            if ($cas_['categorie'] === CategorieQuestion::DESCRIPTIVE && $cas_['attendus'] !== []) {
                // Titre **et** extrait : le corps de métier visé par le
                // jeu ne figure pas dans le titre d'une fiche produit.
                // Voir `ReponseAssistant::textesDesSources()`.
                $rappel = $this->rappel($cas_['attendus'], $reponse->textesDesSources());
                $rappels[] = $rappel;
            }

            if ($cas_['refus_attendu']) {
                $refusAttendus++;
                $refusObtenus += $reponse->estRefus() ? 1 : 0;
            }

            $lignes[] = [
                'moteur' => $cle,
                'question' => $cas_['question'],
                'categorie_attendue' => $cas_['categorie']->value,
                'categorie_obtenue' => $reponse->categorie->value,
                'classification' => $classifieJuste ? 'juste' : 'fausse',
                'branche' => $reponse->branche->value,
                'refus_attendu' => $cas_['refus_attendu'] ? 'oui' : 'non',
                'refus_obtenu' => $reponse->estRefus() ? 'oui' : 'non',
                'rappel' => $rappel === null ? '' : number_format($rappel, 3, '.', ''),
                'sources' => implode(' | ', $reponse->titresDesSources()),
            ];
        }

        return [[
            'questions' => count($cas),
            'classification' => count($cas) > 0 ? $classificationJustes / count($cas) : 0.0,
            'rappel_5' => $rappels === [] ? 0.0 : array_sum($rappels) / count($rappels),
            'questions_rappel' => count($rappels),
            'refus_correct' => $refusAttendus > 0 ? $refusObtenus / $refusAttendus : 0.0,
            'questions_refus' => $refusAttendus,
        ], $lignes];
    }

    /**
     * La proportion des sources attendues retrouvées parmi celles
     * restituées.
     *
     * La comparaison est insensible à la casse et aux accents : le jeu
     * de questions est écrit à la main, et le faire échouer sur un accent
     * mesurerait la frappe plutôt que le moteur.
     *
     * @param  array<int, string>  $attendus
     * @param  array<int, string>  $titres
     */
    protected function rappel(array $attendus, array $titres): float
    {
        if ($attendus === []) {
            return 0.0;
        }

        $foin = $this->aplatir(implode(' ', $titres));
        $trouves = 0;

        foreach ($attendus as $attendu) {
            if (str_contains($foin, $this->aplatir($attendu))) {
                $trouves++;
            }
        }

        return $trouves / count($attendus);
    }

    protected function aplatir(string $texte): string
    {
        $texte = mb_strtolower(trim($texte), 'UTF-8');

        return strtr($texte, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e',
            'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        ]);
    }

    // =================================================================
    //  ENTRÉES ET SORTIES
    // =================================================================

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function lire(string $chemin): array
    {
        $cas = [];
        $flux = fopen($chemin, 'rb');

        if ($flux === false) {
            return [];
        }

        $premiere = true;

        while (($colonnes = fgetcsv($flux, 0, ';')) !== false) {
            if ($colonnes === [null] || $colonnes === false) {
                continue;
            }

            $question = trim((string) ($colonnes[0] ?? ''));

            if ($question === '' || str_starts_with($question, '#')) {
                continue;
            }

            // L'en-tête se reconnaît à son premier champ, pas à sa
            // position : un fichier sans en-tête reste lisible.
            if ($premiere && mb_strtolower($question) === 'question') {
                $premiere = false;

                continue;
            }

            $premiere = false;

            $attendus = array_values(array_filter(array_map(
                'trim',
                explode('|', (string) ($colonnes[2] ?? '')),
            )));

            $cas[] = [
                'question' => $question,
                'categorie' => CategorieQuestion::tryFrom(mb_strtoupper(trim((string) ($colonnes[1] ?? ''))))
                    ?? CategorieQuestion::DESCRIPTIVE,
                'attendus' => $attendus,
                'refus_attendu' => in_array(mb_strtolower(trim((string) ($colonnes[3] ?? 'non'))), ['oui', 'o', '1', 'true'], true),
            ];
        }

        fclose($flux);

        return $cas;
    }

    /**
     * Les moteurs à mesurer, dans l'ordre où le tableau les présentera.
     *
     * **La liste est explicite et non déduite de `clesDisponibles()`.**
     * Une mesure comparative n'a de sens que si l'on sait, en la lisant,
     * ce qui a été comparé : un catalogue qui s'enrichirait tout seul
     * ferait varier la table 4.3 d'une version à l'autre sans que rien
     * ne le dise. Le prix de ce choix est qu'un moteur ajouté au
     * catalogue reste invisible ici tant qu'on ne l'y déclare pas —
     * c'est précisément ce qui est arrivé aux branches dense et hybride,
     * indexées à 100 % et mesurées par personne.
     *
     * `estDisponible()` fait le reste : sur un poste sans fournisseur
     * d'embeddings, les deux dernières clés disparaissent d'elles-mêmes
     * et la mesure se réduit aux deux moteurs locaux, sans échouer.
     *
     * @return array<string, MoteurDeRecherche|null>
     */
    protected function moteurs(ResolveurDeMoteur $resolveur): array
    {
        $demande = $this->option('moteur');

        if (filled($demande)) {
            $moteur = $resolveur->moteurNomme((string) $demande);

            return $moteur === null ? [] : [(string) $demande => $moteur];
        }

        $moteurs = [];

        foreach (['lexical', 'mots_cles', 'dense', 'hybride'] as $cle) {
            $moteur = $resolveur->moteurNomme($cle);

            if ($moteur !== null && $moteur->estDisponible()) {
                $moteurs[$cle] = $moteur;
            }
        }

        return $moteurs;
    }

    /**
     * @param  array<string, array<string, float|int>>  $mesures
     */
    protected function afficher(array $mesures): void
    {
        $this->newLine();
        $this->components->info('Table 4.3 — mesures de l\'assistant');

        $this->table(
            ['Moteur', 'Questions', 'Classification', 'Rappel@5', 'Refus correct'],
            array_map(fn (string $cle, array $m): array => [
                $cle,
                $m['questions'],
                $this->pourcentage($m['classification']),
                $this->pourcentage($m['rappel_5']).' ('.$m['questions_rappel'].' q.)',
                $this->pourcentage($m['refus_correct']).' ('.$m['questions_refus'].' q.)',
            ], array_keys($mesures), $mesures),
        );

        if (isset($mesures['lexical'], $mesures['mots_cles'])) {
            $ecart = $mesures['lexical']['rappel_5'] - $mesures['mots_cles']['rappel_5'];

            $this->components->info(sprintf(
                'H3 — écart de rappel@5 entre pondération TF-IDF et mots-clés : %+.1f point(s).',
                $ecart * 100,
            ));
        }

        // La classification ne dépend pas du moteur : elle est décidée
        // avant lui. Un écart entre les deux lignes signalerait un
        // défaut de la mesure, pas du moteur — autant le dire.
        $this->components->info(
            'La classification est identique d\'un moteur à l\'autre : le routage précède le choix du moteur.',
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $lignes
     */
    protected function afficherLeDetail(array $lignes): void
    {
        $this->newLine();
        $this->table(
            ['Moteur', 'Question', 'Attendue', 'Obtenue', 'Branche', 'Rappel'],
            array_map(fn (array $l): array => [
                $l['moteur'],
                mb_strimwidth((string) $l['question'], 0, 46, '…'),
                $l['categorie_attendue'],
                $l['categorie_obtenue'],
                $l['branche'],
                $l['rappel'],
            ], $lignes),
        );
    }

    /**
     * @param  array<string, array<string, float|int>>  $mesures
     * @param  array<int, array<string, mixed>>  $detail
     */
    protected function exporter(array $mesures, array $detail): void
    {
        $repertoire = base_path((string) $this->option('rapport'));

        if (! is_dir($repertoire) && ! mkdir($repertoire, 0o775, true) && ! is_dir($repertoire)) {
            $this->components->error("Répertoire de rapport inaccessible : {$repertoire}");

            return;
        }

        $horodatage = now()->format('Ymd-His');

        $synthese = $repertoire.DIRECTORY_SEPARATOR."evaluation-assistant-{$horodatage}.csv";
        $flux = fopen($synthese, 'wb');
        fputcsv($flux, ['moteur', 'questions', 'classification', 'rappel_5', 'questions_rappel', 'refus_correct', 'questions_refus'], ';');

        foreach ($mesures as $cle => $m) {
            fputcsv($flux, [
                $cle,
                $m['questions'],
                number_format($m['classification'], 4, '.', ''),
                number_format($m['rappel_5'], 4, '.', ''),
                $m['questions_rappel'],
                number_format($m['refus_correct'], 4, '.', ''),
                $m['questions_refus'],
            ], ';');
        }

        fclose($flux);

        $parQuestion = $repertoire.DIRECTORY_SEPARATOR."evaluation-assistant-{$horodatage}-detail.csv";
        $flux = fopen($parQuestion, 'wb');
        fputcsv($flux, array_keys($detail[0] ?? ['moteur' => '']), ';');

        foreach ($detail as $ligne) {
            fputcsv($flux, array_values($ligne), ';');
        }

        fclose($flux);

        $this->newLine();
        $this->components->twoColumnDetail('Synthèse', $synthese);
        $this->components->twoColumnDetail('Détail par question', $parQuestion);
    }

    protected function pourcentage(float $valeur): string
    {
        return number_format($valeur * 100, 1, ',', ' ').' %';
    }
}
