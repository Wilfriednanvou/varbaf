<?php

namespace Modules\Pilotage\Services;

use Illuminate\Support\Collection;
use Modules\Pilotage\Contracts\FournisseurDEmbeddings;
use Modules\Pilotage\Embeddings\Vecteurs;
use Modules\Pilotage\Indexation\RapportIndexationDense;
use Modules\Pilotage\Models\FicheLexicale;

/**
 * Calcule le vecteur dense de chaque fiche déjà composée.
 *
 * **Cette indexation vient après l'autre, jamais à la place.** Elle ne
 * relit ni les produits ni les artisans : elle part de
 * `fiches_lexicales`, que `ServiceIndexationLexicale` a composées, et
 * n'y ajoute qu'une colonne. Les deux branches voient donc exactement le
 * même corpus, avec les mêmes titres et les mêmes textes — sans quoi
 * comparer leurs résultats n'aurait aucun sens, et la fusion mêlerait
 * deux réalités différentes.
 *
 * **Elle est hors transaction, contrairement à l'indexation lexicale.**
 * Une indexation lexicale est une suite de calculs locaux : la
 * dérouler d'un bloc coûte quelques secondes. Une indexation dense est
 * une suite d'appels réseau : quelques centaines de fiches peuvent
 * demander plusieurs minutes. Tenir une transaction PostgreSQL ouverte
 * pendant tout ce temps verrouillerait la table pour rien, et une
 * coupure au milieu ferait tout recommencer. Chaque lot est écrit dès
 * qu'il est obtenu, et une reprise repart d'où l'on s'était arrêté —
 * c'est précisément ce que l'empreinte permet.
 *
 * **Une fiche inchangée n'est pas recalculée.** L'empreinte du contenu
 * est déjà calculée par l'indexation lexicale ; on la recopie à côté du
 * vecteur. Tant qu'elle ne bouge pas et que le modèle est le même, le
 * vecteur reste valable. Sans cela, chaque exécution rappellerait le
 * modèle pour tout le corpus, et la commande deviendrait impraticable
 * en démonstration.
 */
class ServiceIndexationDense
{
    public function __construct(
        protected FournisseurDEmbeddings $fournisseur,
    ) {}

    /**
     * @param  (callable(int, int): void)|null  $progression  fiches traitées, total
     */
    public function reindexer(bool $force = false, ?callable $progression = null): RapportIndexationDense
    {
        $modele = $this->fournisseur->modele();
        $taille = FicheLexicale::query()->count();

        // Les fiches sans terme sont hors du corpus dense comme du
        // corpus lexical : une fiche dont tous les champs étaient vides
        // n'a rien à vectoriser, et son vecteur ne rapprocherait que du
        // bruit.
        $comparables = FicheLexicale::query()->comparable()->count();

        $aFaire = FicheLexicale::query()
            ->comparable()
            ->when(! $force, fn ($requete) => $requete->where(function ($ou) use ($modele): void {
                $ou->whereNull('vecteur')
                    ->orWhere('vecteur_modele', '!=', $modele)
                    ->orWhereColumn('vecteur_empreinte', '!=', 'empreinte')
                    ->orWhereNull('vecteur_empreinte');
            }))
            ->orderBy('id')
            ->get(['id', 'titre', 'texte', 'empreinte']);

        $lues = $aFaire->count();
        $vectorisees = 0;
        $echouees = 0;
        $dimensions = 0;
        $traitees = 0;

        foreach ($aFaire->chunk((int) config('pilotage.dense.ollama.lot', 32)) as $lot) {
            /** @var Collection<int, FicheLexicale> $lot */
            $textes = $lot->map(fn (FicheLexicale $fiche): string => $this->texteAVectoriser($fiche))->values()->all();

            $vecteurs = $this->fournisseur->vecteurs($textes);

            foreach ($lot->values() as $rang => $fiche) {
                $brut = $vecteurs[$rang] ?? null;

                if ($brut === null || $brut === []) {
                    $echouees++;

                    continue;
                }

                $dimensions = count($brut);

                // Le vecteur est stocké **normé** : le moteur n'a plus
                // qu'un produit scalaire à faire. Voir `Vecteurs`.
                FicheLexicale::query()->whereKey($fiche->getKey())->update([
                    'vecteur' => json_encode(Vecteurs::normer($brut)),
                    'vecteur_modele' => $modele,
                    'vecteur_empreinte' => $fiche->empreinte,
                ]);

                $vectorisees++;
            }

            $traitees += $lot->count();

            if ($progression !== null) {
                $progression($traitees, $lues);
            }
        }

        return new RapportIndexationDense(
            modele: $modele,
            fichesLues: $lues,
            vectorisees: $vectorisees,
            inchangees: max(0, $comparables - $lues),
            echouees: $echouees,
            dimensions: $dimensions,
            couverture: FicheLexicale::query()
                ->whereNotNull('vecteur')
                ->where('vecteur_modele', $modele)
                ->count(),
            tailleCorpus: $taille,
        );
    }

    /**
     * Le texte soumis au modèle : le titre, puis le corps.
     *
     * Le titre est répété en tête plutôt que fondu dans le texte. Un
     * modèle d'embeddings pondère les premiers mots plus fortement, et
     * c'est le titre qui porte l'identité de la fiche — « Panier tressé
     * en raphia » dit ce qu'est la fiche, la description dit ce qu'on
     * peut en ajouter. C'est le pendant dense du poids 3 que la branche
     * lexicale accorde déjà à la désignation.
     */
    protected function texteAVectoriser(FicheLexicale $fiche): string
    {
        $texte = trim((string) $fiche->texte);

        return trim($fiche->titre."\n".$texte);
    }
}
