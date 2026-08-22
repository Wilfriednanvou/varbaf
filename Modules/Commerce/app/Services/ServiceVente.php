<?php

namespace Modules\Commerce\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Contracts\JournalDeCaisse;
use Modules\Commerce\Enums\EtatVente;
use Modules\Commerce\Enums\ModeReglement;
use Modules\Commerce\Enums\SensMouvementStock;
use Modules\Commerce\Enums\TypeMouvementStock;
use Modules\Commerce\Exceptions\VenteInvalideException;
use Modules\Commerce\Models\LigneVente;
use Modules\Commerce\Models\Produit;
use Modules\Commerce\Models\TauxCommission;
use Modules\Commerce\Models\Vente;
use Modules\Socle\Models\Agent;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;

/**
 * Point d'entrée unique de l'enregistrement des ventes.
 *
 * **Atomicité.** Une vente met en jeu trois écritures distinctes : ses
 * lignes, les sorties au journal de stock, et l'encaissement au
 * brouillard de caisse. Les trois réussissent ensemble ou aucune n'a
 * lieu. Le scénario redouté est la panne au troisième temps : un stock
 * décrémenté sans vente correspondante, découvert des semaines plus
 * tard à l'inventaire, sans moyen de savoir ce qui manque. La
 * transaction englobante est donc posée ici et non dans chacun des
 * services appelés.
 *
 * **Aucune écriture directe.** Le stock passe par
 * `ServiceMouvementStock`, la caisse par le port `JournalDeCaisse`. Ce
 * service n'insère lui-même que dans `ventes` et `lignes_vente`.
 *
 * **Arrondi.** La commission est arrondie à l'unité — le franc CFA n'a
 * pas de subdivision — et la part artisan est obtenue **par
 * différence**, jamais par un second arrondi. C'est la seule façon de
 * garantir que les deux parts totalisent exactement le montant
 * encaissé : deux arrondis indépendants laisseraient un franc
 * s'évaporer ou apparaître, et l'écart se retrouverait au
 * rapprochement de caisse.
 */
class ServiceVente
{
    public function __construct(
        protected ServiceMouvementStock $stock,
        protected JournalDeCaisse $caisse,
    ) {}

    /**
     * Enregistre une vente et toutes ses conséquences.
     *
     * @param  array<int, array{produit_id: int|string, quantite: int}>  $lignes
     * @param  array<string, mixed>  $client
     *
     * @throws VenteInvalideException
     */
    public function enregistrer(
        array $lignes,
        ModeReglement $modeReglement = ModeReglement::ESPECES,
        array $client = [],
        string|Carbon|null $dateVente = null,
    ): Vente {
        $dateVente = $dateVente ? Carbon::parse($dateVente) : now();

        // === Contrôles préalables, avant toute écriture ===
        $lignes = array_values(array_filter($lignes, fn (array $ligne) => (int) ($ligne['quantite'] ?? 0) > 0));

        if ($lignes === []) {
            throw VenteInvalideException::sansLigne();
        }

        $produits = $this->rassemblerLesProduits($lignes);
        $boutiqueId = $this->boutiqueUnique($produits);
        $vendeur = $this->vendeurDuCompteConnecte();
        $exercice = Exercice::courant();

        if (! $exercice) {
            throw VenteInvalideException::exerciceIndisponible();
        }

        // Lève si aucun taux n'est en vigueur : une vente qu'on ne sait
        // pas commissionner ne s'enregistre pas.
        $taux = TauxCommission::getTauxEnVigueur($dateVente);

        $montantTotal = $this->calculerMontantTotal($lignes, $produits);
        [$commission, $partArtisan] = $this->repartir($montantTotal, $taux);

        // L'artisan est celui du premier produit : ils partagent tous la
        // même boutique, donc le même attributaire.
        $premierProduit = $produits[(int) $lignes[0]['produit_id']];

        return DB::transaction(function () use (
            $lignes, $produits, $boutiqueId, $vendeur, $exercice, $premierProduit,
            $dateVente, $modeReglement, $client, $montantTotal, $taux, $commission, $partArtisan
        ): Vente {
            $vente = new Vente([
                'date_vente' => $dateVente,
                'mode_reglement' => $modeReglement,
                'nom_client' => $client['nom_client'] ?? null,
                'contact_client' => $client['contact_client'] ?? null,
                'accepte_notifications' => (bool) ($client['accepte_notifications'] ?? false),
                'provenance_client' => $client['provenance_client'] ?? null,
                'boutique_id' => $boutiqueId,
                'artisan_id' => $premierProduit->artisan_id,
                'exercice_id' => $exercice->getKey(),
            ]);

            $vente->vendeur_id = $vendeur->getKey();
            $vente->montant_total = $montantTotal;
            $vente->taux_commission = $taux;
            $vente->montant_commission = $commission;
            $vente->part_artisan = $partArtisan;
            $vente->etat = EtatVente::VALIDEE;
            $vente->save();

            // 1. Les lignes, entièrement figées.
            foreach ($lignes as $ligne) {
                $produit = $produits[(int) $ligne['produit_id']];
                $quantite = (int) $ligne['quantite'];

                $prixUnitaire = (int) round((float) $produit->prix_unitaire);

                LigneVente::create([
                    'vente_id' => $vente->getKey(),
                    'produit_id' => $produit->getKey(),
                    'reference_produit' => $produit->reference,
                    'designation' => $produit->designation,
                    'prix_unitaire' => $prixUnitaire,
                    'quantite' => $quantite,
                    'montant_ligne' => $prixUnitaire * $quantite,
                ]);

                // 2. Le stock, par le service — jamais en direct. C'est
                //    lui qui refuse de sortir plus que le solde.
                $this->stock->enregistrer(
                    $produit,
                    SensMouvementStock::SORTIE,
                    TypeMouvementStock::VENTE,
                    $quantite,
                    "Vente {$vente->numero}",
                    $vente,
                );
            }

            // 3. La caisse, par le port. Tant que la Trésorerie n'est
            //    pas branchée, l'implémentation provisoire trace sans
            //    écrire — mais une exception levée ici annulerait bien
            //    les deux étapes précédentes.
            $this->caisse->enregistrerEncaissementDeVente($vente);

            return $vente;
        });
    }

    /**
     * Annule une vente : contre-passe le stock puis la caisse.
     *
     * La vente n'est pas supprimée et ses montants ne bougent pas :
     * c'est son état qui change. Les articles reviennent en stock par
     * des mouvements d'entrée référençant la vente, et le brouillard
     * reçoit sa contre-passation.
     *
     * @throws VenteInvalideException
     */
    public function annuler(Vente $vente, string $motif): Vente
    {
        if ($vente->estAnnulee()) {
            throw VenteInvalideException::dejaAnnulee($vente->numero);
        }

        return DB::transaction(function () use ($vente, $motif): Vente {
            foreach ($vente->lignes()->with('produit')->get() as $ligne) {
                if (! $ligne->produit) {
                    continue;
                }

                $this->stock->enregistrer(
                    $ligne->produit,
                    SensMouvementStock::ENTREE,
                    TypeMouvementStock::CORRECTION,
                    $ligne->quantite,
                    "Annulation de la vente {$vente->numero} : {$motif}",
                    $vente,
                );
            }

            $vente->etat = EtatVente::ANNULEE;
            $vente->motif_annulation = $motif;
            $vente->date_annulation = now();
            $vente->save();

            $this->caisse->contrepasserEncaissementDeVente($vente, $motif);

            return $vente;
        });
    }

    /**
     * Répartit le montant encaissé entre le village et l'artisan.
     *
     * Un seul arrondi, sur la commission ; la part artisan suit par
     * différence. `3 333 F` à 15 % donne `499,95` → `500` de
     * commission et `2 833` pour l'artisan, dont la somme fait
     * exactement `3 333`.
     *
     * @return array{0: int, 1: int} commission, part artisan
     */
    public function repartir(int $montantTotal, float|string $taux): array
    {
        $commission = (int) round($montantTotal * (float) $taux / 100);

        return [$commission, $montantTotal - $commission];
    }

    /**
     * @param  array<int, array{produit_id: int|string, quantite: int}>  $lignes
     * @param  array<int, Produit>  $produits
     */
    protected function calculerMontantTotal(array $lignes, array $produits): int
    {
        $total = 0;

        foreach ($lignes as $ligne) {
            $produit = $produits[(int) $ligne['produit_id']];
            $total += (int) round((float) $produit->prix_unitaire) * (int) $ligne['quantite'];
        }

        return $total;
    }

    /**
     * Charge les produits et vérifie que chacun est vendable.
     *
     * @param  array<int, array{produit_id: int|string, quantite: int}>  $lignes
     * @return array<int, Produit>
     */
    protected function rassemblerLesProduits(array $lignes): array
    {
        $identifiants = array_map(fn (array $ligne) => (int) $ligne['produit_id'], $lignes);

        $produits = Produit::query()
            ->whereKey($identifiants)
            ->get()
            ->keyBy(fn (Produit $produit) => (int) $produit->getKey())
            ->all();

        foreach ($identifiants as $identifiant) {
            $produit = $produits[$identifiant] ?? null;

            if (! $produit || ! $produit->estVendable()) {
                throw VenteInvalideException::produitNonVendable(
                    $produit?->identite ?? "#{$identifiant}"
                );
            }
        }

        return $produits;
    }

    /**
     * RG-08 : une vente, une boutique.
     *
     * Le contrôle est ici et pas seulement à l'écran : le cheminement
     * boutique → produits rend la règle structurellement inviolable
     * dans l'interface (RG-09 bis), mais un import de données ou une
     * commande n'emprunte pas ce cheminement.
     *
     * @param  array<int, Produit>  $produits
     */
    protected function boutiqueUnique(array $produits): int
    {
        $boutiques = array_unique(array_map(
            fn (Produit $produit) => (int) $produit->boutique_id,
            $produits,
        ));

        if (count($boutiques) > 1) {
            throw VenteInvalideException::plusieursBoutiques();
        }

        return (int) reset($boutiques);
    }

    /**
     * RG-14 : l'agent qui a réalisé la vente. Constaté, pas choisi.
     */
    protected function vendeurDuCompteConnecte(): Agent
    {
        /** @var Utilisateur|null $utilisateur */
        $utilisateur = Auth::user();
        $agent = $utilisateur?->agent;

        if (! $agent) {
            throw VenteInvalideException::vendeurInconnu();
        }

        return $agent;
    }
}
