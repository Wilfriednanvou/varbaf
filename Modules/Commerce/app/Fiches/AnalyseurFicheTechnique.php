<?php

namespace Modules\Commerce\Fiches;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Facades\Log;
use ZipArchive;

/**
 * Lit une fiche technique Word et en rend les rubriques.
 *
 * **Aucune bibliothèque.** Un `.docx` est une archive ZIP contenant du
 * XML ; `ZipArchive` et `DOMDocument` sont natifs à PHP. Ajouter
 * `phpoffice/phpword` pour lire des paragraphes et un attribut de gras
 * ne franchit pas le seuil que pose CLAUDE.md — un paquet demande une
 * nécessité démontrée, et elle ne l'est pas ici.
 *
 * **Aucun modèle de langage non plus, et c'est un choix de fond.**
 * L'extraction est déterministe : les mêmes octets rendent toujours les
 * mêmes rubriques. Confier ce travail au modèle le placerait en
 * producteur de données entrant en base — dont des montants — c'est-à-dire
 * en amont de la frontière entre l'agrégation calculée et le descriptif,
 * la frontière même sur laquelle repose le volet IA. Ici le modèle n'est
 * pas appelé.
 *
 * **La règle de découpe**, relevée sur les trois fiches réelles du
 * village : un titre de rubrique est un paragraphe court dont *tous* les
 * fragments sont en gras ; tout ce qui suit lui appartient jusqu'au
 * titre suivant. Mesure sur les pièces réelles — 10 rubriques sur 10
 * pour le tabouret royal et pour l'huile de palmiste. La fiche « My Soy »,
 * qui présente une entreprise et deux produits au lieu d'un produit,
 * rend six rubriques hors forme : elle se dégrade, elle ne casse pas.
 *
 * Ce que l'analyseur ne fait **jamais** : reporter un prix, choisir une
 * catégorie, désigner un artisan. Les trois relèvent d'un référentiel du
 * village, et le référentiel fait autorité sur le document — c'est la
 * règle déjà tenue par l'import du registre, qui ne crée plus aucun
 * espace locatif absent du parc.
 */
class AnalyseurFicheTechnique
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** Espace de compatibilité : Word y duplique le contenu des zones de texte. */
    private const MC = 'http://schemas.openxmlformats.org/markup-compatibility/2006';

    /** Longueur au-delà de laquelle un paragraphe gras est une phrase, pas un titre. */
    private const LONGUEUR_TITRE = 60;

    /** En deçà, le document n'a pas la forme d'une fiche produit. */
    private const RUBRIQUES_MINIMUM = 3;

    private const IMAGES = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Numérotation de tête : « 1. », « 10) », « I. », « iv) ».
     * Retirée du libellé — elle appartient à la mise en forme du
     * document, pas au nom de la rubrique.
     */
    private const NUMEROTATION = '/^\s*(?:\d{1,2}|[IVXivx]{1,5})[.)]\s*/u';

    /** Un couple « clé : valeur » sur une seule ligne. */
    private const COUPLE = '/^\s*([\p{L}][^:]{2,40}?)\s*:\s*(.+)$/u';

    /** Un montant en francs CFA, sous les graphies rencontrées au village. */
    private const MONTANT = '/([\d][\d\s\x{00A0}\x{202F}]{2,})\s*(?:f\s?cfa|fcfa|frs?|f)\b/iu';

    public function analyser(string $chemin): FicheAnalysee
    {
        $archive = new ZipArchive;

        if ($archive->open($chemin) !== true) {
            // Un fichier illisible n'est pas une erreur de l'agent : il a
            // pu déposer un .doc ancien ou une archive abîmée. On rend
            // une fiche vide signalée, l'écran le dit, la saisie continue.
            Log::warning('Fiche technique illisible comme archive.', ['chemin' => $chemin]);

            return new FicheAnalysee(signalements: [FicheAnalysee::STRUCTURE_NON_RECONNUE]);
        }

        $xml = $archive->getFromName('word/document.xml');
        [$image, $extension] = $this->imagePrincipale($archive);
        $archive->close();

        if ($xml === false) {
            Log::warning('Fiche technique sans word/document.xml.', ['chemin' => $chemin]);

            return new FicheAnalysee(signalements: [FicheAnalysee::STRUCTURE_NON_RECONNUE]);
        }

        return $this->depuisXml($xml, $image, $extension);
    }

    public function depuisXml(string $xml, ?string $image = null, ?string $extension = null): FicheAnalysee
    {
        $dom = new DOMDocument;

        // Word écrit du XML volumineux et parfois imparfait ; ses
        // avertissements n'ont pas leur place dans le journal de
        // l'application.
        $charge = @$dom->loadXML($xml, LIBXML_NOWARNING | LIBXML_NOERROR);

        if (! $charge) {
            return new FicheAnalysee(signalements: [FicheAnalysee::STRUCTURE_NON_RECONNUE]);
        }

        [$titres, $rubriques] = $this->decouper($dom);

        $couples = $this->couples($rubriques);
        $montants = $this->montants($rubriques);
        $designation = $this->designation($couples, $titres);

        $signalements = [];

        if (count($rubriques) < self::RUBRIQUES_MINIMUM) {
            $signalements[] = FicheAnalysee::STRUCTURE_NON_RECONNUE;
        }

        if ($designation === null) {
            $signalements[] = FicheAnalysee::DESIGNATION_ABSENTE;
        }

        // Un seul montant ne vaut pas davantage : la fiche peut nommer un
        // prix de vente conseillé, un coût de revient ou le prix d'un
        // conditionnement parmi d'autres restés implicites. Le prix du
        // produit enregistré se saisit, toujours.
        if (count($montants) > 1) {
            $signalements[] = FicheAnalysee::PLUSIEURS_MONTANTS;
        }

        if ($image === null) {
            $signalements[] = FicheAnalysee::IMAGE_ABSENTE;
        }

        return new FicheAnalysee(
            rubriques: $rubriques,
            titres: $titres,
            designation: $designation,
            categorieTexte: $couples['catégorie'] ?? $couples['categorie'] ?? null,
            description: $this->description($rubriques),
            image: $image,
            extensionImage: $extension,
            montants: $montants,
            signalements: $signalements,
        );
    }

    /**
     * Découpe le document en titres et rubriques.
     *
     * Une rubrique sans contenu n'est pas une rubrique : c'est le titre
     * du document — « FICHE TECHNIQUE DU PRODUIT », puis le nom du
     * produit. Les deux fiches régulières en portent deux ; les retenir
     * parmi les rubriques polluerait la caractérisation d'entrées vides.
     *
     * @return array{0: list<string>, 1: list<array{rubrique: string, contenu: string}>}
     */
    private function decouper(DOMDocument $dom): array
    {
        $blocs = [];

        foreach ($dom->getElementsByTagNameNS(self::W, 'p') as $paragraphe) {
            if ($this->dansUnRepli($paragraphe)) {
                continue;
            }

            $texte = $this->normaliser($this->texte($paragraphe));

            if ($texte === '') {
                continue;
            }

            $estTitre = $this->toutEnGras($paragraphe)
                && mb_strlen($texte) <= self::LONGUEUR_TITRE;

            if ($estTitre) {
                $blocs[] = [
                    'rubrique' => rtrim((string) preg_replace(self::NUMEROTATION, '', $texte), " :"),
                    'lignes' => [],
                ];

                continue;
            }

            if ($blocs !== []) {
                $blocs[count($blocs) - 1]['lignes'][] = $texte;
            }
        }

        $titres = [];
        $rubriques = [];

        foreach ($blocs as $bloc) {
            if ($bloc['lignes'] === []) {
                // Un même titre revient deux fois lorsque Word le pose à
                // la fois dans une zone de texte et dans son repli ; le
                // filtre `mc:Fallback` en attrape la plupart, ce test
                // ferme le reste.
                if (! in_array($bloc['rubrique'], $titres, true)) {
                    $titres[] = $bloc['rubrique'];
                }

                continue;
            }

            $rubriques[] = [
                'rubrique' => $bloc['rubrique'],
                'contenu' => implode("\n", $bloc['lignes']),
            ];
        }

        return [$titres, $rubriques];
    }

    /**
     * Word duplique le contenu des zones de texte dans `mc:Fallback`,
     * pour les lecteurs qui ne savent pas rendre `mc:Choice`. Sans ce
     * filtre, le titre de la fiche du tabouret apparaît quatre fois.
     */
    private function dansUnRepli(DOMNode $noeud): bool
    {
        for ($n = $noeud->parentNode; $n instanceof DOMElement; $n = $n->parentNode) {
            if ($n->namespaceURI === self::MC && $n->localName === 'Fallback') {
                return true;
            }
        }

        return false;
    }

    /**
     * Vrai si tous les fragments porteurs de texte sont en gras.
     *
     * *Tous*, et non « le premier » : les fiches du village mettent le
     * titre entier en gras, tandis qu'une ligne de contenu commence
     * souvent par une clé en gras — « **Nom :** Tabouret Royal ».
     * Interroger le premier fragment seul ferait de chaque ligne de
     * contenu un titre, et la fiche entière se réduirait à des rubriques
     * vides.
     */
    private function toutEnGras(DOMElement $paragraphe): bool
    {
        $porteurs = 0;

        foreach ($paragraphe->getElementsByTagNameNS(self::W, 'r') as $fragment) {
            if (trim($this->texte($fragment)) === '') {
                continue;
            }

            $porteurs++;

            $proprietes = $fragment->getElementsByTagNameNS(self::W, 'rPr')->item(0);
            $gras = $proprietes?->getElementsByTagNameNS(self::W, 'b')->item(0);

            if (! $gras instanceof DOMElement) {
                return false;
            }

            // `<w:b/>` sans attribut vaut vrai ; `w:val="0"` le désactive.
            if (in_array($gras->getAttributeNS(self::W, 'val'), ['0', 'false'], true)) {
                return false;
            }
        }

        return $porteurs > 0;
    }

    private function texte(DOMElement $element): string
    {
        $texte = '';

        foreach ($element->getElementsByTagNameNS(self::W, 't') as $noeud) {
            $texte .= $noeud->textContent;
        }

        return $texte;
    }

    /** Espaces insécables et retours de Word ramenés à une espace simple. */
    private function normaliser(string $texte): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace(["\u{00A0}", "\u{202F}"], ' ', $texte)));
    }

    /**
     * Les couples « clé : valeur » de toutes les rubriques, indexés par
     * clé en minuscules. La première occurrence gagne : les fiches
     * nomment le produit en tête.
     *
     * @param  list<array{rubrique: string, contenu: string}>  $rubriques
     * @return array<string, string>
     */
    private function couples(array $rubriques): array
    {
        $couples = [];

        foreach ($rubriques as $rubrique) {
            foreach (explode("\n", $rubrique['contenu']) as $ligne) {
                if (! preg_match(self::COUPLE, $ligne, $trouve)) {
                    continue;
                }

                $cle = mb_strtolower(trim($trouve[1]));
                $valeur = trim($trouve[2]);

                // Une « valeur » très longue est une phrase qui contient
                // un deux-points, pas un couple.
                if ($valeur === '' || mb_strlen($valeur) > 90) {
                    continue;
                }

                $couples[$cle] ??= $valeur;
            }
        }

        return $couples;
    }

    /**
     * @param  array<string, string>  $couples
     * @param  list<string>  $titres
     */
    private function designation(array $couples, array $titres): ?string
    {
        foreach (['nom du produit', 'nom', 'désignation', 'designation', 'produit'] as $cle) {
            if (isset($couples[$cle])) {
                return $couples[$cle];
            }
        }

        // À défaut, le dernier titre du document : les fiches régulières
        // posent « FICHE TECHNIQUE DU PRODUIT » puis le nom du produit.
        $dernier = end($titres);

        if (is_string($dernier) && ! str_contains(mb_strtolower($dernier), 'fiche technique')) {
            return $dernier;
        }

        return null;
    }

    /**
     * La rubrique de description, telle que les fiches la nomment.
     *
     * @param  list<array{rubrique: string, contenu: string}>  $rubriques
     */
    private function description(array $rubriques): ?string
    {
        foreach ($rubriques as $rubrique) {
            if (str_starts_with(mb_strtolower($rubrique['rubrique']), 'description')) {
                return $rubrique['contenu'];
            }
        }

        return null;
    }

    /**
     * Les montants repérés, pour être **signalés**, jamais reportés.
     *
     * @param  list<array{rubrique: string, contenu: string}>  $rubriques
     * @return list<string>
     */
    private function montants(array $rubriques): array
    {
        $montants = [];

        foreach ($rubriques as $rubrique) {
            if (preg_match_all(self::MONTANT, $rubrique['contenu'], $trouves)) {
                foreach ($trouves[1] as $montant) {
                    $montants[] = $this->normaliser($montant);
                }
            }
        }

        return array_values(array_unique($montants));
    }

    /**
     * La plus grosse image de l'archive.
     *
     * Les trois fiches relevées n'en portent qu'une. « La plus grosse »
     * plutôt que « la première » couvre le cas d'un logo d'en-tête
     * accompagnant la photo du produit : le logo est toujours le plus
     * léger des deux.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function imagePrincipale(ZipArchive $archive): array
    {
        $meilleure = null;

        for ($i = 0; $i < $archive->numFiles; $i++) {
            $entree = $archive->statIndex($i);

            if ($entree === false || ! str_starts_with($entree['name'], 'word/media/')) {
                continue;
            }

            $extension = mb_strtolower(pathinfo($entree['name'], PATHINFO_EXTENSION));

            if (! in_array($extension, self::IMAGES, true)) {
                continue;
            }

            if ($meilleure === null || $entree['size'] > $meilleure['size']) {
                $meilleure = $entree + ['extension' => $extension];
            }
        }

        if ($meilleure === null) {
            return [null, null];
        }

        $contenu = $archive->getFromName($meilleure['name']);

        return $contenu === false
            ? [null, null]
            : [$contenu, $meilleure['extension']];
    }
}
