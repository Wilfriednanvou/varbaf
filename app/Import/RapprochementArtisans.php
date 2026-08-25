<?php

namespace App\Import;

/**
 * Résolution d'entités sur les noms d'artisans du registre.
 *
 * Le registre porte trois cent vingt-six écritures distinctes dans la
 * colonne « nom_artisan_source » pour un village qui compte beaucoup
 * moins d'artisans. « Bassi », « Bassie » et « BASSIE » sont la même
 * personne ; « Crousti delice » et « Crousti-delice » aussi. Créer un
 * artisan par écriture produirait un fichier artisans inexploitable et
 * un état de reversement qui éclate les ventes d'une même personne sur
 * trois lignes.
 *
 * **La méthode.** Chaque nom est réduit à sa forme comparable — sans
 * accent, sans ponctuation, sans casse — puis confronté aux formes déjà
 * retenues par une distance de chaînes. Au-dessus du seuil, les deux
 * écritures désignent la même personne ; en dessous, elles restent
 * distinctes.
 *
 * **La forme retenue est la plus fréquente**, et non la première
 * rencontrée. C'est celle que la coordination reconnaîtra : si le
 * registre écrit cent quatre-vingt-quinze fois « Merveille » et une
 * fois « merveill », c'est la première qui doit figurer au fichier
 * artisans.
 *
 * **La zone de doute.** Un rapprochement écarté de justesse — « Crousti
 * delice » et « Crousti Delice NGASSAM » se ressemblent à quatre-vingts
 * pour cent, sous un seuil de quatre-vingt-cinq — n'est pas un
 * non-événement. C'est très exactement le cas qu'un humain doit
 * trancher, et que la machine n'a pas le droit de trancher seule. Ces
 * couples sont donc signalés au rapport sans être rapprochés, ce que
 * l'énoncé demande. En deçà de la marge, le silence est le bon
 * comportement : signaler chacune des trois cent vingt-six écritures
 * comme « rapprochement possible » reviendrait à ne rien signaler du
 * tout.
 *
 * **Ce que la méthode ne fait pas.** Elle ne rapproche jamais sur un
 * préfixe : « Djoko » et « Djoko Pokam » restent deux entrées, parce
 * que rien dans un registre de ventes ne dit s'il s'agit d'une même
 * personne abrégée ou de deux membres d'une même famille. La question
 * se règle avec la coordination, pas avec une heuristique.
 */
class RapprochementArtisans
{
    /**
     * @param  float  $seuil  Pourcentage de similarité au-delà duquel deux
     *                        écritures désignent la même personne.
     * @param  float  $marge  Largeur de la zone de doute, sous le seuil.
     */
    public function __construct(
        protected float $seuil = 85.0,
        protected float $marge = 10.0,
    ) {}

    /**
     * @param  array<int, string>  $noms  Noms bruts, répétitions comprises
     */
    public function regrouper(array $noms): ResultatRapprochement
    {
        $occurrences = [];

        foreach ($noms as $nom) {
            $nom = Normalisation::lisible($nom);

            if ($nom === '') {
                continue;
            }

            $occurrences[$nom] = ($occurrences[$nom] ?? 0) + 1;
        }

        // Ordre de traitement déterministe : le plus fréquent d'abord,
        // puis le plus court, puis l'alphabet. Sans cet ordre, deux
        // exécutions sur le même fichier pourraient retenir des formes
        // différentes selon l'ordre de parcours du tableau, et le
        // rapport cesserait d'être reproductible.
        uksort($occurrences, function (string $gauche, string $droite) use ($occurrences): int {
            return ($occurrences[$droite] <=> $occurrences[$gauche])
                ?: (mb_strlen($gauche) <=> mb_strlen($droite))
                ?: strcmp($gauche, $droite);
        });

        /** @var array<string, array{comparable: string, variantes: array<int, string>}> $groupes */
        $groupes = [];
        $canoniqueParNom = [];
        $doutes = [];

        foreach ($occurrences as $nom => $_) {
            $comparable = Normalisation::comparable($nom);

            if ($comparable === '') {
                continue;
            }

            [$meilleurCanonique, $meilleurScore] = $this->meilleurCandidat($comparable, $groupes);

            if ($meilleurCanonique !== null && $meilleurScore >= $this->seuil) {
                $groupes[$meilleurCanonique]['variantes'][] = $nom;
                $canoniqueParNom[$nom] = $meilleurCanonique;

                continue;
            }

            if ($meilleurCanonique !== null && $meilleurScore >= $this->seuil - $this->marge) {
                $doutes[] = [
                    'nom' => $nom,
                    'candidat' => $meilleurCanonique,
                    'score' => round($meilleurScore, 1),
                ];
            }

            $groupes[$nom] = ['comparable' => $comparable, 'variantes' => [$nom]];
            $canoniqueParNom[$nom] = $nom;
        }

        return new ResultatRapprochement(
            canoniqueParNom: $canoniqueParNom,
            variantesParCanonique: array_map(fn (array $groupe) => $groupe['variantes'], $groupes),
            doutes: $doutes,
            seuil: $this->seuil,
            marge: $this->marge,
        );
    }

    /**
     * @param  array<string, array{comparable: string, variantes: array<int, string>}>  $groupes
     * @return array{0: ?string, 1: float}
     */
    protected function meilleurCandidat(string $comparable, array $groupes): array
    {
        $meilleurCanonique = null;
        $meilleurScore = 0.0;

        foreach ($groupes as $canonique => $groupe) {
            $score = $this->similarite($comparable, $groupe['comparable']);

            if ($score > $meilleurScore) {
                $meilleurScore = $score;
                $meilleurCanonique = (string) $canonique;
            }
        }

        return [$meilleurCanonique, $meilleurScore];
    }

    /**
     * Similarité de deux formes comparables, en pourcentage.
     *
     * `similar_text` n'est pas rigoureusement symétrique : l'ordre des
     * arguments peut changer le résultat de quelques points sur des
     * chaînes de longueurs très différentes. On retient donc le
     * meilleur des deux sens, faute de quoi le regroupement dépendrait
     * de l'ordre de lecture du fichier — c'est-à-dire cesserait d'être
     * reproductible, ce qu'on ne peut pas défendre sur des données
     * comptables.
     */
    public function similarite(string $gauche, string $droite): float
    {
        if ($gauche === $droite) {
            return 100.0;
        }

        similar_text($gauche, $droite, $premier);
        similar_text($droite, $gauche, $second);

        return max((float) $premier, (float) $second);
    }
}
