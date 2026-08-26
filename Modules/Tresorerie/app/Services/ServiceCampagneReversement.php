<?php

namespace Modules\Tresorerie\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Enums\EtatVente;
use Modules\Commerce\Models\Vente;
use Modules\Tresorerie\Enums\NatureMouvementCaisse;
use Modules\Tresorerie\Enums\SensMouvementCaisse;
use Modules\Tresorerie\Enums\StatutCampagneReversement;
use Modules\Tresorerie\Enums\StatutReversement;
use Modules\Tresorerie\Enums\TypeLigneReversement;
use Modules\Tresorerie\Exceptions\CampagneReversementException;
use Modules\Tresorerie\Models\CampagneReversement;
use Modules\Tresorerie\Models\LibelleMouvement;
use Modules\Tresorerie\Models\LigneReversement;
use Modules\Tresorerie\Models\Reversement;
use Modules\Tresorerie\Models\SectionCaisse;

/**
 * Point d'entrée unique des campagnes de reversement (RG-16 à RG-21).
 *
 * **Deux temps, deux natures.** `preparer()` calcule et ne s'engage à
 * rien : il efface le calcul précédent et le refait, autant de fois
 * qu'on veut, tant que la campagne est en préparation. `valider()`
 * engage : il rattache les ventes, décaisse en caisse, et referme la
 * campagne pour toujours (RG-21). C'est aussi ce que RG-23 sépare — la
 * section qui tient la caisse prépare, le coordonnateur valide.
 *
 * **Atomicité de la validation.** Rattacher les ventes, écrire N
 * décaissements au brouillard et refermer la campagne réussissent
 * ensemble ou pas du tout. Le scénario redouté est la panne au
 * deuxième temps : des ventes marquées « reversées » sans que l'argent
 * soit sorti, découvertes le mois suivant quand elles ne réapparaissent
 * dans aucune campagne. Le rattachement est donc posé **avant** les
 * décaissements, à l'intérieur de la même transaction : si un
 * décaissement échoue, le rattachement disparaît avec lui.
 *
 * **Aucune écriture directe en caisse.** Les décaissements passent par
 * `ServiceTresorerie` (RG-06), comme tout le reste.
 */
class ServiceCampagneReversement
{
    public function __construct(
        protected ServiceTresorerie $tresorerie,
    ) {}

    // =================================================================
    //  PRÉPARATION
    // =================================================================

    /**
     * Recalcule entièrement une campagne en préparation.
     *
     * Efface le calcul précédent — les reversements et leurs lignes —
     * puis le refait depuis les ventes. Repréparer une campagne après
     * une saisie tardive est donc l'opération normale, pas un
     * rattrapage exceptionnel.
     *
     * @throws CampagneReversementException si la campagne est validée
     */
    public function preparer(CampagneReversement $campagne): CampagneReversement
    {
        if ($campagne->estValidee()) {
            throw CampagneReversementException::dejaValidee($campagne->libellePeriode());
        }

        return DB::transaction(function () use ($campagne): CampagneReversement {
            // Le calcul précédent s'efface entièrement. La cascade
            // emporte les lignes ; le modèle `Reversement` refuse la
            // suppression d'une ligne définitive, ce qui ne peut pas
            // arriver ici puisque la campagne est en préparation.
            $campagne->reversements()->get()->each(fn (Reversement $r) => $r->delete());

            [$debutPeriode] = $campagne->bornesDeLaPeriode();

            /** @var array<int, array{periode: int, regularisation: int, lignes: array<int, array<string, mixed>>}> $parArtisan */
            $parArtisan = [];

            // --- 1. Les ventes retenues (RG-17) -----------------------
            //
            // Validées, jamais rattachées à une campagne validée, et
            // antérieures ou égales à la date d'arrêté. `whereNull` dit
            // exactement « non rattachée » : la colonne n'est écrite
            // qu'à la validation.
            $ventes = Vente::query()
                ->validee()
                ->whereNull('campagne_reversement_id')
                ->whereDate('date_vente', '<=', $campagne->date_arrete)
                ->orderBy('date_vente')
                ->get();

            foreach ($ventes as $vente) {
                // RG-19 : une vente antérieure au mois de la campagne
                // est une régularisation, avec sa date d'origine
                // visible. Tout le reste relève de la période — y
                // compris une vente postérieure au mois, si la date
                // d'arrêté déborde : ce n'est pas un rattrapage.
                $type = $vente->date_vente->lt($debutPeriode)
                    ? TypeLigneReversement::REGULARISATION
                    : TypeLigneReversement::PERIODE;

                $this->ajouterLigne($parArtisan, $vente, $type, (int) $vente->part_artisan);
            }

            // --- 2. Les reprises (RG-20) ------------------------------
            //
            // Une vente payée lors d'une campagne validée, puis annulée.
            // L'artisan a touché une part qui n'est plus due : la
            // campagne suivante la reprend, une fois et une seule.
            // `whereNotIn` sur les lignes de reprise déjà écrites est ce
            // qui empêche la campagne d'après de la reprendre à nouveau.
            $reprises = Vente::query()
                ->where('etat', EtatVente::ANNULEE->value)
                ->whereNotNull('campagne_reversement_id')
                ->whereDate('date_annulation', '<=', $campagne->date_arrete)
                ->whereNotIn(
                    'id',
                    LigneReversement::query()->reprises()->select('vente_id'),
                )
                ->orderBy('date_vente')
                ->get();

            foreach ($reprises as $vente) {
                $this->ajouterLigne(
                    $parArtisan,
                    $vente,
                    TypeLigneReversement::REPRISE,
                    -(int) $vente->part_artisan,
                );
            }

            // --- 3. Les soldes reportés (RG-20) -----------------------
            //
            // Un solde négatif non absorbé passe de campagne en campagne
            // jusqu'à extinction. Il n'a pas de ligne de détail : il ne
            // se rattache à aucune vente, il est le reliquat d'un calcul
            // antérieur. Un artisan qui n'a rien vendu ce mois-ci mais
            // porte un report doit tout de même avoir son reversement,
            // sans quoi la dette s'évaporerait.
            foreach ($this->reportsDeLaCampagnePrecedente($campagne) as $artisanId => $report) {
                $parArtisan[$artisanId] ??= ['periode' => 0, 'regularisation' => 0, 'lignes' => []];
                $parArtisan[$artisanId]['regularisation'] += $report;
            }

            // --- 4. Écriture ------------------------------------------
            $montantTotal = 0;
            $beneficiaires = 0;

            foreach ($parArtisan as $artisanId => $donnees) {
                $soldeNet = $donnees['periode'] + $donnees['regularisation'];

                // RG-20 : on ne réclame pas d'argent à un artisan au
                // guichet. Ce qui est dû se paie, ce qui est négatif se
                // retient sur les campagnes suivantes.
                $montantPaye = max(0, $soldeNet);
                $soldeReporte = min(0, $soldeNet);

                $reversement = Reversement::create([
                    'campagne_id' => $campagne->getKey(),
                    'artisan_id' => $artisanId,
                    'montant_periode' => $donnees['periode'],
                    'montant_regularisation' => $donnees['regularisation'],
                    'montant_paye' => $montantPaye,
                    'solde_reporte' => $soldeReporte,
                    'statut' => StatutReversement::A_PAYER,
                ]);

                foreach ($donnees['lignes'] as $ligne) {
                    LigneReversement::create($ligne + ['reversement_id' => $reversement->getKey()]);
                }

                $montantTotal += $montantPaye;

                if ($montantPaye > 0) {
                    $beneficiaires++;
                }
            }

            $campagne->forceFill([
                'date_generation' => now(),
                'generee_par' => Auth::id(),
                'montant_total' => $montantTotal,
                'nombre_beneficiaires' => $beneficiaires,
            ])->save();

            return $campagne->refresh();
        });
    }

    // =================================================================
    //  VALIDATION
    // =================================================================

    /**
     * Rattache les ventes, décaisse et fige la campagne (RG-18, RG-21).
     *
     * Irréversible. Un décaissement par artisan bénéficiaire, écrit au
     * brouillard par `ServiceTresorerie` (RG-06). Les soldes négatifs ou
     * nuls ne produisent aucun mouvement et passent en report (RG-20).
     *
     * **Pourquoi le décaissement a lieu ici.** Une variante a été
     * explorée le 25/08 : ne rattacher qu'ici et payer chaque artisan
     * séparément à son passage au guichet, pour que la date en caisse
     * soit la date réelle du paiement. Elle est plus juste sur ce seul
     * point et se défend, mais elle déplace le décaissement hors de la
     * transaction de validation — c'est-à-dire hors de la garantie
     * décrite en tête de cette classe. Le choix retenu est la
     * validation atomique ; `payerReversement()` reste dans le code,
     * non branchée, et la variante est portée en perspective.
     *
     * @throws CampagneReversementException
     * @throws \Modules\Tresorerie\Exceptions\SectionCaisseException si la section visée n'est pas ouverte
     */
    public function valider(CampagneReversement $campagne, ?SectionCaisse $section = null): CampagneReversement
    {
        if ($campagne->estValidee()) {
            throw CampagneReversementException::dejaValidee($campagne->libellePeriode());
        }

        $reversements = $campagne->reversements()->with('lignes')->get();

        if ($reversements->isEmpty()) {
            throw CampagneReversementException::aucunReversementAValider($campagne->libellePeriode());
        }

        $section ??= $this->tresorerie->resoudreSectionOuverte();

        // Une seule lecture du libellé pour toute la campagne : il est
        // le même sur les N décaissements.
        $libelleReversement = LibelleMouvement::query()
            ->where('code', NatureMouvementCaisse::REVERSEMENT->value)
            ->first();

        return DB::transaction(function () use ($campagne, $reversements, $section, $libelleReversement): CampagneReversement {
            // --- 1. Rattachement des ventes (RG-21) -------------------
            //
            // Posé avant les décaissements, dans la même transaction :
            // si une écriture en caisse échoue, le rattachement part
            // avec elle. Les lignes de reprise sont exclues — elles
            // désignent des ventes déjà rattachées à la campagne qui
            // les avait payées, et cette trace-là ne se réécrit pas.
            $ventesARattacher = $reversements
                ->flatMap(fn (Reversement $reversement) => $reversement->lignes)
                ->reject(fn (LigneReversement $ligne) => $ligne->estUneReprise())
                ->pluck('vente_id')
                ->unique()
                ->all();

            if ($ventesARattacher !== []) {
                Vente::query()
                    ->whereIn('id', $ventesARattacher)
                    ->update(['campagne_reversement_id' => $campagne->getKey()]);
            }

            // --- 2. Un décaissement par bénéficiaire (RG-18, RG-20) ---
            foreach ($reversements as $reversement) {
                if ($reversement->montant_paye <= 0) {
                    // RG-20 : on ne réclame rien au guichet. Le solde
                    // négatif est reporté sur la campagne suivante et
                    // ne produit aucun mouvement de caisse.
                    $reversement->forceFill([
                        'statut' => StatutReversement::REPORTE,
                    ])->save();

                    continue;
                }

                $mouvement = $this->tresorerie->enregistrer(
                    section: $section,
                    nature: NatureMouvementCaisse::REVERSEMENT,
                    sens: SensMouvementCaisse::SORTIE,
                    montant: (int) $reversement->montant_paye,
                    libelle: "Reversement {$campagne->libellePeriode()} — "
                        .($reversement->artisan?->identite ?? "artisan #{$reversement->artisan_id}"),
                    origine: $reversement,
                    libelleMouvement: $libelleReversement,
                );

                $reversement->forceFill([
                    'mouvement_caisse_id' => $mouvement->getKey(),
                    'date_paiement' => now(),
                    'statut' => StatutReversement::PAYE,
                ])->save();
            }

            // --- 3. Fermeture de la campagne (RG-21) ------------------
            //
            // `montant_total` et `nombre_beneficiaires` viennent de la
            // préparation et ne sont pas recalculés : ce que la
            // validation décaisse est exactement ce que la préparation
            // avait annoncé, sans quoi l'état récapitulatif signé par
            // l'artisan ne vaudrait rien.
            $campagne->forceFill([
                'statut' => StatutCampagneReversement::VALIDEE,
                'validee_par' => Auth::id(),
                'date_validation' => now(),
            ])->save();

            return $campagne->refresh();
        });
    }

    /**
     * Décaisse le reversement d'un seul artisan (RG-18).
     *
     * **Non branchée — conservée comme perspective.** Elle porte la
     * variante « paiement au guichet » décrite dans `valider()` : la
     * campagne ne ferait que rattacher, et chaque artisan serait payé
     * au moment réel de son passage. Aucun écran ne l'appelle
     * aujourd'hui, et `valider()` marquant tout de suite les
     * reversements PAYÉ, la garde de statut ci-dessous la rend inerte
     * sur une campagne validée par le chemin normal — un double
     * décaissement est donc impossible.
     *
     * Voir `docs/dette-technique.md`, arbitrage A-08.
     *
     * @throws \RuntimeException si la campagne n'est pas validée ou si le reversement n'est pas à payer
     * @throws \Modules\Tresorerie\Exceptions\SectionCaisseException si aucune section n'est ouverte
     */
    public function payerReversement(Reversement $reversement, ?SectionCaisse $section = null): Reversement
    {
        $campagne = $reversement->campagne;

        if (! $campagne->estValidee()) {
            throw new \RuntimeException("La campagne {$campagne->libellePeriode()} n'est pas encore validée.");
        }

        if ($reversement->statut !== StatutReversement::A_PAYER) {
            throw new \RuntimeException("Ce reversement n'est pas en attente de paiement.");
        }

        $section ??= $this->tresorerie->resoudreSectionOuverte();

        $libelleReversement = LibelleMouvement::query()
            ->where('code', NatureMouvementCaisse::REVERSEMENT->value)
            ->first();

        return DB::transaction(function () use ($reversement, $campagne, $section, $libelleReversement): Reversement {
            $mouvement = $this->tresorerie->enregistrer(
                section: $section,
                nature: NatureMouvementCaisse::REVERSEMENT,
                sens: SensMouvementCaisse::SORTIE,
                montant: (int) $reversement->montant_paye,
                libelle: "Reversement {$campagne->libellePeriode()} — "
                    .($reversement->artisan?->identite ?? "artisan #{$reversement->artisan_id}"),
                origine: $reversement,
                libelleMouvement: $libelleReversement,
            );

            $reversement->forceFill([
                'mouvement_caisse_id' => $mouvement->getKey(),
                'date_paiement' => now(),
                'statut' => StatutReversement::PAYE,
            ])->save();

            // `montant_total` n'est pas touché : il porte le total
            // annoncé par la préparation, qui est celui de l'état
            // récapitulatif. L'incrémenter ici le doublerait.

            return $reversement->refresh();
        });
    }

    // =================================================================
    //  CALCULS INTERNES
    // =================================================================

    /**
     * Empile une ligne de détail sur le compte d'un artisan.
     *
     * @param  array<int, array{periode: int, regularisation: int, lignes: array<int, array<string, mixed>>}>  $parArtisan
     */
    protected function ajouterLigne(
        array &$parArtisan,
        Vente $vente,
        TypeLigneReversement $type,
        int $montant,
    ): void {
        $artisanId = (int) $vente->artisan_id;

        $parArtisan[$artisanId] ??= ['periode' => 0, 'regularisation' => 0, 'lignes' => []];

        // Seule la part de période alimente `montant_periode` ; les
        // régularisations et les reprises se cumulent dans la colonne
        // qui porte leur nom, et c'est cette séparation que le reçu
        // présente à l'artisan.
        if ($type === TypeLigneReversement::PERIODE) {
            $parArtisan[$artisanId]['periode'] += $montant;
        } else {
            $parArtisan[$artisanId]['regularisation'] += $montant;
        }

        $parArtisan[$artisanId]['lignes'][] = [
            'vente_id' => $vente->getKey(),
            'type' => $type,
            'montant' => $montant,
            // RG-19 : la date de la vente, pas celle de la campagne.
            'date_origine' => $vente->date_vente->toDateString(),
        ];
    }

    /**
     * Soldes négatifs laissés par la dernière campagne validée du même
     * exercice, par artisan.
     *
     * @return array<int, int> artisan_id => report (négatif)
     */
    protected function reportsDeLaCampagnePrecedente(CampagneReversement $campagne): array
    {
        $precedente = CampagneReversement::query()
            ->validee()
            ->where('exercice_id', $campagne->exercice_id)
            ->where('periode', '<', $campagne->periode->toDateString())
            ->orderByDesc('periode')
            ->first();

        if (! $precedente) {
            return [];
        }

        return $precedente->reversements()
            ->where('solde_reporte', '<', 0)
            ->pluck('solde_reporte', 'artisan_id')
            ->map(fn ($solde) => (int) $solde)
            ->all();
    }
}
