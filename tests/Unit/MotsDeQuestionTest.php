<?php

namespace Tests\Unit;

use Modules\Pilotage\Recherche\MotsDeQuestion;
use PHPUnit\Framework\TestCase;

/**
 * Éprouve l'élagage de l'échafaudage des questions.
 *
 * **Le test qui compte est le second.** Retirer des mots d'une question
 * est facile ; ne jamais la vider l'est moins, et c'est là que se
 * cacherait le défaut. Un filtre qui ramènerait « Quels produits ? » à
 * rien ferait basculer l'assistant en refus — et ce refus serait
 * fabriqué par le filtre, pas constaté sur le corpus. Le système dirait
 * « l'information n'est pas disponible » là où il aurait dû dire « voici
 * tout ce que j'ai ».
 *
 * Aucune base de données ici : `MotsDeQuestion` est une fonction pure
 * sur un tableau de termes déjà normalisés.
 */
class MotsDeQuestionTest extends TestCase
{
    public function test_l_echafaudage_tombe_et_le_reste_demeure(): void
    {
        $this->assertSame(
            ['vannerie'],
            MotsDeQuestion::elaguer(['produits', 'vannerie']),
        );

        $this->assertSame(
            ['artisans', 'vannerie'],
            MotsDeQuestion::elaguer(['liste', 'artisans', 'vannerie']),
        );
    }

    /**
     * Une question entièrement faite d'échafaudage garde le sien.
     *
     * Mieux vaut une réponse large qu'un refus dont la cause serait un
     * filtre et non l'absence d'information dans le corpus.
     */
    public function test_une_question_entierement_generique_n_est_jamais_videe(): void
    {
        $this->assertSame(
            ['produits'],
            MotsDeQuestion::elaguer(['produits']),
        );

        $this->assertSame(
            ['liste', 'objets'],
            MotsDeQuestion::elaguer(['liste', 'objets']),
        );
    }

    /**
     * L'ordre et les répétitions sont préservés.
     *
     * La pondération compte les occurrences : réordonner ou dédupliquer
     * ici fausserait la fréquence des termes, donc le TF.
     */
    public function test_l_ordre_et_les_repetitions_survivent(): void
    {
        $this->assertSame(
            ['panier', 'raphia', 'panier'],
            MotsDeQuestion::elaguer(['panier', 'produit', 'raphia', 'panier']),
        );
    }

    /**
     * La liste ne recoupe pas celle des mots vides.
     *
     * Les deux écartent des termes pour des motifs opposés — l'une
     * grammaticaux et côté question seulement, l'autre les mots outils du
     * français des deux côtés. Un terme présent dans les deux signalerait
     * qu'on a cessé de savoir laquelle sert à quoi.
     */
    public function test_aucun_terme_n_appartient_aussi_aux_mots_vides(): void
    {
        $communs = array_intersect(
            MotsDeQuestion::liste(),
            \Modules\Pilotage\Indexation\MotsVides::liste(),
        );

        $this->assertSame([], array_values($communs));
    }
}
