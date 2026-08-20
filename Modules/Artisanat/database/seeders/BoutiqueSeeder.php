<?php

namespace Modules\Artisanat\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Artisanat\Enums\EtatBoutique;
use Modules\Artisanat\Models\Boutique;
use Modules\Socle\Models\VillageArtisanal;

/**
 * Parc de 24 boutiques du Village Artisanal Régional de Bafoussam.
 *
 * Seules les données structurelles connues sont posées : numéro et
 * emplacement. La superficie et la redevance de référence restent
 * nulles — CLAUDE.md interdit les données fictives, et ces montants
 * doivent être repris de la liste réelle des redevances détenue par la
 * coordination. Une redevance inventée se retrouverait ensuite dans les
 * échéanciers et les statistiques, sans qu'on sache plus qu'elle était
 * fausse.
 *
 * Le seeder n'est pas lié à un village en dur : il alimente le village
 * de code VARBAF, et ne fait rien si le Socle n'a pas encore été semé.
 */
class BoutiqueSeeder extends Seeder
{
    /**
     * Répartition du parc dans le bâtiment.
     */
    protected const REPARTITION = [
        'Rez-de-chaussée' => [1, 8],
        'Étage' => [9, 16],
        'Sous-sol' => [17, 24],
    ];

    public function run(): void
    {
        $village = VillageArtisanal::where('code', 'VARBAF')->first();

        if (! $village) {
            $this->command?->warn('Village VARBAF introuvable : le seeder du Socle doit passer avant celui de l\'Artisanat.');

            return;
        }

        foreach (self::REPARTITION as $emplacement => [$premier, $dernier]) {
            for ($rang = $premier; $rang <= $dernier; $rang++) {
                $numero = 'B-'.str_pad((string) $rang, 2, '0', STR_PAD_LEFT);

                Boutique::updateOrCreate(
                    ['village_id' => $village->id, 'numero' => $numero],
                    [
                        'emplacement' => $emplacement,
                        // Laissées nulles à dessein : à renseigner
                        // depuis la liste réelle des redevances.
                        'superficie' => null,
                        'redevance_mensuelle' => null,
                        'etat' => EtatBoutique::DISPONIBLE,
                    ],
                );
            }
        }

        $total = Boutique::where('village_id', $village->id)->count();

        $this->command?->info("{$total} boutiques en place pour {$village->nom}.");
        $this->command?->comment('Superficies et redevances à renseigner depuis la liste réelle de la coordination.');
    }
}
