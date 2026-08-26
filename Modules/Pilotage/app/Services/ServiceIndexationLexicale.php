<?php

namespace Modules\Pilotage\Services;

use Illuminate\Support\Facades\DB;
use Modules\Pilotage\Enums\TypeFicheLexicale;
use Modules\Pilotage\Indexation\CompositeurDeFiches;
use Modules\Pilotage\Indexation\FicheComposee;
use Modules\Pilotage\Indexation\Normalisateur;
use Modules\Pilotage\Indexation\RapportIndexation;
use Modules\Pilotage\Models\FicheLexicale;
use Modules\Pilotage\Models\TermeVocabulaire;

/**
 * Construit l'index TF-IDF du corpus, entièrement en base et en PHP.
 *
 * Aucun service externe, aucune connexion sortante, aucun paquet
 * supplémentaire. C'est la propriété qui justifie cette branche : elle
 * fonctionne le jour de la soutenance quoi qu'il arrive au réseau.
 *
 * **Trois passes, et une seule est partielle.**
 *
 * 1. *Composition.* Les fiches des types demandés sont relues depuis les
 *    modèles et retokenisées — sauf celles dont l'empreinte n'a pas
 *    bougé, qui gardent les fréquences déjà stockées.
 * 2. *Vocabulaire.* La fréquence documentaire et l'IDF sont recalculés
 *    sur **tout** le corpus, jamais sur le seul sous-ensemble demandé.
 * 3. *Pondération.* Les poids et les normes sont recalculés sur tout le
 *    corpus également.
 *
 * Les passes 2 et 3 ne peuvent pas être partielles, et c'est
 * mathématique : l'IDF d'un terme dépend du nombre de fiches qui le
 * portent. Réindexer les seuls produits change donc le poids que ce
 * terme a dans les fiches artisan. Une réindexation « partielle » qui
 * ne toucherait pas au reste produirait un index silencieusement faux —
 * `--type` limite ce qu'on relit, jamais ce qu'on recalcule.
 *
 * Les deux dernières passes sont écrites en SQL. Sur un corpus de
 * quelques milliers d'entrées, les remonter en PHP pour les redescendre
 * ligne à ligne coûterait des milliers d'allers-retours pour un calcul
 * que PostgreSQL fait en une instruction.
 */
class ServiceIndexationLexicale
{
    public function __construct(
        protected CompositeurDeFiches $compositeur,
    ) {}

    /**
     * Reconstruit l'index.
     *
     * @param  array<int, TypeFicheLexicale>|null  $types  null = tout le corpus
     * @param  bool  $force  retokeniser même les fiches inchangées
     */
    public function reindexer(?array $types = null, bool $force = false): RapportIndexation
    {
        $types ??= TypeFicheLexicale::cases();

        return DB::transaction(function () use ($types, $force): RapportIndexation {
            $lues = 0;
            $recomposees = 0;
            $inchangees = 0;
            $supprimees = 0;
            $ecrits = 0;

            foreach ($types as $type) {
                $resultat = $this->composerLesFichesDuType($type, $force);

                $lues += $resultat['lues'];
                $recomposees += $resultat['recomposees'];
                $inchangees += $resultat['inchangees'];
                $supprimees += $resultat['supprimees'];
                $ecrits += $resultat['ecrits'];
            }

            $this->reconstruireLeVocabulaire();
            $this->repondererLeCorpus();

            return new RapportIndexation(
                fichesLues: $lues,
                fichesRecomposees: $recomposees,
                fichesInchangees: $inchangees,
                fichesSupprimees: $supprimees,
                fichesSansTerme: FicheLexicale::query()->where('nombre_termes', 0)->count(),
                termesEcrits: $ecrits,
                termesDistincts: TermeVocabulaire::query()->count(),
                tailleCorpus: FicheLexicale::query()->count(),
            );
        });
    }

    /**
     * @return array{lues: int, recomposees: int, inchangees: int, supprimees: int, ecrits: int}
     */
    protected function composerLesFichesDuType(TypeFicheLexicale $type, bool $force): array
    {
        $normalisateur = Normalisateur::depuisLaConfiguration();
        $poids = $this->poidsDuType($type);

        $lues = 0;
        $recomposees = 0;
        $inchangees = 0;
        $ecrits = 0;

        /** @var array<int, int> $vues */
        $vues = [];

        foreach ($this->compositeur->pourType($type) as $composee) {
            $lues++;
            $vues[] = $composee->sourceId;

            $existante = FicheLexicale::query()
                ->where('type', $type->value)
                ->where('source_id', $composee->sourceId)
                ->first();

            $empreinte = $composee->empreinte();

            if (! $force && $existante !== null && $existante->empreinte === $empreinte) {
                $inchangees++;

                continue;
            }

            $ecrits += $this->ecrireLaFiche($existante, $composee, $empreinte, $normalisateur, $poids);
            $recomposees++;
        }

        $supprimees = $this->retirerLesFichesOrphelines($type, $vues);

        return [
            'lues' => $lues,
            'recomposees' => $recomposees,
            'inchangees' => $inchangees,
            'supprimees' => $supprimees,
            'ecrits' => $ecrits,
        ];
    }

    /**
     * Écrit une fiche et ses termes. Rend le nombre de termes écrits.
     *
     * @param  array<string, int>  $poids
     */
    protected function ecrireLaFiche(
        ?FicheLexicale $existante,
        FicheComposee $composee,
        string $empreinte,
        Normalisateur $normalisateur,
        array $poids,
    ): int {
        $frequences = $this->frequencesDeLaFiche($composee, $normalisateur, $poids);

        $fiche = $existante ?? new FicheLexicale([
            'type' => $composee->type->value,
            'source_id' => $composee->sourceId,
        ]);

        $fiche->fill([
            'type' => $composee->type->value,
            'source_id' => $composee->sourceId,
            'titre' => $composee->titre,
            'texte' => $composee->texte(),
            'nombre_termes' => count($frequences),
            'empreinte' => $empreinte,
            'indexee_le' => now(),
        ])->save();

        // Les termes de la fiche sont remplacés en bloc plutôt que
        // rapprochés un à un : une fiche recomposée peut avoir perdu des
        // termes autant qu'en avoir gagné, et la comparaison coûterait
        // plus que la réécriture.
        $fiche->termes()->delete();

        if ($frequences === []) {
            return 0;
        }

        $lots = array_chunk(
            array_map(
                fn (string $terme, int $frequence): array => [
                    'fiche_id' => $fiche->getKey(),
                    'terme' => $terme,
                    'frequence' => $frequence,
                    'poids' => 0,
                ],
                array_keys($frequences),
                $frequences,
            ),
            (int) config('pilotage.index.taille_lot', 500),
        );

        foreach ($lots as $lot) {
            DB::table('termes_lexicaux')->insert($lot);
        }

        return count($frequences);
    }

    /**
     * Les fréquences pondérées de tous les champs d'une fiche.
     *
     * Un champ non renseigné n'apparaît pas dans `champsRenseignes()` et
     * ne contribue donc rien — sans test particulier ici. Un champ dont
     * le poids n'est pas configuré retombe sur 1 plutôt que sur zéro :
     * une clé de configuration oubliée doit affaiblir le champ, jamais
     * le faire disparaître silencieusement de l'index.
     *
     * @param  array<string, int>  $poids
     * @return array<string, int>
     */
    protected function frequencesDeLaFiche(
        FicheComposee $composee,
        Normalisateur $normalisateur,
        array $poids,
    ): array {
        $frequences = [];

        foreach ($composee->champsRenseignes() as $champ => $texte) {
            foreach ($normalisateur->frequences($texte, $poids[$champ] ?? 1) as $terme => $nombre) {
                $frequences[$terme] = ($frequences[$terme] ?? 0) + $nombre;
            }
        }

        return $frequences;
    }

    /**
     * @param  array<int, int>  $sourcesVues
     */
    protected function retirerLesFichesOrphelines(TypeFicheLexicale $type, array $sourcesVues): int
    {
        $requete = FicheLexicale::query()->where('type', $type->value);

        if ($sourcesVues !== []) {
            $requete->whereNotIn('source_id', $sourcesVues);
        }

        // La cascade emporte les termes : c'est la contrainte de clé
        // étrangère qui s'en charge, pas ce code.
        return $requete->delete();
    }

    /**
     * Recalcule la fréquence documentaire et l'IDF de tout le corpus.
     *
     * `ln(1 + N / df)` plutôt que `ln(N / df)` : la forme lissée reste
     * strictement positive même pour un terme présent dans toutes les
     * fiches, là où la forme classique le ramène à zéro et le fait
     * disparaître du calcul. Sur un corpus de mille fiches où un mot
     * comme « artisanal » est partout, cette différence décide entre
     * « ce terme compte peu » et « ce terme n'existe pas ».
     */
    protected function reconstruireLeVocabulaire(): void
    {
        DB::table('vocabulaire_lexical')->delete();

        $corpus = FicheLexicale::query()->count();

        if ($corpus === 0) {
            return;
        }

        DB::statement(
            'insert into vocabulaire_lexical (terme, documents, idf)
             select terme,
                    count(distinct fiche_id) as documents,
                    ln(1 + (? / count(distinct fiche_id)::float)) as idf
             from termes_lexicaux
             group by terme',
            [$corpus],
        );
    }

    /**
     * Applique l'IDF à tout l'index, puis recalcule les normes.
     */
    protected function repondererLeCorpus(): void
    {
        DB::statement(
            'update termes_lexicaux as t
             set poids = t.frequence * v.idf
             from vocabulaire_lexical as v
             where v.terme = t.terme',
        );

        DB::statement(
            'update fiches_lexicales as f
             set norme = coalesce(s.norme, 0),
                 nombre_termes = coalesce(s.nombre, 0)
             from (
                 select fiche_id,
                        sqrt(sum(poids * poids)) as norme,
                        count(*) as nombre
                 from termes_lexicaux
                 group by fiche_id
             ) as s
             where s.fiche_id = f.id',
        );

        // Une fiche dont tous les champs étaient vides n'a aucun terme :
        // elle ne figure pas dans l'agrégat ci-dessus et garderait la
        // norme d'une indexation antérieure. Une norme nulle la rend
        // incomparable, ce qui est exactement son état.
        DB::statement(
            'update fiches_lexicales
             set norme = 0, nombre_termes = 0
             where not exists (
                 select 1 from termes_lexicaux where termes_lexicaux.fiche_id = fiches_lexicales.id
             )',
        );
    }

    /**
     * @return array<string, int>
     */
    protected function poidsDuType(TypeFicheLexicale $type): array
    {
        /** @var array<string, int> $poids */
        $poids = config('pilotage.index.poids.'.$type->cleDePoids(), []);

        return $poids;
    }
}
