<?php

namespace Modules\Commerce\Fiches;

/**
 * Le résultat de l'analyse d'une fiche technique — une lecture, pas une
 * vérité.
 *
 * Rien ici n'est enregistré tel quel. L'objet alimente un formulaire que
 * l'agent relit, corrige et complète avant d'enregistrer. C'est la
 * différence entre pré-remplir et décider, et elle est délibérée : une
 * valeur extraite d'un document rédigé par un tiers n'a pas le statut
 * d'une valeur saisie par un agent du village.
 *
 * **Les signalements sont aussi importants que les rubriques.** Une
 * analyse qui ne rend que ce qu'elle a compris laisse croire qu'elle a
 * tout compris. C'est la forme retenue par l'import du registre —
 * anomalies ventilées par nature — et le motif est le même : le repli
 * doit être silencieux pour l'utilisateur et bavard pour celui qui
 * relit.
 */
class FicheAnalysee
{
    /**
     * @param  list<array{rubrique: string, contenu: string}>  $rubriques
     * @param  list<string>  $titres      Titres du document, hors rubriques
     * @param  list<string>  $montants    Montants repérés, jamais reportés au prix
     * @param  list<string>  $signalements Codes d'anomalie, voir les constantes
     */
    public function __construct(
        public readonly array $rubriques = [],
        public readonly array $titres = [],
        public readonly ?string $designation = null,
        public readonly ?string $categorieTexte = null,
        public readonly ?string $description = null,
        public readonly ?string $image = null,
        public readonly ?string $extensionImage = null,
        public readonly array $montants = [],
        public readonly array $signalements = [],
    ) {}

    /** Moins de trois rubriques : le document n'a pas la forme d'une fiche produit. */
    public const STRUCTURE_NON_RECONNUE = 'STRUCTURE_NON_RECONNUE';

    /** Aucune rubrique ne nomme le produit ; l'agent saisira la désignation. */
    public const DESIGNATION_ABSENTE = 'DESIGNATION_ABSENTE';

    /**
     * Plusieurs montants dans la fiche — le cas de l'huile de palmiste,
     * 1 L à 6 000, 0,5 L à 3 000, 0,25 L à 1 500. Le produit est en
     * réalité une famille de conditionnements, que le modèle ne porte
     * pas encore (DT-13). Aucun prix n'est reporté.
     */
    public const PLUSIEURS_MONTANTS = 'PLUSIEURS_MONTANTS';

    /** Le document ne contient aucune image exploitable comme photo. */
    public const IMAGE_ABSENTE = 'IMAGE_ABSENTE';

    public function estExploitable(): bool
    {
        return $this->rubriques !== [];
    }

    /**
     * Les rubriques sous la forme attendue par la colonne `caracteristiques`.
     *
     * @return list<array{rubrique: string, contenu: string}>
     */
    public function pourStockage(): array
    {
        return $this->rubriques;
    }

    /**
     * Les signalements en une phrase française, pour l'écran.
     *
     * Les codes servent aux tests et aux journaux ; l'agent, lui, doit
     * lire ce qui manque sans avoir à traduire une constante.
     */
    public function messages(): array
    {
        $libelles = [
            self::STRUCTURE_NON_RECONNUE => "Le document n'a pas la forme d'une fiche produit : les rubriques n'ont pas pu être découpées. Rien n'a été repris.",
            self::DESIGNATION_ABSENTE => "La fiche ne nomme pas le produit : saisissez la désignation.",
            self::PLUSIEURS_MONTANTS => "La fiche porte plusieurs montants, un par conditionnement. Aucun prix n'a été repris : saisissez celui du produit enregistré.",
            self::IMAGE_ABSENTE => "La fiche ne contient aucune image : ajoutez la photo séparément.",
        ];

        return array_values(array_map(
            fn (string $code): string => $libelles[$code] ?? $code,
            $this->signalements,
        ));
    }
}
