<?php

namespace App\Console\Commands;

use App\Import\ServiceCompletionAttributions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Modules\Socle\Models\JournalAudit;
use Modules\Socle\Models\Utilisateur;
use RuntimeException;

/**
 * Complète le parc locatif pour les occupants que le registre des
 * ventes ne révèle pas — voir `ServiceCompletionAttributions` pour le
 * motif complet.
 */
class CompleterAttributionsCommand extends Command
{
    protected $signature = 'varbaf:completer-attributions
        {fichier? : Chemin du relevé de recouvrement CSV, relatif à la racine du projet}
        {--compte=admin@varbaf.local : Compte au nom duquel l\'écriture se fait}';

    protected $description = "Attribue un espace aux occupants du parc locatif absents du registre des ventes.";

    protected const FICHIER_PAR_DEFAUT = 'docs/donnees/parc-locatif.csv';

    public function handle(ServiceCompletionAttributions $service): int
    {
        $chemin = $this->chemin();

        try {
            $this->authentifier();

            $rapport = $service->completer($chemin);
        } catch (RuntimeException $erreur) {
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
        $this->components->info('Complétion du parc locatif');

        $this->table(['Indicateur', 'Valeur'], [
            ['Lignes du relevé traitées', (string) $rapport['lignes']],
            ['Espaces déjà attribués (registre) — laissés intacts', (string) $rapport['deja_attribues']],
            ['Attributions créées', (string) $rapport['attributions_creees']],
            ['Attributions sans redevance connue', (string) $rapport['attributions_sans_redevance']],
            ['Artisans créés', (string) $rapport['artisans_crees']],
            ['Artisans créés sans secteur d\'activité', (string) $rapport['artisans_sans_secteur']],
        ]);

        if ($rapport['occupants_sans_espace'] !== []) {
            $this->newLine();
            $this->components->warn(
                'Occupants du relevé sans code d\'espace : redevance connue, aucun contrat à créer.'
            );
            $this->table(['Occupant'], array_map(fn ($o) => [$o], $rapport['occupants_sans_espace']));
        }

        if ($rapport['espaces_introuvables'] !== []) {
            $this->newLine();
            $this->components->error(
                'Codes d\'espace du relevé absents du parc réel : à vérifier avant de relancer.'
            );
            $this->table(['Code'], array_map(fn ($c) => [$c], $rapport['espaces_introuvables']));
        }

        if ($rapport['attributions_creees'] > 0) {
            $this->newLine();
            $this->components->warn(
                "Les {$rapport['attributions_creees']} attribution(s) créée(s) portent le début de "
                .'l\'exercice courant comme date d\'entrée — le relevé de recouvrement ne porte aucune '
                .'date d\'entrée réelle pour ces occupants. À corriger depuis l\'écran dès qu\'elle est connue.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $rapport
     */
    protected function auditer(array $rapport, string $chemin): void
    {
        JournalAudit::enregistrer(
            action: 'COMPLETION_ATTRIBUTIONS',
            module: 'ARTISANAT',
            entite: 'ParcLocatif',
            entiteId: null,
            donnees: [
                'fichier' => basename($chemin),
                'lignes' => $rapport['lignes'],
                'attributions_creees' => $rapport['attributions_creees'],
                'artisans_crees' => $rapport['artisans_crees'],
            ],
        );
    }
}
