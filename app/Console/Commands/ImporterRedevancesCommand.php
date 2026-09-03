<?php

namespace App\Console\Commands;

use App\Import\ServiceImportRedevances;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Modules\Socle\Models\JournalAudit;
use Modules\Socle\Models\Utilisateur;
use Modules\Tresorerie\Exceptions\SectionCaisseException;
use RuntimeException;

/**
 * Reprend au brouillard de caisse les redevances déjà encaissées avant
 * l'existence du système — voir `ServiceImportRedevances` pour le
 * motif complet.
 */
class ImporterRedevancesCommand extends Command
{
    protected $signature = 'varbaf:importer-redevances
        {fichier? : Chemin du relevé de recouvrement CSV, relatif à la racine du projet}
        {--compte=admin@varbaf.local : Compte au nom duquel la reprise écrit}';

    protected $description = "Reprend au brouillard de caisse les redevances deja encaissees, telles que le releve de recouvrement les porte.";

    protected const FICHIER_PAR_DEFAUT = 'docs/donnees/parc-locatif.csv';

    public function handle(ServiceImportRedevances $service): int
    {
        $chemin = $this->chemin();

        try {
            $this->authentifier();

            $rapport = $service->importer($chemin);
        } catch (RuntimeException|SectionCaisseException $erreur) {
            $this->components->error($erreur->getMessage());

            return self::FAILURE;
        }

        $this->afficher($rapport);
        $this->auditer($rapport, $chemin);

        return self::SUCCESS;
    }

    protected function authentifier(): void
    {
        $identifiant = (string) $this->option('compte');

        $utilisateur = Utilisateur::query()
            ->where('email', $identifiant)
            ->where('actif', true)
            ->first();

        if (! $utilisateur || ! $utilisateur->agent) {
            throw new RuntimeException("Aucun compte actif avec agent pour « {$identifiant} ».");
        }

        Auth::login($utilisateur);

        $this->components->twoColumnDetail('Compte', $utilisateur->email);
    }

    protected function chemin(): string
    {
        $fichier = (string) ($this->argument('fichier') ?? self::FICHIER_PAR_DEFAUT);

        $absolu = str_starts_with($fichier, DIRECTORY_SEPARATOR)
            || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $fichier);

        return $absolu ? $fichier : base_path($fichier);
    }

    /**
     * @param  array<string, mixed>  $rapport
     */
    protected function afficher(array $rapport): void
    {
        $this->components->info('Reprise des redevances encaissées');

        $this->table(['Indicateur', 'Valeur'], [
            ['Lignes du relevé traitées', (string) $rapport['lignes']],
            ['Déjà reprises lors d\'un passage antérieur', (string) $rapport['deja_repris']],
            ['Sans paiement enregistré', (string) $rapport['sans_paiement']],
            ['Encaissements créés', (string) $rapport['encaissements_crees']],
            ['Montant total repris', number_format($rapport['montant_total'], 0, ',', ' ').' F'],
            ['dont sans attribution pour les recevoir', (string) $rapport['orphelins']],
        ]);

        if ($rapport['ecarts_paye'] !== []) {
            $this->newLine();
            $this->components->warn(
                'Le relevé se contredit lui-même sur ces occupants : la synthèse annuelle et le détail '
                .'mensuel ne portent pas le même total. Le détail mensuel est retenu (voir '
                .'docs/donnees/README.md) ; à faire trancher par la coordination.'
            );
            $this->table(
                ['Occupant', 'Synthèse annuelle', 'Détail mensuel'],
                array_map(
                    fn (array $e) => [$e['occupant'], number_format($e['paye_2026'], 0, ',', ' ').' F', number_format($e['paye_mensuel_2026'], 0, ',', ' ').' F'],
                    $rapport['ecarts_paye'],
                ),
            );
        }

        if ($rapport['espaces_introuvables'] !== []) {
            $this->newLine();
            $this->components->error('Codes d\'espace absents du parc réel :');
            $this->table(['Code'], array_map(fn ($c) => [$c], $rapport['espaces_introuvables']));
        }
    }

    /**
     * @param  array<string, mixed>  $rapport
     */
    protected function auditer(array $rapport, string $chemin): void
    {
        JournalAudit::enregistrer(
            action: 'IMPORT_REDEVANCES',
            module: 'TRESORERIE',
            entite: 'ReleveDeRecouvrement',
            entiteId: null,
            donnees: [
                'fichier' => basename($chemin),
                'lignes' => $rapport['lignes'],
                'encaissements_crees' => $rapport['encaissements_crees'],
                'montant_total' => $rapport['montant_total'],
            ],
        );
    }
}
