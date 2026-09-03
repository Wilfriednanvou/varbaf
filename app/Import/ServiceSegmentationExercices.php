<?php

namespace App\Import;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Artisanat\Enums\StatutParticipationArtisan;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\ArtisanExercice;
use Modules\Commerce\Enums\StatutParticipationProduit;
use Modules\Commerce\Models\LigneVente;
use Modules\Commerce\Models\Produit;
use Modules\Commerce\Models\ProduitExercice;
use Modules\Commerce\Models\Vente;
use Modules\Socle\Models\Exercice;
use RuntimeException;

/**
 * Segmente en 2024/2025/2026 les données déjà en base, importées sous
 * un exercice unique avant que la gestion multi-exercice n'existe.
 *
 * **Pourquoi ceci contourne délibérément le figement.** `Vente` et
 * `Depot` interdisent toute modification après enregistrement — RG-10
 * et son équivalent sur le dépôt, la garantie qui rend un reçu fiable
 * dans deux ans. Réaffecter `exercice_id` à une année plus juste n'est
 * pas une réécriture de ce que la vente a été : le montant, la date,
 * l'artisan, le produit ne bougent pas. C'est une correction d'une
 * classification posée avant que l'exercice qui convenait n'existe.
 * Elle passe donc par une écriture SQL directe, hors du modèle, une
 * seule fois, tracée au journal d'audit — jamais par un chemin que
 * l'application emprunterait en fonctionnement normal.
 *
 * **Les années, pas les dates de bornage.** `Exercice.date_debut`/
 * `date_fin` restent l'année civile conventionnelle : la date de fin
 * réelle d'un exercice est celle de sa clôture officielle, elle ne se
 * décide pas à l'avance ni ne se déduit des données. Seule la
 * classification — quelle vente appartient à quel exercice — se fonde
 * sur les dates réelles du registre.
 *
 * **Les participations sont reconstruites, pas réaffectées.** Un
 * artisan qui n'a vendu qu'en 2025 avait, avant cette classe, une seule
 * ligne `artisan_exercices` posée sur 2026 par le crochet de création
 * — exacte au moment où il a été créé, fausse maintenant qu'un exercice
 * 2025 existe. Sa participation est donc entièrement reconstruite à
 * partir de ses ventes réelles, année par année. Les artisans sans
 * aucune vente (le parc complété par `varbaf:completer-attributions`)
 * ne sont pas touchés : rien dans les données ne dit d'eux autre chose
 * que ce qui est déjà en base.
 *
 * **Ce que cette classe ne corrige pas.** Les mouvements de caisse et
 * les sections de caisse restent sur l'exercice où ils ont été écrits
 * (2026, au moment de l'import) : leur immuabilité et leur numérotation
 * par section rendent tout déplacement impossible sans réécrire un
 * historique qui n'a jamais existé. Voir `docs/dette-technique.md`.
 */
class ServiceSegmentationExercices
{
    /** @var array<int, int> annee => exercice_id */
    protected array $exercices = [];

    /**
     * @return array<string, mixed>
     */
    public function segmenter(): array
    {
        $courant = Exercice::courant();

        if (! $courant) {
            throw new RuntimeException('Aucun exercice en cours.');
        }

        $rapport = [
            'exercices_crees' => [],
            'ventes_reaffectees' => ['2024' => 0, '2025' => 0, '2026' => 0],
            'depots_reaffectes' => ['2024' => 0, '2025' => 0, '2026' => 0],
            'attributions_reaffectees' => ['2024' => 0, '2025' => 0, '2026' => 0],
            'artisans_reconstruits' => 0,
            'produits_reconstruits' => 0,
            'exercices_clotures' => [],
        ];

        DB::transaction(function () use ($courant, &$rapport): void {
            $this->resoudreExercices($courant, $rapport);
            $this->reaffecterVentesEtDepots($rapport);
            $this->reaffecterAttributions($rapport);
            $this->reconstruireParticipations($rapport);
            $this->clotureLesExercicesHistoriques($rapport);
        });

        return $rapport;
    }

    /**
     * @param  array<string, mixed>  $rapport
     */
    protected function resoudreExercices(Exercice $courant, array &$rapport): void
    {
        foreach ([2024, 2025] as $annee) {
            $exercice = Exercice::query()
                ->where('village_id', $courant->village_id)
                ->where('libelle', (string) $annee)
                ->first();

            if (! $exercice) {
                $exercice = Exercice::create([
                    'libelle' => (string) $annee,
                    'date_debut' => "{$annee}-01-01",
                    'date_fin' => "{$annee}-12-31",
                    'village_id' => $courant->village_id,
                ]);

                $rapport['exercices_crees'][] = $annee;
            }

            $this->exercices[$annee] = $exercice->getKey();
        }

        $this->exercices[(int) $courant->libelle] = $courant->getKey();
    }

    /**
     * @param  array<string, mixed>  $rapport
     */
    protected function reaffecterVentesEtDepots(array &$rapport): void
    {
        foreach ($this->exercices as $annee => $exerciceId) {
            $rapport['ventes_reaffectees'][(string) $annee] = DB::table('ventes')
                ->whereRaw('extract(year from date_vente) = ?', [$annee])
                ->where('exercice_id', '!=', $exerciceId)
                ->update(['exercice_id' => $exerciceId]);

            $rapport['depots_reaffectes'][(string) $annee] = DB::table('depots')
                ->whereRaw('extract(year from date_depot) = ?', [$annee])
                ->where('exercice_id', '!=', $exerciceId)
                ->update(['exercice_id' => $exerciceId]);
        }
    }

    /**
     * @param  array<string, mixed>  $rapport
     */
    protected function reaffecterAttributions(array &$rapport): void
    {
        foreach ($this->exercices as $annee => $exerciceId) {
            $rapport['attributions_reaffectees'][(string) $annee] = DB::table('attributions_espaces')
                ->whereRaw('extract(year from date_debut) = ?', [$annee])
                ->where('exercice_id', '!=', $exerciceId)
                ->update(['exercice_id' => $exerciceId]);
        }
    }

    /**
     * @param  array<string, mixed>  $rapport
     */
    protected function reconstruireParticipations(array &$rapport): void
    {
        // Artisans : uniquement ceux qui ont au moins une vente reelle —
        // les autres (parc complete sans historique de vente) gardent
        // leur unique participation telle quelle.
        $anneesParArtisan = Vente::query()
            ->selectRaw('artisan_id, extract(year from date_vente) as annee, min(date_vente) as premiere')
            ->groupBy('artisan_id', DB::raw('extract(year from date_vente)'))
            ->get()
            ->groupBy('artisan_id');

        foreach ($anneesParArtisan as $artisanId => $lignes) {
            if (! Artisan::whereKey($artisanId)->exists()) {
                continue;
            }

            ArtisanExercice::where('artisan_id', $artisanId)->delete();

            foreach ($lignes as $ligne) {
                $exerciceId = $this->exercices[(int) $ligne->annee] ?? null;

                if ($exerciceId === null) {
                    continue;
                }

                ArtisanExercice::create([
                    'artisan_id' => $artisanId,
                    'exercice_id' => $exerciceId,
                    'statut' => StatutParticipationArtisan::ACTIF,
                    'date_activation' => Carbon::parse($ligne->premiere)->toDateString(),
                ]);
            }

            $rapport['artisans_reconstruits']++;
        }

        // Produits : meme principe, l'annee vient de la vente qui les
        // porte (lignes_vente -> ventes), pas d'une date propre au
        // produit — il n'en a pas.
        $anneesParProduit = LigneVente::query()
            ->join('ventes', 'ventes.id', '=', 'lignes_vente.vente_id')
            ->selectRaw('lignes_vente.produit_id, extract(year from ventes.date_vente) as annee, min(ventes.date_vente) as premiere')
            ->groupBy('lignes_vente.produit_id', DB::raw('extract(year from ventes.date_vente)'))
            ->get()
            ->groupBy('produit_id');

        foreach ($anneesParProduit as $produitId => $lignes) {
            if (! Produit::whereKey($produitId)->exists()) {
                continue;
            }

            ProduitExercice::where('produit_id', $produitId)->delete();

            foreach ($lignes as $ligne) {
                $exerciceId = $this->exercices[(int) $ligne->annee] ?? null;

                if ($exerciceId === null) {
                    continue;
                }

                ProduitExercice::create([
                    'produit_id' => $produitId,
                    'exercice_id' => $exerciceId,
                    'statut' => StatutParticipationProduit::ACTIF,
                ]);
            }

            $rapport['produits_reconstruits']++;
        }
    }

    /**
     * @param  array<string, mixed>  $rapport
     */
    protected function clotureLesExercicesHistoriques(array &$rapport): void
    {
        foreach ([2024, 2025] as $annee) {
            $exercice = Exercice::find($this->exercices[$annee]);

            if ($exercice && ! $exercice->cloture) {
                $exercice->cloturer();
                $rapport['exercices_clotures'][] = $annee;
            }
        }
    }
}
