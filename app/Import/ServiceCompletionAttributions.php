<?php

namespace App\Import;

use Illuminate\Support\Facades\Auth;
use Modules\Artisanat\Enums\StatutAttribution;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\AttributionEspace;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Artisanat\Models\EspaceLocatif;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\VillageArtisanal;
use RuntimeException;

/**
 * Complète le parc locatif au-delà de ce que le registre des ventes
 * révèle.
 *
 * **Pourquoi cette commande est distincte de `varbaf:importer`.**
 * L'import du registre ne crée une attribution que pour un artisan qui
 * a vendu quelque chose : c'est la vente qui atteste l'occupation. Un
 * occupant qui paie une redevance sans jamais être passé au comptoir —
 * la CNTC au sous-sol, la coopérative de menuisiers, l'entretien de
 * l'espace vert — n'a donc aucune vente pour en témoigner, et
 * `docs/donnees/parc-locatif.csv` est la seule source qui l'atteste.
 *
 * **Elle ne recouvre jamais ce que le registre a déjà posé.** Un espace
 * qui porte déjà une attribution active est laissé intact : c'est
 * l'occupation attestée par une vente réelle, avec sa date d'entrée
 * réelle, et cette commande ne la remplacerait que par une date
 * arbitraire.
 *
 * **La date d'entrée est une hypothèse, assumée comme telle.** Le
 * relevé de recouvrement ne porte aucune date d'entrée pour ces
 * occupants — seulement un dû annuel. Faute de mieux, l'attribution
 * prend pour départ le début de l'exercice courant : c'est une date
 * réelle du système, pas une donnée inventée, mais elle ne prétend pas
 * être la date d'entrée effective. Le rapport le dit en toutes lettres.
 *
 * **Un occupant sans espace connu reste un artisan sans attribution.**
 * MAKAMTE Bibiane paie une redevance pour B13 mais le relevé ne porte
 * aucun code d'espace pour elle — inventer un espace serait exactement
 * ce que l'import du registre a cessé de faire le 26/08.
 */
class ServiceCompletionAttributions
{
    /** @var array<string, int|null> */
    protected array $corpsMetiers = [];

    protected VillageArtisanal $village;

    protected Exercice $exercice;

    /**
     * @return array<string, mixed>
     */
    public function completer(string $chemin): array
    {
        $utilisateur = Auth::user();

        if (! $utilisateur || ! $utilisateur->agent) {
            throw new RuntimeException('Aucun compte réel connecté : la trace de création exige un agent.');
        }

        $exercice = Exercice::courant();

        if (! $exercice) {
            throw new RuntimeException('Aucun exercice en cours.');
        }

        $this->exercice = $exercice;
        $this->village = $exercice->village ?? VillageArtisanal::query()->firstOrFail();

        $rapport = [
            'lignes' => 0,
            'deja_attribues' => 0,
            'attributions_creees' => 0,
            'attributions_sans_redevance' => 0,
            'artisans_crees' => 0,
            'artisans_sans_secteur' => 0,
            'espaces_introuvables' => [],
            'occupants_sans_espace' => [],
        ];

        foreach ($this->lireCsv($chemin) as $ligne) {
            $rapport['lignes']++;

            $espaceCode = trim($ligne['espace'] ?? '');
            $occupant = Normalisation::lisible($ligne['occupant'] ?? '');

            if ($occupant === '') {
                continue;
            }

            if ($espaceCode === '') {
                // MAKAMTE Bibiane : redevance et occupant connus, aucun
                // espace pour les porter. L'artisan existe malgré tout —
                // règle 4, identité permanente indépendante de
                // l'attribution.
                $this->resoudreArtisan($occupant, $ligne['metier'] ?? '', $rapport);
                $rapport['occupants_sans_espace'][] = $occupant;

                continue;
            }

            $espace = EspaceLocatif::where('code', $espaceCode)->first();

            if (! $espace) {
                $rapport['espaces_introuvables'][] = $espaceCode;

                continue;
            }

            $dejaAttribue = AttributionEspace::query()
                ->where('espace_locatif_id', $espace->getKey())
                ->where('statut', StatutAttribution::ACTIVE->value)
                ->exists();

            if ($dejaAttribue) {
                $rapport['deja_attribues']++;

                continue;
            }

            $artisan = $this->resoudreArtisan($occupant, $ligne['metier'] ?? '', $rapport);
            $redevance = Normalisation::entier($ligne['redevance'] ?? '');

            AttributionEspace::create([
                'date_debut' => $this->exercice->date_debut,
                'redevance_convenue' => $redevance,
                'statut' => StatutAttribution::ACTIVE,
                'dossier_complet' => false,
                'artisan_id' => $artisan->getKey(),
                'espace_locatif_id' => $espace->getKey(),
                'exercice_id' => $this->exercice->getKey(),
            ]);

            $rapport['attributions_creees']++;

            if ($redevance === null) {
                $rapport['attributions_sans_redevance']++;
            }
        }

        return $rapport;
    }

    /**
     * @param  array<string, mixed>  $rapport
     */
    protected function resoudreArtisan(string $nom, string $metier, array &$rapport): Artisan
    {
        $artisan = Artisan::firstOrCreate(
            ['village_id' => $this->village->getKey(), 'nom' => $nom],
            [
                'actif' => true,
                'corps_metier_id' => $this->corpsMetierId($metier),
                'autorisation_publication' => false,
            ],
        );

        if ($artisan->wasRecentlyCreated) {
            $rapport['artisans_crees']++;

            if ($artisan->corps_metier_id === null) {
                $rapport['artisans_sans_secteur']++;
            }
        }

        return $artisan;
    }

    protected function corpsMetierId(string $metier): ?int
    {
        $code = LecteurRegistre::CORPS_METIER[Normalisation::comparable($metier)] ?? null;

        if ($code === null) {
            return null;
        }

        return $this->corpsMetiers[$code] ??= CorpsMetier::query()->where('code', $code)->value('id');
    }

    /**
     * @return iterable<int, array<string, string>>
     */
    protected function lireCsv(string $chemin): iterable
    {
        if (! is_file($chemin) || ! is_readable($chemin)) {
            throw new RuntimeException("Fichier introuvable ou illisible : {$chemin}");
        }

        $flux = fopen($chemin, 'r');

        if ($flux === false) {
            throw new RuntimeException("Ouverture impossible : {$chemin}");
        }

        try {
            $entete = fgetcsv($flux, 0, ';', '"', '');

            if ($entete === false || $entete === [null]) {
                return;
            }

            $entete[0] = preg_replace('/^\x{FEFF}/u', '', (string) $entete[0]) ?? '';
            $entete = array_map(fn ($colonne) => trim((string) $colonne), $entete);

            while (($cellules = fgetcsv($flux, 0, ';', '"', '')) !== false) {
                if ($cellules === [null]) {
                    continue;
                }

                $ligne = [];

                foreach ($entete as $rang => $colonne) {
                    $ligne[$colonne] = trim((string) ($cellules[$rang] ?? ''));
                }

                yield $ligne;
            }
        } finally {
            fclose($flux);
        }
    }
}
