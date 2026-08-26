<?php

namespace Modules\Pilotage\Console;

use Illuminate\Console\Command;
use Modules\Pilotage\Enums\TypeFicheLexicale;
use Modules\Pilotage\Services\ServiceIndexationLexicale;

/**
 * Reconstruit l'index lexical du corpus.
 *
 * **Relançable sans précaution.** L'index est entièrement dérivé des
 * modèles : le relancer deux fois de suite donne exactement le même
 * résultat, et le perdre ne perd rien. C'est la seule table du système
 * dont on puisse dire cela — partout ailleurs, l'immuabilité et le
 * figement interdisent la réécriture.
 *
 * Aucune écriture au journal d'audit, à dessein : l'audit trace les
 * faits métier et les décisions, or une réindexation ne produit aucun
 * fait. Elle recalcule une vue dérivée qui, si elle disparaissait,
 * se reconstruirait à l'identique.
 */
class IndexerCorpusCommand extends Command
{
    protected $signature = 'varbaf:indexer
        {--type=tout : Corpus à recomposer — produit, artisan ou tout}
        {--force : Retokeniser même les fiches dont le texte n\'a pas changé}';

    protected $description = 'Reconstruit l\'index lexical TF-IDF des produits et des artisans.';

    public function handle(ServiceIndexationLexicale $indexation): int
    {
        $types = $this->typesDemandes();

        if ($types === null) {
            $this->components->error(
                "Type inconnu : « {$this->option('type')} ». Valeurs acceptées : produit, artisan, tout.",
            );

            return self::FAILURE;
        }

        $libelle = implode(' et ', array_map(
            fn (TypeFicheLexicale $type): string => mb_strtolower($type->getLabel()).'s',
            $types,
        ));

        $this->components->info("Indexation lexicale : {$libelle}.");

        if ($this->option('force')) {
            $this->components->warn('Mode --force : toutes les fiches sont retokenisées.');
        }

        $rapport = $indexation->reindexer($types, (bool) $this->option('force'));

        $this->newLine();
        $this->table(['Indicateur', 'Valeur'], $rapport->enLignes());

        if ($rapport->tailleCorpus === 0) {
            $this->components->warn(
                'Le corpus est vide : aucun produit ni artisan en base. Reprenez le registre avant d\'indexer.',
            );

            return self::SUCCESS;
        }

        if ($rapport->fichesSansTerme > 0) {
            $this->components->warn(
                "{$rapport->fichesSansTerme} fiche(s) sans aucun terme indexable : "
                .'leurs champs sont vides ou ne contiennent que des mots outils. '
                .'Elles restent au corpus mais ne peuvent être ni recommandées ni retrouvées.',
            );
        }

        $this->components->info(
            'Poids en vigueur : '.json_encode(config('pilotage.index.poids'), JSON_UNESCAPED_UNICODE),
        );

        return self::SUCCESS;
    }

    /**
     * @return array<int, TypeFicheLexicale>|null  null si la valeur est invalide
     */
    protected function typesDemandes(): ?array
    {
        return match (mb_strtolower(trim((string) $this->option('type')))) {
            'tout', '' => TypeFicheLexicale::cases(),
            'produit', 'produits' => [TypeFicheLexicale::PRODUIT],
            'artisan', 'artisans' => [TypeFicheLexicale::ARTISAN],
            default => null,
        };
    }
}
