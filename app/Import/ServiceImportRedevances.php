<?php

namespace App\Import;

use Illuminate\Support\Facades\Auth;
use Modules\Artisanat\Enums\StatutAttribution;
use Modules\Artisanat\Models\AttributionEspace;
use Modules\Artisanat\Models\EspaceLocatif;
use Modules\Tresorerie\Enums\NatureMouvementCaisse;
use Modules\Tresorerie\Enums\SensMouvementCaisse;
use Modules\Tresorerie\Models\LibelleMouvement;
use Modules\Tresorerie\Models\MouvementCaisse;
use Modules\Tresorerie\Services\ServiceTresorerie;
use RuntimeException;

/**
 * Reprend au brouillard de caisse les redevances déjà encaissées avant
 * l'existence du système.
 *
 * **Deux colonnes portent le même encaissement, et elles ne s'accordent
 * pas toujours.** `docs/donnees/README.md` l'a établi : sur cinq
 * lignes, la synthèse annuelle (`paye_2026`) et le détail mensuel
 * (`paye_mensuel_2026`) divergent — cent dix mille francs encaissés au
 * mois n'apparaissent pas dans la synthèse. Le détail mensuel est la
 * lecture retenue ici, parce que c'est lui que l'analyse a montré le
 * plus complet ; mais « laquelle fait foi relève de la coordination »,
 * et ce script ne referme pas la question — il rapporte chaque
 * désaccord au lieu de le taire.
 *
 * **Une seule écriture par occupant, pas un historique.** Le relevé ne
 * porte qu'un total annuel, jamais de date de versement : chaque
 * encaissement est donc reconstitué comme un mouvement unique, daté du
 * jour de la reprise — même limite, assumée pour la même raison, que
 * les dépôts et les ventes du registre (voir `ServiceImportRegistre`).
 *
 * **Un occupant sans espace connu n'est pas un occupant sans
 * encaissement.** MAKAMTE Bibiane (boutique B13) paie une redevance
 * réelle sans qu'aucune attribution ne puisse la porter. L'écriture
 * est enregistrée quand même, sans origine — c'est exactement le cas
 * que `ServiceLocations::encaissementsNonRattaches()` existe pour
 * signaler plutôt que perdre silencieusement de l'argent réel.
 */
class ServiceImportRedevances
{
    /**
     * Marque chaque ligne reprise pour un ré-import sans doublon — la
     * pièce justificative plutôt qu'une table dédiée, puisqu'une seule
     * écriture par ligne suffit à décrire toute la reprise.
     */
    protected function piece(string $ligneSource): string
    {
        return "RELEVE-2026-{$ligneSource}";
    }

    /**
     * @return array<string, mixed>
     */
    public function importer(string $chemin): array
    {
        $utilisateur = Auth::user();

        if (! $utilisateur || ! $utilisateur->agent) {
            throw new RuntimeException('Aucun compte réel connecté : la trace de saisie exige un agent.');
        }

        $tresorerie = app(ServiceTresorerie::class);
        $section = $tresorerie->resoudreSectionOuverte();

        $libelleMouvement = LibelleMouvement::query()
            ->where('code', NatureMouvementCaisse::REDEVANCE->value)
            ->first();

        $rapport = [
            'lignes' => 0,
            'encaissements_crees' => 0,
            'montant_total' => 0,
            'deja_repris' => 0,
            'sans_paiement' => 0,
            'orphelins' => 0,
            'espaces_introuvables' => [],
            'ecarts_paye' => [],
        ];

        foreach ($this->lireCsv($chemin) as $ligne) {
            $rapport['lignes']++;

            $piece = $this->piece($ligne['ligne_source'] ?? (string) $rapport['lignes']);

            if (MouvementCaisse::query()->where('piece_justificative', $piece)->exists()) {
                $rapport['deja_repris']++;

                continue;
            }

            $occupant = Normalisation::lisible($ligne['occupant'] ?? '');
            $espaceCode = trim($ligne['espace'] ?? '');
            $payeAnnuel = Normalisation::entier($ligne['paye_2026'] ?? '');
            $payeMensuel = Normalisation::entier($ligne['paye_mensuel_2026'] ?? '');

            if ($payeAnnuel !== null && $payeMensuel !== null && $payeAnnuel !== $payeMensuel) {
                $rapport['ecarts_paye'][] = [
                    'occupant' => $occupant,
                    'paye_2026' => $payeAnnuel,
                    'paye_mensuel_2026' => $payeMensuel,
                ];
            }

            // Le détail mensuel prime quand les deux existent (voir le
            // motif en tête de classe) ; à défaut, la synthèse annuelle.
            $montant = $payeMensuel ?? $payeAnnuel;

            if ($montant === null || $montant <= 0) {
                $rapport['sans_paiement']++;

                continue;
            }

            $attribution = null;

            if ($espaceCode !== '') {
                $espace = EspaceLocatif::where('code', $espaceCode)->first();

                if (! $espace) {
                    $rapport['espaces_introuvables'][] = $espaceCode;
                } else {
                    $attribution = AttributionEspace::query()
                        ->where('espace_locatif_id', $espace->getKey())
                        ->where('statut', StatutAttribution::ACTIVE->value)
                        ->first();
                }
            }

            if ($attribution === null) {
                $rapport['orphelins']++;
            }

            $tresorerie->enregistrer(
                section: $section,
                nature: NatureMouvementCaisse::REDEVANCE,
                sens: SensMouvementCaisse::ENTREE,
                montant: $montant,
                libelle: "Reprise du relevé de recouvrement 2026 — {$occupant}"
                    .($espaceCode !== '' ? " ({$espaceCode})" : ''),
                pieceJustificative: $piece,
                origine: $attribution,
                libelleMouvement: $libelleMouvement,
            );

            $rapport['encaissements_crees']++;
            $rapport['montant_total'] += $montant;
        }

        return $rapport;
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
