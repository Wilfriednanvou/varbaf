<?php

namespace Modules\Pilotage\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Artisanat\Enums\NatureContenant;
use Modules\Artisanat\Models\EspaceLocatif;
use Modules\Commerce\Enums\EtatVente;
use Modules\Commerce\Enums\ProvenanceClient;
use Modules\Commerce\Models\Produit;
use Modules\Commerce\Models\Vente;
use Modules\Pilotage\Data\FiltreRapport;
use Modules\Tresorerie\Enums\EtatSectionCaisse;
use Modules\Tresorerie\Enums\SensMouvementCaisse;
use Modules\Tresorerie\Models\CampagneReversement;

/**
 * Point de calcul unique des indicateurs du tableau de bord.
 *
 * **Aucun widget ne requête la base.** C'est la raison d'être de ce
 * service : sans lui, la définition du chiffre d'affaires se
 * retrouverait dupliquée dans chaque écran qui l'affiche, et deux
 * écrans finiraient par ne plus dire le même chiffre. Ici, « chiffre
 * d'affaires » a une définition, et une seule.
 *
 * **Uniquement des agrégats.** Aucune méthode ne charge de collection
 * complète. Les totaux sont calculés par la base, les ventilations
 * rendent une ligne par axe — dix-sept boutiques, quelques dizaines
 * d'artisans — jamais la liste des ventes qui les composent. Le
 * registre transcrit laisse attendre plusieurs milliers de ventes par
 * exercice : un `sum()` en PHP sur une collection hydratée deviendrait
 * ingérable, et le deviendrait silencieusement.
 *
 * **Une vente annulée ne compte nulle part.** Toutes les requêtes
 * filtrent sur `etat = VALIDEE`. C'est l'invariant que le test du
 * module éprouve indicateur par indicateur : une annulation ne se
 * corrige pas dans les états, elle en disparaît.
 *
 * **Dépendance descendante.** Le Pilotage lit Artisanat, Commerce et
 * Trésorerie ; aucun d'eux ne le connaît. C'est la note d'architecture
 * de `docs/modele-classes.md` : le village n'expose pas
 * `getStatistiques()`, ces lectures traversent les modules et vivent
 * ici.
 */
class RapportService
{
    // =================================================================
    //  TRÉSORERIE
    // =================================================================

    /**
     * Solde de la section ouverte de chaque caisse.
     *
     * Solde d'ouverture plus le cumul signé des mouvements, calculé par
     * la base. Une caisse sans section ouverte n'apparaît pas : elle
     * n'a pas de solde courant, elle a un solde de clôture.
     *
     * @return array<int, array{code: string, libelle: string, solde: int}>
     */
    public function soldesParCaisse(): array
    {
        return DB::table('caisses as c')
            ->join('sections_caisse as s', function ($jointure): void {
                $jointure->on('s.caisse_id', '=', 'c.id')
                    ->where('s.etat', '=', EtatSectionCaisse::OUVERTE->value);
            })
            ->leftJoin('mouvements_caisse as m', 'm.section_id', '=', 's.id')
            ->groupBy('c.id', 'c.code', 'c.libelle', 's.solde_ouverture')
            ->orderBy('c.code')
            ->selectRaw(
                'c.code as code, c.libelle as libelle, '
                ."s.solde_ouverture + coalesce(sum(case when m.sens = '"
                .SensMouvementCaisse::ENTREE->value
                ."' then m.montant else -m.montant end), 0) as solde"
            )
            ->get()
            ->map(fn ($ligne): array => [
                'code' => (string) $ligne->code,
                'libelle' => (string) $ligne->libelle,
                'solde' => (int) $ligne->solde,
            ])
            ->all();
    }

    /**
     * Trésorerie disponible, toutes caisses confondues.
     */
    public function soldeDeCaisseConsolide(): int
    {
        return array_sum(array_column($this->soldesParCaisse(), 'solde'));
    }

    // =================================================================
    //  RECETTES
    // =================================================================

    public function chiffreAffaires(FiltreRapport $filtre): int
    {
        return (int) $this->ventesValidees($filtre)->sum('ventes.montant_total');
    }

    public function recettesDeCommission(FiltreRapport $filtre): int
    {
        return (int) $this->ventesValidees($filtre)->sum('ventes.montant_commission');
    }

    public function nombreDeVentes(FiltreRapport $filtre): int
    {
        return $this->ventesValidees($filtre)->count();
    }

    /**
     * Dettes du village envers les artisans : la somme des parts dues
     * qui n'ont pas encore été rattachées à une campagne validée.
     *
     * Sans filtre de date ni d'exercice : une dette n'a pas de période,
     * elle est due jusqu'à son reversement (RG-13). La rattacher à un
     * intervalle donnerait un chiffre juste sur la période et faux sur
     * la situation.
     */
    public function dettesEnversLesArtisans(): int
    {
        return (int) Vente::query()
            ->where('etat', EtatVente::VALIDEE->value)
            ->whereNull('campagne_reversement_id')
            ->sum('part_artisan');
    }

    /**
     * Total décaissé lors de la dernière campagne validée.
     */
    public function montantDernierReversement(): int
    {
        return (int) (CampagneReversement::query()
            ->validee()
            ->orderByDesc('date_validation')
            ->value('montant_total') ?? 0);
    }

    public function dernierReversement(): ?CampagneReversement
    {
        return CampagneReversement::query()
            ->validee()
            ->orderByDesc('date_validation')
            ->first();
    }

    // =================================================================
    //  VENTILATIONS DES VENTES
    // =================================================================

    /**
     * @return array<int, array{libelle: string, detail: ?string, nombre: int, total: int}>
     */
    public function ventesParBoutique(FiltreRapport $filtre): array
    {
        return $this->ventilation(
            $filtre,
            'boutiques',
            'boutiques.id',
            'ventes.boutique_id',
            'boutiques.numero',
            null,
        );
    }

    /**
     * @return array<int, array{libelle: string, detail: ?string, nombre: int, total: int}>
     */
    public function ventesParArtisan(FiltreRapport $filtre): array
    {
        return $this->ventilation(
            $filtre,
            'artisans',
            'artisans.id',
            'ventes.artisan_id',
            'artisans.nom',
            'artisans.matricule',
        );
    }

    /**
     * @return array<int, array{libelle: string, detail: ?string, nombre: int, total: int}>
     */
    public function ventesParVendeur(FiltreRapport $filtre): array
    {
        return $this->ventilation(
            $filtre,
            'agents',
            'agents.id',
            'ventes.vendeur_id',
            'agents.nom',
            'agents.prenom',
        );
    }

    /**
     * Répartition des ventes par provenance déclarée du client.
     *
     * La provenance est facultative à la saisie : les ventes sans
     * provenance forment une ligne à part plutôt que de disparaître,
     * sans quoi le total de la ventilation ne correspondrait pas au
     * chiffre d'affaires et l'écart serait inexplicable à la lecture.
     *
     * @return array<int, array{libelle: string, nombre: int, total: int}>
     */
    public function ventesParProvenanceClient(FiltreRapport $filtre): array
    {
        return $this->ventesValidees($filtre)
            ->groupBy('ventes.provenance_client')
            ->orderByDesc('total')
            ->selectRaw('ventes.provenance_client as provenance, count(*) as nombre, sum(ventes.montant_total) as total')
            ->get()
            ->map(fn ($ligne): array => [
                'libelle' => $ligne->provenance
                    ? (ProvenanceClient::tryFrom((string) $ligne->provenance)?->getLabel() ?? (string) $ligne->provenance)
                    : 'Non renseignée',
                'nombre' => (int) $ligne->nombre,
                'total' => (int) $ligne->total,
            ])
            ->all();
    }

    // =================================================================
    //  PARC ET CATALOGUE
    // =================================================================

    /**
     * Taux d'occupation du parc locatif.
     *
     * Le dénominateur est le nombre d'espaces locatifs, non celui des
     * boutiques. Une boutique abritant plusieurs artisans, la rapporter
     * au parc de locaux donnait un taux structurellement faux : deux
     * artisans installés dans le même local comptaient pour une seule
     * occupation, et le village se croyait moins rempli qu'il ne l'est.
     *
     * **Deux périmètres, et ils ne disent pas la même chose.** Sans
     * argument, la méthode couvre tout ce qui se loue — y compris les
     * deux espaces du sous-sol et celui de l'espace vert, entrés au parc
     * le 26/08. En passant `NatureContenant::BOUTIQUE`, elle se restreint
     * aux locaux de vente : c'est le périmètre du taux d'occupation que
     * la coordination présente à sa tutelle, et le mélanger avec le
     * locatif entier ferait varier l'indicateur sans que rien n'ait
     * changé sur le terrain.
     *
     * **L'occupation est comptée par les attributions, pas par la
     * colonne `etat`** — corrigé le 28/08. Cette méthode comptait les
     * espaces dont `etat` valait `OCCUPE`, pendant que les indicateurs
     * du module Artisanat comptaient ceux portant une attribution en
     * cours. Les deux rendaient 24 sur 36, l'import ayant écrit `OCCUPE`
     * sur exactement les espaces attribués — une égalité de circonstance,
     * pas de définition.
     *
     * `etat` n'est jamais mis à jour par une attribution :
     * `AttributionEspace` la lit et ne l'écrit pas. La première
     * attribution créée ou arrivée à terme dans l'application aurait donc
     * séparé les deux chiffres, sans que rien ne le signale, sur un
     * indicateur présenté à la tutelle. `EspaceLocatif::scopeOccupe()`
     * porte désormais la définition unique.
     *
     * Le dénominateur reste le parc du périmètre, et non les seuls
     * espaces attribuables : c'est un taux de remplissage présenté à la
     * tutelle, pas un taux de commercialisation. Les indicateurs de
     * l'Artisanat, eux, rapportent aux attribuables — deux questions
     * différentes, deux dénominateurs assumés, et c'est écrit des deux
     * côtés.
     *
     * @return array{occupes: int, total: int, taux: float}
     */
    public function tauxOccupationEspaces(?NatureContenant $nature = null): array
    {
        $requete = fn (): Builder => EspaceLocatif::query()
            ->when($nature, fn (Builder $q) => $q->whereHas(
                'boutique',
                fn (Builder $b) => $b->where('nature', $nature->value),
            ));

        $total = $requete()->count();
        $occupes = $requete()->occupe()->count();

        return [
            'occupes' => $occupes,
            'total' => $total,
            'taux' => $total > 0 ? round($occupes * 100 / $total, 1) : 0.0,
        ];
    }

    public function nombreDeProduitsSousLeSeuil(): int
    {
        return Produit::query()->sousLeSeuil()->count();
    }

    /**
     * Les produits en rupture imminente, bornés.
     *
     * `limit` n'est pas une commodité d'affichage : c'est ce qui
     * garantit que la méthode reste un agrégat borné même le jour où
     * cent produits passeraient sous leur seuil en même temps.
     *
     * @return array<int, array{reference: string, designation: string, boutique: ?string, seuil: int, stock: int}>
     */
    public function produitsSousLeSeuil(int $limite = 10): array
    {
        $cumul = 'coalesce((select sum(case when sens = \'ENTREE\' then quantite else -quantite end)'
            .' from mouvements_stock where mouvements_stock.produit_id = produits.id), 0)';

        return Produit::query()
            ->sousLeSeuil()
            ->leftJoin('boutiques', 'boutiques.id', '=', 'produits.boutique_id')
            ->orderByRaw("{$cumul} asc")
            ->limit($limite)
            ->selectRaw(
                'produits.reference as reference, produits.designation as designation, '
                ."boutiques.numero as boutique, produits.seuil_alerte as seuil, {$cumul} as stock"
            )
            ->get()
            ->map(fn ($ligne): array => [
                'reference' => (string) $ligne->reference,
                'designation' => (string) $ligne->designation,
                'boutique' => $ligne->boutique ? (string) $ligne->boutique : null,
                'seuil' => (int) $ligne->seuil,
                'stock' => (int) $ligne->stock,
            ])
            ->all();
    }

    // =================================================================
    //  FABRIQUE DE REQUÊTES
    // =================================================================

    /**
     * Socle commun de tous les indicateurs de vente.
     *
     * Les colonnes sont qualifiées par leur table : `etat` existe aussi
     * sur `boutiques`, et une ventilation par boutique produirait sinon
     * une ambiguïté SQL au lieu d'un chiffre.
     */
    protected function ventesValidees(FiltreRapport $filtre): Builder
    {
        return Vente::query()
            ->where('ventes.etat', EtatVente::VALIDEE->value)
            ->when(
                $filtre->exerciceId,
                fn (Builder $requete, int $exerciceId) => $requete->where('ventes.exercice_id', $exerciceId),
            )
            ->when(
                $filtre->du,
                fn (Builder $requete, $du) => $requete->whereDate('ventes.date_vente', '>=', $du),
            )
            ->when(
                $filtre->au,
                fn (Builder $requete, $au) => $requete->whereDate('ventes.date_vente', '<=', $au),
            );
    }

    /**
     * Ventilation du chiffre d'affaires selon un axe.
     *
     * Une ligne par valeur de l'axe, jamais la liste des ventes : la
     * base groupe et totalise, PHP ne fait que mettre en forme.
     *
     * @return array<int, array{libelle: string, detail: ?string, nombre: int, total: int}>
     */
    protected function ventilation(
        FiltreRapport $filtre,
        string $table,
        string $cleEtrangere,
        string $cleLocale,
        string $colonneLibelle,
        ?string $colonneDetail,
    ): array {
        $selection = "{$colonneLibelle} as libelle, count(*) as nombre, sum(ventes.montant_total) as total";
        $groupes = [$cleEtrangere, $colonneLibelle];

        if ($colonneDetail !== null) {
            $selection = "{$colonneDetail} as detail, {$selection}";
            $groupes[] = $colonneDetail;
        }

        return $this->ventesValidees($filtre)
            ->join($table, $cleEtrangere, '=', $cleLocale)
            ->groupBy($groupes)
            ->orderByDesc('total')
            ->selectRaw($selection)
            ->get()
            ->map(fn ($ligne): array => [
                'libelle' => (string) $ligne->libelle,
                'detail' => $colonneDetail !== null && filled($ligne->detail) ? (string) $ligne->detail : null,
                'nombre' => (int) $ligne->nombre,
                'total' => (int) $ligne->total,
            ])
            ->all();
    }
}
