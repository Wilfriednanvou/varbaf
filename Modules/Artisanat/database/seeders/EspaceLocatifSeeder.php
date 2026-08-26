<?php

namespace Modules\Artisanat\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\EspaceLocatif;
use Modules\Socle\Models\VillageArtisanal;

/**
 * Découpage du parc en espaces locatifs, repris de l'état de
 * recouvrement des redevances 2026.
 *
 * **Ce que la version précédente posait, et pourquoi c'était faux.**
 * Elle créait un espace par boutique, faute de mieux : le registre de
 * ventes transcrit ne portait pas le découpage réel, et inventer une
 * répartition aurait produit des B0102 inexistants. Le raisonnement
 * était juste, la donnée manquait simplement. Elle existe désormais —
 * l'état de recouvrement nomme trente-six espaces, code par code, avec
 * leur occupant et leur redevance.
 *
 * **Les codes viennent du relevé, ils ne sont pas dérivés.** La règle
 * reste que le code se compose du numéro du contenant et d'un rang, et
 * `EspaceLocatif::genererCode()` continue de l'appliquer aux espaces
 * créés depuis l'écran. Mais le sous-sol SS01 abrite G0201 et SS02
 * abrite G0202 : ces codes-là figurent sur des contrats signés, et les
 * renommer pour les faire rentrer dans la règle romprait le lien avec
 * le papier. Le seeder les pose donc explicitement — le crochet
 * `creating` ne dérive un code que lorsqu'il en manque un.
 *
 * **B02 saute B0205, B12 saute B1202.** Ce ne sont pas des oublis de
 * transcription : la feuille numérote ainsi. Un espace peut avoir été
 * fusionné avec son voisin ou retiré de la location ; le rang reste
 * porté par le code, qui est figé.
 *
 * **B13 et B17 n'ont aucun espace ici.** Une feuille de recouvrement ne
 * mentionne que ce qui se facture. Les deux locaux existent au relevé
 * physique du bâtiment, leur découpage est simplement inconnu : mieux
 * vaut un local sans espace, visible comme tel, qu'un espace inventé
 * qui fausserait le taux d'occupation dans l'autre sens.
 *
 * Idempotent : rejouable sans recréer ni écraser aucun espace.
 */
class EspaceLocatifSeeder extends Seeder
{
    /**
     * Le parc réel, contenant par contenant.
     *
     * @var array<string, array<int, string>>
     */
    protected const PARC = [
        'B01' => ['B0101', 'B0102', 'B0103', 'B0104', 'B0105', 'B0106', 'B0107'],
        'B02' => ['B0201', 'B0202', 'B0203', 'B0204', 'B0206'],
        'B03' => ['B0301'],
        'B04' => ['B0401', 'B0402'],
        'B05' => ['B0501'],
        'B06' => ['B0601', 'B0602', 'B0603', 'B0604', 'B0605'],
        'B07' => ['B0701', 'B0702'],
        'B08' => ['B0801'],
        'B09' => ['B0901'],
        'B10' => ['B1001'],
        'B11' => ['B1101'],
        'B12' => ['B1201', 'B1203', 'B1204'],
        'B14' => ['B1401'],
        'B15' => ['B1501'],
        'B16' => ['B1601'],
        'SS01' => ['G0201'],
        'SS02' => ['G0202'],
        'EV01' => ['EV0101'],
    ];

    public function run(): void
    {
        $village = VillageArtisanal::where('code', 'VARBAF')->first();

        if (! $village) {
            $this->command?->warn('Village VARBAF introuvable : le seeder du Socle doit passer avant celui de l\'Artisanat.');

            return;
        }

        $contenants = Boutique::query()
            ->where('village_id', $village->id)
            ->pluck('id', 'numero');

        $poses = 0;
        $inconnus = [];

        foreach (self::PARC as $numero => $codes) {
            $contenantId = $contenants[$numero] ?? null;

            if ($contenantId === null) {
                $inconnus[] = $numero;

                continue;
            }

            foreach ($codes as $code) {
                $existe = EspaceLocatif::query()
                    ->where('boutique_id', $contenantId)
                    ->where('code', $code)
                    ->exists();

                if ($existe) {
                    continue;
                }

                // `code` est hors de `$fillable` : il se pose comme
                // attribut, jamais par affectation de masse.
                $espace = new EspaceLocatif(['boutique_id' => $contenantId]);
                $espace->code = $code;
                $espace->save();

                $poses++;
            }
        }

        $total = EspaceLocatif::query()
            ->whereIn('boutique_id', $contenants->values())
            ->count();

        $this->command?->info("{$total} espaces locatifs en place ({$poses} posés à ce passage).");

        if ($inconnus !== []) {
            $this->command?->warn('Contenants absents du parc : '.implode(', ', $inconnus).'. Le seeder des boutiques doit passer avant.');
        }

        $sansEspace = $contenants->keys()
            ->reject(fn (string $numero) => array_key_exists($numero, self::PARC))
            ->values();

        if ($sansEspace->isNotEmpty()) {
            $this->command?->comment(
                'Sans espace locatif connu : '.$sansEspace->implode(', ')
                .'. Absents de l\'état de recouvrement, découpage à relever sur place.'
            );
        }
    }
}
