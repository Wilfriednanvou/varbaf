<?php

namespace App\Console\Commands;

use App\Import\ServiceSegmentationExercices;
use Illuminate\Console\Command;
use Modules\Socle\Models\JournalAudit;
use RuntimeException;

/**
 * Segmente en 2024/2025/2026 les données déjà en base — voir
 * `ServiceSegmentationExercices` pour le motif complet.
 */
class SegmenterExercicesCommand extends Command
{
    protected $signature = 'varbaf:segmenter-exercices';

    protected $description = "Segmente par annee reelle les ventes, depots, attributions et participations deja en base.";

    public function handle(ServiceSegmentationExercices $service): int
    {
        try {
            $rapport = $service->segmenter();
        } catch (RuntimeException $erreur) {
            $this->components->error($erreur->getMessage());

            return self::FAILURE;
        }

        $this->afficher($rapport);
        $this->auditer($rapport);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $rapport
     */
    protected function afficher(array $rapport): void
    {
        $this->components->info('Segmentation par exercice');

        if ($rapport['exercices_crees'] !== []) {
            $this->components->twoColumnDetail('Exercices créés', implode(', ', $rapport['exercices_crees']));
        }

        $lignes = [];

        foreach (['2024', '2025', '2026'] as $annee) {
            $lignes[] = [
                $annee,
                (string) $rapport['ventes_reaffectees'][$annee],
                (string) $rapport['depots_reaffectes'][$annee],
                (string) $rapport['attributions_reaffectees'][$annee],
            ];
        }

        $this->table(['Exercice', 'Ventes réaffectées', 'Dépôts réaffectés', 'Attributions réaffectées'], $lignes);

        $this->table(['Indicateur', 'Valeur'], [
            ['Artisans dont la participation a été reconstruite', (string) $rapport['artisans_reconstruits']],
            ['Produits dont la participation a été reconstruite', (string) $rapport['produits_reconstruits']],
            ['Exercices clôturés', implode(', ', $rapport['exercices_clotures']) ?: '—'],
        ]);

        $this->newLine();
        $this->components->warn(
            'Les mouvements de caisse et les sections de caisse restent sur l\'exercice où ils ont été '
            .'écrits (2026) — leur immuabilité l\'interdit. Voir docs/dette-technique.md.'
        );
    }

    /**
     * @param  array<string, mixed>  $rapport
     */
    protected function auditer(array $rapport): void
    {
        JournalAudit::enregistrer(
            action: 'SEGMENTATION_EXERCICES',
            module: 'SOCLE',
            entite: 'Exercice',
            entiteId: null,
            donnees: $rapport,
        );
    }
}
