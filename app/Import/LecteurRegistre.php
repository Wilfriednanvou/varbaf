<?php

namespace App\Import;

use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Lecture du registre de ventes reconstruit (pipeline du 2 septembre 2026).
 *
 * **Ce que cette classe faisait avant, et pourquoi ça ne suffit plus.**
 * Elle lisait directement le cahier manuscrit transcrit : dates en trois
 * écritures, quantité/prix/montant à recouper, noms d'artisans à
 * rapprocher par similarité. Le pipeline `docs/donnees/*.py` fait
 * désormais tout ce travail en amont, une fois pour toutes, et le
 * documente dans `docs/donnees/README.md` — dates déjà résolues,
 * artisans déjà rapprochés dans `rattachements.csv`. Relire le registre
 * comme un cahier brut referait un travail déjà fait, avec le risque de
 * le refaire autrement.
 *
 * **Ce que cette classe fait maintenant : joindre, pas deviner.** Trois
 * fichiers, une seule vérité chacun :
 * - `registre.csv` — la vente : date, désignation, montant, écriture
 *   d'artisan telle que transcrite.
 * - `rattachements.csv` — la décision humaine-assistée sur cette
 *   écriture : rattachée à un occupant du parc, sans correspondance
 *   (déposant non installé), à arbitrer, ou pas un artisan du tout.
 * - `parc-locatif.csv` — pour l'espace résolu, la redevance convenue et
 *   le métier déclaré.
 *
 * **Deux décisions ne produisent aucune ligne importable**, et c'est
 * délibéré : `A ARBITRER` est une ambiguïté que le script de
 * rapprochement a explicitement refusé de trancher — l'importer quand
 * même reviendrait à trancher à sa place. `NON ARTISAN` désigne un
 * espace du village ou un agent, jamais un artisan — il n'y a personne
 * à qui rattacher la vente. Les deux sont comptées et signalées,
 * jamais tues.
 *
 * **Une ligne, un article.** Le nouveau registre ne porte plus de
 * quantité ni de prix unitaire séparés — un seul montant par ligne. La
 * ligne est donc traitée comme la vente d'un article unique à ce
 * montant : c'est la meilleure lecture d'une ligne qui ne dit rien de
 * plus, et le montant retenu par le système reste rigoureusement celui
 * du registre (quantité 1 × prix = montant, sans arrondi ni écart).
 */
class LecteurRegistre
{
    /**
     * Décisions de `rattachements.csv` qui ne produisent aucune ligne :
     * une ambiguïté réelle, ou une écriture qui ne désigne pas un
     * artisan.
     *
     * @var array<int, string>
     */
    protected const DECISIONS_EXCLUES = ['A ARBITRER', 'NON ARTISAN'];

    /**
     * Métier déclaré au relevé de recouvrement → code du corps de
     * métier officiel (`CorpsMetierSeeder`), en forme comparable
     * (`Normalisation::comparable()`).
     *
     * **Absent de cette table, un métier reste sans secteur — jamais
     * deviné.** Voir la migration
     * `rendre_facultatives_les_donnees_absentes_du_registre` : inventer
     * un rattachement approximatif polluerait un référentiel qui fait
     * autorité. Plusieurs métiers du relevé restent donc volontairement
     * hors de cette table : « bijoux en perles » (question 5 de
     * `docs/questions-coordination.md`, toujours sans réponse),
     * « objets en bambou », « plusieurs objets artisanaux », « objet
     * traditionnel », « naturopathie… », « entretien des plantes
     * médicinales » — aucun ne recoupe sans force l'un des quatorze
     * secteurs.
     *
     * @var array<string, string>
     */
    protected const CORPS_METIER = [
        'production des vins' => 'AGR',
        'apiculteur' => 'AGR',
        'production des produits a base de la farine locale' => 'AGR',
        'production de la pharmacopee traditionnelle' => 'MED',
        'produits de sante' => 'MED',
        'cosmetique' => 'COS',
        'production des cosmetiques' => 'COS',
        'production des objets sculptes' => 'SCU',
        'production des objets sculptes transformation du bois' => 'SCU',
        'fabrication des chaussures' => 'CUI',
        'vannerie' => 'VAN',
        'objets artisanaux et recuperateur' => 'REC',
        'dessinateur' => 'ARP',
        'cooperative des artisans menuisiers' => 'MEN',
    ];

    /**
     * Ce que la dernière lecture a écarté — une entrée par ligne
     * exclue, pour le rapport complémentaire.
     *
     * Remise à zéro à chaque appel de `lire()` : la propriété ne porte
     * que le dernier fichier lu.
     *
     * @var array<int, array{ligne_source: string, artisan: string, decision: string, montant: string}>
     */
    public array $dernieresExclusions = [];

    /**
     * @return array<int, LigneRegistre>
     */
    public function lire(string $chemin): array
    {
        if (! is_file($chemin) || ! is_readable($chemin)) {
            throw new RuntimeException("Registre introuvable ou illisible : {$chemin}");
        }

        $repertoire = dirname($chemin);
        $rattachements = $this->lireRattachements($repertoire.DIRECTORY_SEPARATOR.'rattachements.csv');
        $parc = $this->lireParc($repertoire.DIRECTORY_SEPARATOR.'parc-locatif.csv');

        $this->dernieresExclusions = [];
        $fichier = basename($chemin);
        $lignes = [];

        foreach ($this->lireCsv($chemin) as $brut) {
            $artisanSource = trim($brut['artisan'] ?? '');
            $rattachement = $rattachements[$artisanSource] ?? null;

            if ($rattachement === null) {
                // Ne peut arriver que si rattachements.csv n'a pas été
                // régénéré depuis le dernier registre : les deux
                // fichiers sont produits par le même pipeline et
                // portent les mêmes écritures. Signalé en échec dur
                // plutôt qu'en anomalie silencieuse — une ligne sans
                // décision ne doit pas se glisser dans un import.
                throw new RuntimeException(
                    "Aucune décision dans rattachements.csv pour l'écriture « {$artisanSource} » "
                    ."(ligne {$brut['ligne_source']}). Régénérer rattacher-artisans.py avant de réimporter."
                );
            }

            if (in_array($rattachement['decision'], self::DECISIONS_EXCLUES, strict: true)) {
                $this->dernieresExclusions[] = [
                    'ligne_source' => $brut['ligne_source'] ?? '',
                    'artisan' => $artisanSource,
                    'decision' => $rattachement['decision'],
                    'montant' => $brut['montant'] ?? '',
                ];

                continue;
            }

            $espaceLocatif = $rattachement['espace_locatif'];
            $infosParc = $espaceLocatif !== '' ? ($parc[$espaceLocatif] ?? null) : null;

            // RATTACHE : le nom officiel du parc fait autorité — une
            // personne a lu les deux écritures et tranché. SANS
            // CORRESPONDANCE : aucun occupant ne répond, l'écriture du
            // registre devient elle-même le nom retenu (déposant non
            // installé, règle 4 — identité permanente sans espace).
            $nomOfficiel = $rattachement['decision'] === 'RATTACHE'
                ? $rattachement['occupant_parc']
                : Normalisation::lisible($artisanSource);

            $montant = Normalisation::entier($brut['montant'] ?? '');
            $date = $this->parserDate($brut['date'] ?? '');

            $ligne = new LigneRegistre(
                numero: (int) ($brut['ligne_source'] ?? 0),
                empreinte: $this->empreinte($fichier, $brut),
                brut: $brut,
                date: $date,
                codeBoutiqueSource: $espaceLocatif !== '' ? $espaceLocatif : 'SANS CODE',
                // Le contenant vient de parc-locatif.csv, jamais dérivé
                // du code de l'espace : SS01 abrite G0201, un code qui
                // ne suit pas la règle B{numero} (voir
                // EspaceLocatif::genererCode()).
                codeBoutique: $infosParc['contenant'] ?? '',
                nomArtisan: Normalisation::lisible($artisanSource),
                designation: Normalisation::lisible($brut['designation'] ?? ''),
                conditionnement: '',
                quantite: $montant !== null ? 1 : null,
                prixUnitaire: $montant,
                montantTranscrit: $montant,
                ecartSignaleALaSource: false,
                espaceLocatif: $espaceLocatif,
                nomArtisanOfficiel: $nomOfficiel,
                redevanceConvenue: $infosParc['redevance'] ?? null,
                corpsMetier: $infosParc['corps_metier'] ?? '',
            );

            if ($montant === null) {
                $ligne->signaler(LigneRegistre::VALEURS_INSUFFISANTES);
            }

            $lignes[] = $ligne;
        }

        return $lignes;
    }

    /**
     * Empreinte d'idempotence : le rang du registre source suffit à
     * distinguer deux lignes par ailleurs identiques (le même article,
     * au même prix, le même jour).
     *
     * @param  array<string, string>  $brut
     */
    protected function empreinte(string $fichier, array $brut): string
    {
        return hash('sha256', $fichier."\x1f".($brut['ligne_source'] ?? ''));
    }

    protected function parserDate(string $brut): ?Carbon
    {
        $valeur = trim($brut);

        if ($valeur === '') {
            return null;
        }

        // Déjà résolue en ISO par extraire-registre.py : aucun format
        // à deviner ici.
        try {
            return Carbon::createFromFormat('Y-m-d', $valeur)?->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, array{decision: string, espace_locatif: string, occupant_parc: string}>
     */
    protected function lireRattachements(string $chemin): array
    {
        $index = [];

        foreach ($this->lireCsv($chemin) as $ligne) {
            $index[trim($ligne['ecriture_registre'] ?? '')] = [
                'decision' => trim($ligne['decision'] ?? ''),
                'espace_locatif' => trim($ligne['espace_locatif'] ?? ''),
                'occupant_parc' => Normalisation::lisible($ligne['occupant_parc'] ?? ''),
            ];
        }

        return $index;
    }

    /**
     * @return array<string, array{contenant: string, redevance: ?int, corps_metier: string}>
     */
    protected function lireParc(string $chemin): array
    {
        $index = [];

        foreach ($this->lireCsv($chemin) as $ligne) {
            $espace = trim($ligne['espace'] ?? '');

            if ($espace === '') {
                continue;
            }

            $index[$espace] = [
                'contenant' => trim($ligne['contenant'] ?? ''),
                'redevance' => Normalisation::entier($ligne['redevance'] ?? ''),
                'corps_metier' => self::CORPS_METIER[Normalisation::comparable($ligne['metier'] ?? '')] ?? '',
            ];
        }

        return $index;
    }

    /**
     * Lecteur CSV générique : point-virgule, tel que produisent les
     * trois scripts Python de `docs/donnees/`.
     *
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
