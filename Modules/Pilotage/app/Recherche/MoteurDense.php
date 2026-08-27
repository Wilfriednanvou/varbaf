<?php

namespace Modules\Pilotage\Recherche;

use Illuminate\Support\Collection;
use Modules\Pilotage\Contracts\FournisseurDEmbeddings;
use Modules\Pilotage\Contracts\MoteurDeRecherche;
use Modules\Pilotage\Embeddings\Vecteurs;
use Modules\Pilotage\Indexation\Normalisateur;
use Modules\Pilotage\Models\FicheLexicale;

/**
 * La branche dense : ce que le lexical ne peut pas trouver.
 *
 * **Le défaut qu'elle corrige est précis.** La branche TF-IDF ne
 * rapproche que ce qui partage un mot. Une question sur « objets pour
 * la cuisine » ne trouve pas une fiche « marmite en terre cuite » :
 * aucun terme commun, similarité nulle, refus. Le vecteur dense place
 * ces deux textes au même endroit d'un espace appris, et les rapproche
 * sans qu'ils partagent quoi que ce soit.
 *
 * **Le défaut qu'elle introduit, symétriquement**, est qu'elle rapproche
 * *toujours* quelque chose : un espace continu n'a pas de notion de
 * « aucun rapport ». C'est pourquoi son seuil est nettement plus haut
 * que celui du lexical — un cosinus dense de 0,3 entre deux textes sans
 * rapport est banal, là où un cosinus TF-IDF de 0,3 suppose un
 * vocabulaire réellement partagé. Les deux seuils ne mesurent pas la
 * même chose et n'ont aucune raison de se ressembler.
 *
 * C'est exactement pourquoi ce moteur n'est pas censé répondre seul :
 * `MoteurHybride` le fait dialoguer avec le lexical, dont la sévérité
 * compense sa complaisance.
 *
 * **Le corpus est comparé en mémoire.** Quelques centaines de fiches,
 * un produit scalaire chacune : le coût est négligeable et la solution
 * ne demande aucune extension PostgreSQL. Voir la migration pour le
 * raisonnement complet.
 */
class MoteurDense implements MoteurDeRecherche
{
    use ComposeDesExtraits;

    public function __construct(
        protected FournisseurDEmbeddings $fournisseur,
    ) {}

    public function nom(): string
    {
        return 'Vecteurs denses — '.$this->fournisseur->nom();
    }

    public function cle(): string
    {
        return 'dense';
    }

    /**
     * Deux conditions, et les deux sont nécessaires.
     *
     * Le fournisseur doit répondre — sans lui, la question ne peut pas
     * être vectorisée, et un index dense qu'on ne sait pas interroger ne
     * sert à rien. Et l'index doit contenir des vecteurs **du modèle
     * courant** : ceux d'un autre modèle sont inexploitables, et les
     * compter reviendrait à se déclarer prêt sur un corpus vide.
     *
     * L'index est vérifié **avant** le fournisseur, et l'ordre compte :
     * sonder le réseau pour découvrir ensuite qu'il n'y a rien à
     * chercher ajouterait un aller-retour à chaque question sur toute
     * installation qui n'a jamais lancé `varbaf:indexer-vecteurs` —
     * c'est-à-dire sur l'installation par défaut.
     */
    public function estDisponible(): bool
    {
        return $this->corpus()->exists() && $this->fournisseur->estDisponible();
    }

    /**
     * @return Collection<int, SegmentTrouve>
     */
    public function rechercher(string $question, int $limite, ?float $seuil = null): Collection
    {
        $brut = $this->fournisseur->vecteur(trim($question));

        // Le fournisseur n'a pas répondu : ce n'est pas « aucun
        // résultat », c'est « pas de recherche ». La distinction compte
        // parce que l'hybride, lui, saura le dire à l'écran.
        if ($brut === null) {
            return new Collection();
        }

        $vecteurQuestion = Vecteurs::normer($brut);
        $seuil ??= (float) config('pilotage.dense.seuil', 0.35);

        $normalisateur = Normalisateur::depuisLaConfiguration();
        $termes = $this->termesSurs(
            VecteurDeQuestion::depuis($question, $normalisateur)->termesRetenus(),
        );

        $trouves = [];

        foreach ($this->corpus()->get() as $fiche) {
            $vecteur = $fiche->vecteur;

            if (! is_array($vecteur) || $vecteur === []) {
                continue;
            }

            $similarite = Vecteurs::cosinus($vecteurQuestion, $vecteur);

            if ($similarite < $seuil) {
                continue;
            }

            $trouves[] = [
                'similarite' => $similarite,
                'ligne' => (object) [
                    'fiche_id' => $fiche->getKey(),
                    'type' => $fiche->type->value,
                    'source_id' => $fiche->source_id,
                    'titre' => $fiche->titre,
                    'texte' => $fiche->texte,
                    'similarite' => $similarite,
                ],
            ];
        }

        // Départage stable sur l'identifiant, comme la branche lexicale :
        // deux fiches à similarité identique doivent sortir dans le même
        // ordre d'une exécution à l'autre, sans quoi la mesure de
        // rappel@5 varierait sans que rien n'ait changé.
        usort($trouves, function (array $a, array $b): int {
            return $b['similarite'] <=> $a['similarite']
                ?: $a['ligne']->fiche_id <=> $b['ligne']->fiche_id;
        });

        return (new Collection(array_slice($trouves, 0, $limite)))->map(
            fn (array $trouve): SegmentTrouve => $this->composerSegment(
                $trouve['ligne'],
                $termes,
                $normalisateur,
            ),
        );
    }

    /**
     * Les fiches porteuses d'un vecteur du modèle courant.
     *
     * @return \Illuminate\Database\Eloquent\Builder<FicheLexicale>
     */
    protected function corpus()
    {
        return FicheLexicale::query()
            ->whereNotNull('vecteur')
            ->where('vecteur_modele', $this->fournisseur->modele())
            ->select(['id', 'type', 'source_id', 'titre', 'texte', 'vecteur']);
    }
}
