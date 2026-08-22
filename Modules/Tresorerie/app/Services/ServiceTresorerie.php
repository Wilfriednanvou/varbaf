<?php

namespace Modules\Tresorerie\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Contracts\JournalDeCaisse;
use Modules\Commerce\Models\Vente;
use Modules\Tresorerie\Enums\EtatCaisse;
use Modules\Tresorerie\Enums\EtatSectionCaisse;
use Modules\Tresorerie\Enums\NatureMouvementCaisse;
use Modules\Tresorerie\Enums\SensMouvementCaisse;
use Modules\Tresorerie\Exceptions\MouvementCaisseImmuableException;
use Modules\Tresorerie\Exceptions\SectionCaisseException;
use Modules\Tresorerie\Models\Caisse;
use Modules\Tresorerie\Models\LibelleMouvement;
use Modules\Tresorerie\Models\MouvementCaisse;
use Modules\Tresorerie\Models\SectionCaisse;

/**
 * Point d'entrée unique du brouillard de caisse (RG-06).
 *
 * Aucun module n'insère directement dans `mouvements_caisse` : ventes,
 * redevances, reversements et dépenses passent tous par ici. C'est la
 * seule façon de garantir trois choses à la fois — la numérotation
 * sans rupture (RG-04), l'immuabilité (RG-05), et le calcul du solde
 * progressif.
 *
 * Le service implémente le port `JournalDeCaisse` déclaré dans le
 * Commerce, ce qui permet à `ServiceVente` de lui déléguer les
 * encaissements sans connaître la Trésorerie.
 */
class ServiceTresorerie implements JournalDeCaisse
{
    /**
     * Nombre de tentatives en cas de collision sur le numéro d'ordre.
     */
    private const MAX_TENTATIVES = 3;

    /**
     * SQLSTATE d'une violation de contrainte d'unicité en PostgreSQL.
     *
     * C'est la seule erreur qu'une nouvelle tentative peut résoudre :
     * deux saisies simultanées ayant visé le même numéro d'ordre, la
     * seconde aboutit en reprenant le numéro suivant. Une clé étrangère
     * absente ou une valeur trop longue ne se résoudra jamais d'elle-même
     * — la réessayer ne fait que retarder le message d'erreur de trois
     * allers-retours en base.
     */
    private const VIOLATION_UNICITE = '23505';

    /**
     * Section ciblée pour les opérations en cours.
     *
     * Quand l'écran opérationnel de caisse enregistre une vente ou un
     * mouvement, il cible la section affichée plutôt que la première
     * section ouverte trouvée. La valeur est remise à null après usage.
     */
    protected static ?SectionCaisse $sectionCible = null;

    /**
     * Cible temporairement une section pour les prochaines écritures.
     *
     * À appeler avant `ServiceVente::enregistrer()` ou toute autre
     * opération passant par le brouillard, puis à remettre à null dans
     * un bloc `finally`.
     */
    public static function ciblerSection(?SectionCaisse $section): void
    {
        static::$sectionCible = $section;
    }

    /**
     * Solde courant d'une section (RG-12 bis : entier).
     *
     * Relais vers `SectionCaisse::soldeCourant()` plutôt qu'un second
     * calcul : les deux méthodes faisaient la même somme, l'une en
     * `float` et l'autre en `int`. Deux définitions du même solde
     * finissent toujours par diverger d'un franc, et c'est ce franc-là
     * qu'on cherche ensuite pendant une heure au rapprochement.
     */
    public function solde(SectionCaisse $section): int
    {
        return $section->soldeCourant();
    }

    /**
     * Écrit une ligne au brouillard.
     *
     * Toute l'opération tient dans une transaction avec verrou sur les
     * lignes de la section : sans cela, deux saisies simultanées
     * pourraient lire le même solde, attribuer le même numéro d'ordre,
     * et produire un doublon.
     *
     * Trois tentatives en cas de collision (§7.3 de la spécification).
     *
     * @throws SectionCaisseException si la section n'est pas ouverte
     */
    public function enregistrer(
        SectionCaisse $section,
        NatureMouvementCaisse $nature,
        SensMouvementCaisse $sens,
        int $montant,
        string $libelle,
        ?string $pieceJustificative = null,
        ?Model $origine = null,
        ?MouvementCaisse $contrepasse = null,
        ?LibelleMouvement $libelleMouvement = null,
        ?\DateTimeInterface $dateOperation = null,
    ): MouvementCaisse {
        // RG-12 bis : le franc CFA n'a pas de subdivision. La signature
        // prend un entier plutôt qu'un flottant arrondi ici : PHP refuse
        // désormais lui-même ce que la règle interdit, et « 1 000,6 F »
        // ne devient plus silencieusement 1 001 F au passage.
        if ($montant <= 0) {
            throw new \InvalidArgumentException(
                "Le montant d'un mouvement de caisse doit être strictement positif (reçu : {$montant})."
            );
        }

        if (! $section->estOuverte()) {
            throw SectionCaisseException::aucuneSectionOuverte();
        }

        $tentative = 0;

        while (true) {
            try {
                return DB::transaction(function () use (
                    $section, $nature, $sens, $montant, $libelle,
                    $pieceJustificative, $origine, $contrepasse, $libelleMouvement, $dateOperation
                ): MouvementCaisse {
                    // Verrou sur la ligne de section — et non sur les
                    // mouvements.
                    //
                    // `SELECT ... FOR UPDATE` ne verrouille que les
                    // lignes qu'il retourne : sur une section encore
                    // vide, verrouiller les mouvements ne verrouille
                    // rien du tout, et deux saisies simultanées visent
                    // le même numéro d'ordre. La ligne de section, elle,
                    // existe toujours — c'est elle qui sérialise les
                    // écritures.
                    //
                    // Elle évite en outre de charger en mémoire les
                    // milliers de lignes que la section finira par
                    // porter (§7.6 de la spécification), à chaque
                    // écriture, pour n'en tirer aucune donnée.
                    SectionCaisse::query()
                        ->whereKey($section->getKey())
                        ->lockForUpdate()
                        ->first();

                    $soldeCourant = $this->solde($section);

                    $dernierNumero = (int) MouvementCaisse::query()
                        ->where('section_id', $section->getKey())
                        ->max('numero_ordre');

                    // Le montant est entier par signature (RG-12 bis) :
                    // plus rien à arrondir ici.
                    $soldeApres = $soldeCourant + ($sens->signe() * $montant);

                    return MouvementCaisse::query()->create([
                        'numero_ordre' => $dernierNumero + 1,
                        'date_operation' => $dateOperation ?? now(),
                        'section_id' => $section->getKey(),
                        'nature' => $nature,
                        'libelle_mouvement_id' => $libelleMouvement?->getKey(),
                        'sens' => $sens,
                        'montant' => $montant,
                        'solde_apres' => $soldeApres,
                        'libelle' => $libelle,
                        'piece_justificative' => $pieceJustificative,
                        'origine_type' => $origine ? class_basename($origine) : null,
                        'origine_id' => $origine?->getKey(),
                        'mouvement_contrepasse_id' => $contrepasse?->getKey(),
                        'saisi_par' => Auth::id(),
                    ]);
                });
            } catch (QueryException $e) {
                // Seul un doublon de numéro d'ordre justifie une
                // nouvelle tentative. Tout le reste remonte
                // immédiatement, avec son message d'origine.
                if ((string) $e->getCode() !== self::VIOLATION_UNICITE) {
                    throw $e;
                }

                $tentative++;

                if ($tentative >= self::MAX_TENTATIVES) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Annule un mouvement par un mouvement de sens inverse (RG-05).
     *
     * Le mouvement d'origine n'est pas touché : c'est tout l'intérêt.
     * Le brouillard montre l'erreur, puis sa correction, et le solde
     * redevient juste sans qu'aucune ligne n'ait menti entre-temps.
     *
     * @throws MouvementCaisseImmuableException
     */
    public function contrepasser(MouvementCaisse $mouvement, string $motif): MouvementCaisse
    {
        if ($mouvement->estUneContrepassation()) {
            throw MouvementCaisseImmuableException::contrepassationDeContrepassation();
        }

        if ($mouvement->estContrepasse()) {
            throw MouvementCaisseImmuableException::dejaContrepasse($mouvement->numero_ordre);
        }

        return $this->enregistrer(
            $mouvement->section,
            NatureMouvementCaisse::CONTREPASSATION,
            $mouvement->sens->inverse(),
            (int) $mouvement->montant,
            "Contre-passation mvt n° {$mouvement->numero_ordre} : {$motif}",
            null,
            null,
            $mouvement,
            $mouvement->libelleMouvement,
        );
    }

    // ================================================================
    //  Implémentation du port JournalDeCaisse (Commerce → Trésorerie)
    // ================================================================

    /**
     * Résout la section ouverte sur laquelle écrire.
     *
     * En tranche A : prend la première caisse active du village ayant
     * une section ouverte. Ce choix est centralisé ici pour être
     * facilement amendable quand la question « caisse unique ou
     * multiple ? » sera tranchée.
     *
     * Publique parce que la campagne de reversement en a besoin elle
     * aussi : elle décaisse par le brouillard et doit viser la même
     * section que le reste du module. Dupliquer la résolution ailleurs
     * ferait exactement ce que ce commentaire cherche à éviter.
     */
    public function resoudreSectionOuverte(): SectionCaisse
    {
        // Si une section est ciblée par l'écran opérationnel, on la
        // retourne directement. Le contrôle « estOuverte » est fait
        // dans `enregistrer()`, pas ici.
        if (static::$sectionCible) {
            return static::$sectionCible;
        }

        $section = SectionCaisse::query()
            ->whereHas('caisse', fn ($q) => $q->where('etat', EtatCaisse::ACTIVE->value))
            ->where('etat', EtatSectionCaisse::OUVERTE->value)
            ->first();

        if (! $section) {
            throw SectionCaisseException::aucuneSectionOuverte();
        }

        return $section;
    }

    /**
     * {@inheritdoc}
     */
    public function enregistrerEncaissementDeVente(Vente $vente): ?int
    {
        $section = $this->resoudreSectionOuverte();

        $mouvement = $this->enregistrer(
            $section,
            NatureMouvementCaisse::VENTE,
            SensMouvementCaisse::ENTREE,
            (int) $vente->montant_total,
            "Vente {$vente->numero}",
            $vente->numero,
            $vente,
            null,
            LibelleMouvement::query()->where('code', NatureMouvementCaisse::VENTE->value)->first(),
        );

        // Rattacher la vente à la section de caisse
        $vente->newQuery()
            ->where('id', $vente->getKey())
            ->update(['section_caisse_id' => $section->getKey()]);

        return $mouvement->getKey();
    }

    /**
     * {@inheritdoc}
     */
    public function contrepasserEncaissementDeVente(Vente $vente, string $motif): ?int
    {
        // Retrouver le mouvement d'encaissement de cette vente
        $mouvementOrigine = MouvementCaisse::query()
            ->where('origine_type', 'Vente')
            ->where('origine_id', $vente->getKey())
            ->where('nature', NatureMouvementCaisse::VENTE->value)
            ->whereNull('mouvement_contrepasse_id')
            ->first();

        if (! $mouvementOrigine) {
            // Si aucun mouvement n'a été trouvé, la vente n'avait
            // peut-être pas encore été encaissée en caisse.
            return null;
        }

        $mouvement = $this->contrepasser($mouvementOrigine, $motif);

        return $mouvement->getKey();
    }

    /**
     * {@inheritdoc}
     */
    public function estOperationnel(): bool
    {
        return true;
    }
}
