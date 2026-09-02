<?php

namespace Modules\Tresorerie\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Artisanat\Enums\StatutAttribution;
use Modules\Artisanat\Models\AttributionEspace;
use Modules\Tresorerie\Enums\NatureMouvementCaisse;

/**
 * Le parc locatif vu depuis la trésorerie : qui doit quoi, et qui a payé.
 *
 * **La logique vient de l'état de recouvrement du village**, transcrit
 * dans `docs/donnees/parc-locatif.csv`. La coordination y tient trois
 * colonnes par espace — dû, payé, reste — et c'est exactement ce que cet
 * écran reproduit. Rien n'a été inventé : la forme de l'information est
 * celle que le village emploie déjà.
 *
 * **Aucune table d'échéances.** DT-04 les a retirées du périmètre, et
 * elles ne reviennent pas ici. Le dû se *dérive* : nombre de mois écoulés
 * depuis le début de facturation, multiplié par la redevance convenue et
 * figée sur l'attribution (règle 13). C'est moins qu'un échéancier — on
 * connaît l'écart global, pas son ancienneté, donc pas de balance âgée —
 * mais c'est calculé sur des données que le système détient déjà, et
 * aucune ligne n'est créée pour le produire.
 *
 * **Le premier mois est offert**, et il l'est par construction :
 * `AttributionEspace::calculerDateDebutFacturation()` pose le début de
 * facturation un mois après l'entrée dans les lieux. Le décompte part de
 * cette date, jamais de `date_debut` — s'en écarter ferait payer un mois
 * que la règle 13 offre.
 */
class ServiceLocations
{
    /**
     * L'état du parc, une ligne par attribution en cours.
     *
     * `origine_type` et `origine_id` portent le rattachement d'un
     * encaissement à son attribution — les colonnes existent depuis la
     * création de `mouvements_caisse` (A-07 : un nom de classe court,
     * pas une relation polymorphe). Un encaissement qui n'en porte pas
     * ne peut être imputé à personne : il est **compté à part**, jamais
     * réparti au jugé. Un impayé constaté doit être un impayé réel, pas
     * la trace d'une saisie incomplète.
     *
     * @return Collection<int, object>
     */
    public function etatDuParc(?Carbon $arrete = null, ?int $exerciceId = null): Collection
    {
        $arrete ??= Carbon::today();

        $encaisse = DB::table('mouvements_caisse')
            ->selectRaw('origine_id')
            ->selectRaw('coalesce(sum(montant), 0) as encaisse')
            ->selectRaw('max(date_operation) as dernier_paiement')
            ->where('nature', NatureMouvementCaisse::REDEVANCE->value)
            ->where('origine_type', self::ORIGINE)
            ->whereNotNull('origine_id')
            ->groupBy('origine_id');

        $lignes = DB::table('attributions_espaces as a')
            ->join('espaces_locatifs as e', 'e.id', '=', 'a.espace_locatif_id')
            ->join('boutiques as b', 'b.id', '=', 'e.boutique_id')
            ->join('artisans as ar', 'ar.id', '=', 'a.artisan_id')
            ->leftJoin('corps_metiers as cm', 'cm.id', '=', 'ar.corps_metier_id')
            ->leftJoinSub($encaisse, 'm', 'm.origine_id', '=', 'a.id')
            ->select(
                'a.id',
                'a.redevance_convenue',
                'a.date_debut',
                'a.date_debut_facturation',
                'b.numero as boutique',
                'b.nature as nature_contenant',
                'e.code as espace',
                'ar.id as artisan_id',
                'ar.nom',
                'ar.prenom',
                'cm.libelle as metier',
            )
            ->selectRaw('coalesce(m.encaisse, 0) as encaisse')
            ->selectRaw('m.dernier_paiement')
            ->where('a.statut', StatutAttribution::ACTIVE->value)
            ->when($exerciceId, fn ($requete, int $id) => $requete->where('a.exercice_id', $id))
            ->orderBy('b.numero')
            ->orderBy('e.code')
            ->get();

        return $lignes->map(function (object $ligne) use ($arrete): object {
            $ligne->mois_dus = $this->moisDus($ligne->date_debut_facturation, $arrete);
            $ligne->du = $ligne->mois_dus * (int) $ligne->redevance_convenue;
            $ligne->encaisse = (int) $ligne->encaisse;
            $ligne->reste = $ligne->du - $ligne->encaisse;
            $ligne->a_jour = $ligne->reste <= 0;

            return $ligne;
        });
    }

    /**
     * Les totaux du parc, pour l'en-tête de l'écran.
     *
     * @return array{attributions: int, mensuel: int, du: int, encaisse: int, reste: int, a_jour: int}
     */
    public function totaux(?Carbon $arrete = null, ?int $exerciceId = null): array
    {
        $parc = $this->etatDuParc($arrete, $exerciceId);

        return [
            'attributions' => $parc->count(),
            'mensuel' => (int) $parc->sum('redevance_convenue'),
            'du' => (int) $parc->sum('du'),
            'encaisse' => (int) $parc->sum('encaisse'),
            'reste' => (int) $parc->sum('reste'),
            'a_jour' => $parc->where('a_jour', true)->count(),
        ];
    }

    /**
     * Les encaissements de redevance qu'aucune attribution ne réclame.
     *
     * **Ce compteur est le garde-fou de l'écran.** Tant qu'il n'est pas
     * nul, la colonne « encaissé » est incomplète et la colonne « reste »
     * surévalue la dette. Le taire donnerait un écran qui a l'air juste
     * et qui accuse des artisans à jour — précisément le genre de
     * silence que ce projet refuse ailleurs. L'écran l'affiche.
     *
     * @return array{nombre: int, montant: int}
     */
    public function encaissementsNonRattaches(): array
    {
        $orphelins = DB::table('mouvements_caisse')
            ->where('nature', NatureMouvementCaisse::REDEVANCE->value)
            ->where(fn ($requete) => $requete
                ->whereNull('origine_id')
                ->orWhere('origine_type', '!=', self::ORIGINE))
            ->selectRaw('count(*) as nombre, coalesce(sum(montant), 0) as montant')
            ->first();

        return [
            'nombre' => (int) ($orphelins->nombre ?? 0),
            'montant' => (int) ($orphelins->montant ?? 0),
        ];
    }

    /**
     * Le nom court de la classe d'origine (A-07).
     *
     * Une chaîne et non `AttributionEspace::class` : la colonne fait
     * soixante caractères et stocke un nom court, de sorte qu'un
     * déplacement de classe ne casse pas les lignes déjà écrites.
     */
    public const ORIGINE = 'AttributionEspace';

    public static function origineDe(AttributionEspace $attribution): array
    {
        return ['origine_type' => self::ORIGINE, 'origine_id' => $attribution->getKey()];
    }

    /**
     * Nombre de mensualités échues à la date d'arrêté.
     *
     * Le mois de la date d'arrêté compte : une redevance de septembre est
     * due en septembre, pas en octobre. Un début de facturation postérieur
     * à l'arrêté rend zéro — l'attribution existe, rien n'est encore
     * exigible, et afficher une dette serait faux.
     */
    protected function moisDus(?string $debutFacturation, Carbon $arrete): int
    {
        if ($debutFacturation === null) {
            return 0;
        }

        $debut = Carbon::parse($debutFacturation)->startOfMonth();
        $fin = $arrete->copy()->startOfMonth();

        if ($debut->greaterThan($fin)) {
            return 0;
        }

        return $debut->diffInMonths($fin) + 1;
    }
}
