<?php

namespace App\Import;

use Illuminate\Support\Carbon;

/**
 * Une ligne du registre de ventes transcrit, une fois lue et normalisée.
 *
 * L'objet est **immuable et sans dépendance à la base** : il représente
 * ce que le fichier dit, pas ce que le système en fera. C'est ce qui
 * permet à l'import de se dérouler en deux temps — lire tout le
 * registre, puis écrire — et au rapport de compter des lignes plutôt
 * que des enregistrements.
 *
 * Chaque approximation faite à la lecture — une date reprise de la
 * ligne précédente, une quantité déduite du montant — laisse une
 * anomalie derrière elle. Aucune n'est silencieuse : c'est la
 * différence entre un import qui nettoie et un import qui maquille.
 */
class LigneRegistre
{
    // === Anomalies de date ===
    public const DATE_REPRISE = 'Date reprise de la ligne précédente';

    public const DATE_ANNEE_DEDUITE = 'Année déduite de la ligne précédente';

    public const DATE_INVALIDE = 'Date invalide dans le registre';

    public const DATE_INVRAISEMBLABLE = 'Date hors de la période plausible du registre';

    public const DATE_INDETERMINABLE = 'Aucune date exploitable';

    // === Anomalies de boutique ===
    public const BOUTIQUE_ABSENTE = 'Code de boutique absent';

    // === Anomalies d'espace locatif ===
    public const ESPACE_ABSENT = 'Aucun espace locatif rattaché à cet artisan';

    public const ESPACE_INTROUVABLE = 'Espace locatif nommé au registre mais absent du parc';

    public const OCCUPATION_REFUSEE = 'Occupation refusée : l\'espace est déjà attribué sur la période';

    public const BOUTIQUE_REPRISE = 'Code de boutique repris de la ligne précédente';

    public const BOUTIQUE_HORS_PARC = 'Code hors du parc des dix-sept boutiques';

    // === Anomalies d'artisan ===
    public const ARTISAN_ABSENT = 'Artisan non identifiable';

    public const ARTISAN_REPRIS = 'Nom d\'artisan repris de la ligne précédente';

    public const ARTISAN_RAPPROCHEMENT_ECARTE = 'Rapprochement d\'artisan écarté : sous le seuil';

    // === Anomalies de produit ===
    public const DESIGNATION_ABSENTE = 'Désignation absente';

    public const DESIGNATION_REPRISE = 'Désignation reprise de la ligne précédente';

    // === Anomalies de montants ===
    public const QUANTITE_DEDUITE = 'Quantité déduite du montant et du prix';

    public const PRIX_DEDUIT = 'Prix unitaire déduit du montant et de la quantité';

    public const MONTANT_DEDUIT = 'Montant déduit de la quantité et du prix';

    public const VALEURS_INSUFFISANTES = 'Quantité, prix et montant trop lacunaires pour une vente';

    public const ECART_SIGNALE_A_LA_SOURCE = 'Écart signalé à la source (ECART)';

    public const ECART_DE_CALCUL = 'Écart de calcul : montant transcrit différent de quantité × prix';

    /**
     * @param  array<string, string>  $brut  Colonnes du fichier, telles quelles
     * @param  array<int, string>  $anomalies
     */
    public function __construct(
        public readonly int $numero,
        public readonly string $empreinte,
        public readonly array $brut,
        public readonly ?Carbon $date,
        public readonly string $codeBoutiqueSource,
        public readonly string $codeBoutique,
        public readonly ?string $nomArtisan,
        public readonly string $designation,
        public readonly string $conditionnement,
        public readonly ?int $quantite,
        public readonly ?int $prixUnitaire,
        public readonly ?int $montantTranscrit,
        public readonly bool $ecartSignaleALaSource,
        public array $anomalies = [],
        /**
         * Code de l'espace locatif où l'artisan est installé, quand la
         * coordination l'a établi.
         *
         * Il ne vient pas du cahier de ventes — celui-ci ne note aucun
         * emplacement — mais de la table de correspondance que la
         * coordination remplit à partir de l'état de recouvrement des
         * redevances. C'est une décision de gestion recopiée dans le
         * registre, pas une donnée relevée, et l'import ne s'en sert que
         * pour retrouver un espace déjà semé : il n'en crée jamais.
         */
        public readonly string $espaceLocatif = '',
        /**
         * Nom de l'occupant tel qu'il figure au parc, quand le
         * rapprochement a été tranché à la main.
         *
         * Renseigné, il fait autorité sur le rapprochement automatique :
         * une personne a lu les deux noms et décidé qu'il s'agissait du
         * même artisan.
         */
        public readonly string $nomArtisanOfficiel = '',
        /**
         * Redevance mensuelle convenue pour l'espace, en francs.
         *
         * Elle ne se déduit d'aucune surface : c'est un forfait négocié
         * local par local par la coordination, relevé sur l'état de
         * recouvrement des redevances (arbitrage A-01). L'attribution la
         * fige à sa création ; le registre de ventes ne fait que la
         * transporter jusque-là.
         */
        public readonly ?int $redevanceConvenue = null,
        /**
         * Code du corps de métier de l'artisan — `AGR`, `MED`, `SCU`…
         *
         * Il vient de la colonne « métier » de l'état de recouvrement,
         * rangée sous les quatorze secteurs officiels dont
         * `CorpsMetierSeeder` fait autorité. Le cahier de ventes, lui,
         * ne dit pas de quel métier relève un artisan.
         */
        public readonly string $corpsMetier = '',
    ) {}

    public function signaler(string $anomalie): void
    {
        if (! in_array($anomalie, $this->anomalies, strict: true)) {
            $this->anomalies[] = $anomalie;
        }
    }

    public function estSignalee(): bool
    {
        return $this->anomalies !== [];
    }

    public function porte(string $anomalie): bool
    {
        return in_array($anomalie, $this->anomalies, strict: true);
    }

    /**
     * La ligne a-t-elle de quoi devenir une vente ?
     *
     * Trois conditions, et rien de plus : un objet à vendre, une
     * quantité, un prix. La date manquante n'en fait pas partie — elle
     * est reprise de la ligne précédente — et l'artisan non plus, qui
     * bascule sur « Non identifié ».
     */
    public function estVendable(): bool
    {
        return $this->designation !== ''
            && $this->quantite !== null && $this->quantite > 0
            && $this->prixUnitaire !== null && $this->prixUnitaire > 0
            && $this->date !== null;
    }

    /**
     * Montant que le système retiendra : l'invariant `prix × quantité`.
     *
     * Le montant transcrit n'est jamais recopié tel quel dans la vente.
     * Ce n'est pas une correction de la source — ni le prix ni la
     * quantité ne sont retouchés pour faire coller le total — mais
     * l'application d'une règle à laquelle aucune vente du système
     * n'échappe. L'écart entre les deux valeurs est consigné au
     * rapport plutôt que résorbé.
     */
    public function montantCalcule(): ?int
    {
        if ($this->quantite === null || $this->prixUnitaire === null) {
            return null;
        }

        return $this->quantite * $this->prixUnitaire;
    }

    public function ecartDeCalcul(): ?int
    {
        $calcule = $this->montantCalcule();

        if ($calcule === null || $this->montantTranscrit === null) {
            return null;
        }

        return $this->montantTranscrit - $calcule;
    }

    public function enEcartDeCalcul(): bool
    {
        $ecart = $this->ecartDeCalcul();

        return $ecart !== null && $ecart !== 0;
    }

    public function libelleAnomalies(): string
    {
        return implode(' | ', $this->anomalies);
    }
}
