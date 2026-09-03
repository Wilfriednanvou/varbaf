<?php

namespace App\Console\Commands;

use App\Import\ImportImpossibleException;
use App\Import\RapportImport;
use App\Import\ServiceImportRegistre;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Modules\Socle\Models\JournalAudit;
use Modules\Socle\Models\Utilisateur;
use RuntimeException;

/**
 * Reprise du registre de ventes transcrit par la coordination.
 *
 * CLAUDE.md interdit les données d'amorçage fictives : le catalogue, les
 * artisans et les ventes du village doivent venir du registre réel. Ce
 * registre est un cahier tenu à la main puis transcrit — dates
 * partielles, noms orthographiés de trois façons, totaux qui ne tombent
 * pas juste. La commande le reprend tel qu'il est, sans le corriger, et
 * rend compte de tout ce qu'elle a dû supposer.
 *
 * **Elle n'écrit jamais en direct.** Dépôts, ventes, stock et caisse
 * passent par les services qui sont les points d'entrée uniques du
 * système. Voir `ServiceImportRegistre` pour le raisonnement.
 *
 * **Elle est relançable.** Chaque ligne du fichier laisse une empreinte
 * en base ; une ligne déjà reprise est comptée et sautée. Relancer après
 * un incident reprend là où l'on s'était arrêté, sans créer un seul
 * doublon.
 */
class ImporterRegistreCommand extends Command
{
    protected $signature = 'varbaf:importer
        {fichier? : Chemin du registre CSV, relatif à la racine du projet}
        {--compte=admin@varbaf.local : Compte au nom duquel la reprise écrit}
        {--seuil=85 : Similarité minimale, en pourcentage, pour rapprocher deux écritures d\'artisan}
        {--marge=10 : Largeur de la zone de doute sous le seuil, en points}
        {--rapport= : Répertoire où déposer le rapport CSV}
        {--sans-rapport : Affiche le rapport sans l\'exporter}';

    protected $description = 'Reprend le registre de ventes transcrit : espaces locatifs, artisans, produits, dépôts et ventes.';

    /**
     * Chemin du registre, quand la commande est appelée sans argument.
     */
    protected const REGISTRE_PAR_DEFAUT = 'docs/donnees/registre.csv';

    public function handle(ServiceImportRegistre $import): int
    {
        $chemin = $this->chemin();
        $seuil = (float) $this->option('seuil');
        $marge = (float) $this->option('marge');

        $this->components->info("Reprise du registre : {$chemin}");

        try {
            $this->authentifier();

            $barre = $this->output->createProgressBar();
            $barre->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %elapsed:6s%');
            $demarree = false;

            $rapport = $import->importer(
                chemin: $chemin,
                seuil: $seuil,
                marge: $marge,
                progression: function (int $rang, int $total) use ($barre, &$demarree): void {
                    if (! $demarree) {
                        $barre->start($total);
                        $demarree = true;
                    }

                    $barre->setProgress($rang);
                },
            );

            if ($demarree) {
                $barre->finish();
                $this->newLine(2);
            }
        } catch (ImportImpossibleException $erreur) {
            $this->components->error($erreur->getMessage());

            return self::FAILURE;
        } catch (RuntimeException $erreur) {
            $this->components->error($erreur->getMessage());

            return self::FAILURE;
        }

        $this->afficher($rapport);
        $this->afficherExclusions($import);
        $this->exporter($rapport);
        $this->auditer($rapport, $chemin);

        return self::SUCCESS;
    }

    /**
     * Écritures que `rattachements.csv` a explicitement écartées —
     * ambiguïté réelle (« A ARBITRER ») ou pas un artisan (« NON
     * ARTISAN »). Elles ne figurent nulle part dans le rapport
     * d'import : le dire ici évite qu'un chiffre d'affaires reconstitué
     * ne se lise comme complet alors qu'il ne l'est délibérément pas.
     */
    protected function afficherExclusions(ServiceImportRegistre $import): void
    {
        $exclusions = $import->lecteur()->dernieresExclusions;

        if ($exclusions === []) {
            return;
        }

        $parDecision = [];

        foreach ($exclusions as $exclusion) {
            $decision = $exclusion['decision'];
            $parDecision[$decision] ??= ['lignes' => 0, 'montant' => 0];
            $parDecision[$decision]['lignes']++;
            $parDecision[$decision]['montant'] += (int) ($exclusion['montant'] ?: 0);
        }

        $this->newLine();
        $this->components->warn(
            'Écritures écartées par rattachements.csv, hors du rapport ci-dessus : '
            .'ambiguïté réelle ou écriture qui ne désigne pas un artisan.'
        );

        $this->table(
            ['Décision', 'Lignes', 'Montant'],
            array_map(
                fn (string $decision, array $c) => [$decision, (string) $c['lignes'], number_format($c['montant'], 0, ',', ' ').' F'],
                array_keys($parDecision),
                $parDecision,
            ),
        );
    }

    /**
     * Ouvre une session pour la durée de la reprise.
     *
     * Le vendeur d'une vente, le validateur d'un produit et l'auteur
     * d'un mouvement de stock sont **constatés** depuis le compte
     * connecté, jamais choisis (CLAUDE.md, « une trace se constate »).
     * Une commande n'a pas de session : il faut donc lui en donner une,
     * et que ce soit un compte réel du village. C'est aussi ce qui rend
     * la reprise lisible au journal d'audit — on sait qui l'a lancée.
     */
    protected function authentifier(): void
    {
        $identifiant = (string) $this->option('compte');

        $utilisateur = Utilisateur::query()
            ->where('email', $identifiant)
            ->where('actif', true)
            ->first();

        if (! $utilisateur || ! $utilisateur->agent) {
            throw ImportImpossibleException::sansCompte($identifiant);
        }

        Auth::login($utilisateur);

        $this->components->twoColumnDetail('Compte de reprise', $utilisateur->email);
    }

    protected function chemin(): string
    {
        $fichier = (string) ($this->argument('fichier') ?? self::REGISTRE_PAR_DEFAUT);

        // Un chemin absolu est pris tel quel : il arrive qu'on reprenne
        // un extrait déposé ailleurs. Sinon, il se lit depuis la racine
        // du projet, ce qui rend la commande indépendante du répertoire
        // depuis lequel elle est lancée.
        return $this->estAbsolu($fichier) ? $fichier : base_path($fichier);
    }

    protected function estAbsolu(string $chemin): bool
    {
        return str_starts_with($chemin, DIRECTORY_SEPARATOR)
            || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $chemin);
    }

    protected function afficher(RapportImport $rapport): void
    {
        $this->components->info('Rapport d\'import');

        $rubriqueCourante = null;
        $lignes = [];

        foreach ($rapport->indicateurs() as [$rubrique, $indicateur, $valeur, $part]) {
            if ($rubrique !== $rubriqueCourante) {
                $lignes[] = new \Symfony\Component\Console\Helper\TableSeparator;
                $rubriqueCourante = $rubrique;
            }

            $lignes[] = [$rubrique, $indicateur, $valeur, $part];
        }

        // La première séparation est superflue : elle doublerait le
        // trait de l'en-tête.
        array_shift($lignes);

        $this->table(['Rubrique', 'Indicateur', 'Valeur', 'Part'], $lignes);

        $this->afficherHorsParc($rapport);
        $this->afficherDoutes($rapport);
    }

    protected function afficherHorsParc(RapportImport $rapport): void
    {
        $horsParc = $rapport->horsParc();

        if ($horsParc === []) {
            return;
        }

        $this->newLine();
        $this->components->warn(
            'Emplacements hors du parc des dix-sept boutiques, rattachés à la boutique technique « '
            .ServiceImportRegistre::BOUTIQUE_TECHNIQUE.' » : '
            .'ils ont produit des ventes réelles mais ne sont pas des locaux attribués.'
        );

        $this->table(
            ['Code au registre', 'Espace locatif créé'],
            array_map(fn (array $entree) => [$entree['code'], $entree['espace']], $horsParc),
        );
    }

    protected function afficherDoutes(RapportImport $rapport): void
    {
        $doutes = $rapport->doutes();

        if ($doutes === []) {
            return;
        }

        $this->newLine();
        $this->components->warn(
            'Écritures d\'artisans laissées distinctes malgré une ressemblance proche du seuil de '
            .$rapport->seuilLisible().'. Elles ne sont pas rapprochées : à trancher avec la coordination.'
        );

        $this->table(
            ['Écriture', 'Rapprochement possible', 'Similarité'],
            array_map(
                fn (array $doute) => [
                    $doute['nom'],
                    $doute['candidat'],
                    number_format($doute['score'], 1, ',', ' ').' %',
                ],
                $doutes,
            ),
        );
    }

    protected function exporter(RapportImport $rapport): void
    {
        if ($this->option('sans-rapport')) {
            return;
        }

        $repertoire = (string) ($this->option('rapport') ?: storage_path('app/imports'));

        [$synthese, $detail] = $rapport->exporter($repertoire);

        $this->newLine();
        $this->components->twoColumnDetail('Synthèse', $synthese);
        $this->components->twoColumnDetail('Lignes signalées', $detail);
    }

    /**
     * La reprise est une action métier sensible : elle crée des ventes,
     * des attributions et des écritures de caisse. Elle laisse donc une
     * trace au journal d'audit, au même titre qu'une saisie à l'écran.
     */
    protected function auditer(RapportImport $rapport, string $chemin): void
    {
        JournalAudit::enregistrer(
            action: 'IMPORT_REGISTRE',
            module: 'COMMERCE',
            entite: 'RegistreDesVentes',
            entiteId: null,
            donnees: [
                'fichier' => basename($chemin),
                'lignes_traitees' => $rapport->valeur(RapportImport::LIGNES_TRAITEES),
                'lignes_importees' => $rapport->valeur(RapportImport::LIGNES_IMPORTEES),
                'lignes_signalees' => $rapport->valeur(RapportImport::LIGNES_SIGNALEES),
                'lignes_non_importees' => $rapport->valeur(RapportImport::LIGNES_NON_IMPORTEES),
                'lignes_deja_reprises' => $rapport->valeur(RapportImport::LIGNES_DEJA_REPRISES),
                'seuil_rapprochement' => $rapport->seuil,
            ],
        );
    }
}
