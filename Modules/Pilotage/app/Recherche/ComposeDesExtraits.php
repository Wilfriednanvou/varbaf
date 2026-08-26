<?php

namespace Modules\Pilotage\Recherche;

use Modules\Pilotage\Enums\TypeFicheLexicale;
use Modules\Pilotage\Indexation\Normalisateur;

/**
 * Fabrique l'extrait affiché sous une réponse.
 *
 * **Ce qu'un extrait doit prouver.** Il ne s'agit pas d'illustrer mais
 * de permettre la vérification : le lecteur doit retrouver, dans le
 * texte de la fiche, le mot qui a motivé le rapprochement. L'extrait est
 * donc centré sur le premier terme de la question effectivement présent,
 * et non sur le début de la fiche — qui pourrait n'avoir aucun rapport
 * avec la question.
 *
 * Le texte affiché est le texte **d'origine**, accents et majuscules
 * compris. La forme normalisée sert à retrouver la position du mot,
 * jamais à l'afficher : montrer « panier tresse raphia » au lieu de
 * « Panier tressé raphia » ferait douter de ce qu'on a réellement lu.
 */
trait ComposeDesExtraits
{
    protected function composerSegment(object $ligne, array $termes, Normalisateur $normalisateur): SegmentTrouve
    {
        return new SegmentTrouve(
            ficheId: (int) $ligne->fiche_id,
            type: TypeFicheLexicale::from((string) $ligne->type),
            sourceId: (int) $ligne->source_id,
            titre: (string) $ligne->titre,
            extrait: $this->extraire((string) ($ligne->texte ?? ''), $termes, $normalisateur),
            similarite: (float) $ligne->similarite,
        );
    }

    /**
     * @param  array<int, string>  $termes  termes de la question, déjà normalisés
     */
    protected function extraire(string $texte, array $termes, Normalisateur $normalisateur): string
    {
        $texte = trim($texte);
        $longueur = (int) config('pilotage.recherche.longueur_extrait', 200);

        if ($texte === '' || mb_strlen($texte) <= $longueur) {
            return $texte;
        }

        $mots = preg_split('/\s+/u', $texte) ?: [];
        $recherches = array_flip($termes);
        $position = null;

        foreach ($mots as $index => $mot) {
            foreach ($normalisateur->decouper($mot) as $normalise) {
                if (isset($recherches[$normalise])) {
                    $position = $index;

                    break 2;
                }
            }
        }

        // Aucun mot de la question dans le texte affichable : le
        // rapprochement vient d'un champ qui n'a pas survécu à la
        // composition. On rend le début plutôt que rien — un extrait
        // vide priverait le lecteur de toute source.
        if ($position === null) {
            return $this->tronquer($texte, $longueur);
        }

        // Une fenêtre de mots autour du terme trouvé, plutôt qu'un
        // découpage au caractère : couper « vanne | rie » au milieu
        // d'un mot rendrait l'extrait illisible là où il doit convaincre.
        $debut = max(0, $position - 8);
        $fenetre = array_slice($mots, $debut, 24);

        $extrait = implode(' ', $fenetre);

        if ($debut > 0) {
            $extrait = '… '.$extrait;
        }

        if ($debut + 24 < count($mots)) {
            $extrait .= ' …';
        }

        return $this->tronquer($extrait, $longueur + 4);
    }

    protected function tronquer(string $texte, int $longueur): string
    {
        return mb_strlen($texte) <= $longueur
            ? $texte
            : rtrim(mb_substr($texte, 0, $longueur)).' …';
    }

    /**
     * Les termes sont issus du `Normalisateur`, qui ne rend que des
     * caractères alphanumériques. La garde est néanmoins explicite :
     * ces valeurs entrent dans une expression SQL interpolée, et une
     * garantie qui repose sur le comportement d'une autre classe doit
     * être vérifiée là où elle est utilisée, pas supposée.
     *
     * @param  array<int, string>  $termes
     * @return array<int, string>
     */
    protected function termesSurs(array $termes): array
    {
        return array_values(array_filter(
            $termes,
            fn (string $terme): bool => preg_match('/^[a-z0-9]{1,60}$/', $terme) === 1,
        ));
    }
}
