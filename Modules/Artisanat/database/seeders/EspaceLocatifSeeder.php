<?php

namespace Modules\Artisanat\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\EspaceLocatif;
use Modules\Socle\Models\VillageArtisanal;

/**
 * Découpage initial du parc en espaces locatifs.
 *
 * **Un espace par boutique, et pas davantage.** C'est la seule chose que
 * l'on sache avec certitude : tout local abrite au moins une place de
 * vente. Le nombre réel d'espaces par boutique varie — c'est même ce qui
 * a motivé toute la correction — mais il n'est pas dans les données
 * transcrites. Inventer un découpage produirait des B0102 qui
 * n'existent pas et fausserait le taux d'occupation dans l'autre sens.
 *
 * La coordination ajoute les espaces manquants depuis l'écran dédié : le
 * code se compose tout seul à partir de la boutique.
 *
 * Idempotent : rejouable après ajout d'espaces sans en recréer ni en
 * écraser aucun.
 */
class EspaceLocatifSeeder extends Seeder
{
    public function run(): void
    {
        $village = VillageArtisanal::where('code', 'VARBAF')->first();

        if (! $village) {
            $this->command?->warn('Village VARBAF introuvable : le seeder du Socle doit passer avant celui de l\'Artisanat.');

            return;
        }

        $boutiques = Boutique::query()
            ->where('village_id', $village->id)
            ->orderBy('numero')
            ->get();

        foreach ($boutiques as $boutique) {
            if ($boutique->espacesLocatifs()->exists()) {
                continue;
            }

            EspaceLocatif::create(['boutique_id' => $boutique->getKey()]);
        }

        $total = EspaceLocatif::query()
            ->whereIn('boutique_id', $boutiques->modelKeys())
            ->count();

        $this->command?->info("{$total} espaces locatifs en place.");
        $this->command?->comment('Un espace par boutique : ajoutez les subdivisions réelles depuis l\'écran « Espaces locatifs ».');
    }
}
