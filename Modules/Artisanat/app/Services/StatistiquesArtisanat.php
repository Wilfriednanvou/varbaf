<?php

namespace Modules\Artisanat\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Artisanat\Enums\StatutAttribution;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\AttributionEspace;
use Modules\Artisanat\Models\Boutique;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Artisanat\Models\EntrepriseArtisanale;
use Modules\Artisanat\Models\EspaceLocatif;

/**
 * Les indicateurs des écrans du module Artisanat.
 *
 * **Pourquoi un service et non des requêtes dans les widgets.** Trois
 * raisons, dans cet ordre d'importance. La première est qu'un indicateur
 * est une définition avant d'être un chiffre : « espace occupé » veut
 * dire « portant une attribution active dont la période couvre
 * aujourd'hui », et cette phrase doit exister à un seul endroit, sans
 * quoi deux écrans finiront par afficher deux nombres différents sous le
 * même mot. La deuxième est qu'un widget ne s'éprouve pas, alors qu'un
 * service se teste. La troisième est que le Pilotage pose déjà les mêmes
 * questions au sien : ce fichier est le pendant de `RapportService` pour
 * l'Artisanat.
 *
 * **Ce que ce service ne fait pas.** Aucun montant de vente, aucune
 * commission, aucun solde de caisse : ces notions appartiennent au
 * Commerce et à la Trésorerie, qui sont des modules **suivants**. Un
 * indicateur de l'Artisanat ne parle que de qui est enregistré, de ce qui
 * se loue et de qui l'occupe. La redevance fait exception et n'en est pas
 * une : elle est figée sur l'attribution, donc c'est bien une donnée de
 * l'Artisanat.
 */
class StatistiquesArtisanat
{
    // =================================================================
    //  ARTISANS
    // =================================================================

    public function nombreArtisans(): int
    {
        return Artisan::query()->count();
    }

    public function nombreArtisansActifs(): int
    {
        return Artisan::query()->actif()->count();
    }

    /**
     * Actifs **et** ayant autorisé la publication de leur profil.
     *
     * Les deux conditions comptent : une autorisation donnée par un
     * artisan désactivé ne vaut plus rien, et publier sur le portail le
     * profil de quelqu'un qui a quitté le village serait une faute.
     */
    public function nombreArtisansPubliables(): int
    {
        return Artisan::query()->publiable()->count();
    }

    /**
     * Artisans actifs sans espace attribué à ce jour.
     *
     * **Ce n'est pas une anomalie, c'est une question posée à la
     * coordination.** Le modèle admet le déposant non installé — quatre
     * cas sont identifiés dans `docs/questions-coordination.md`, plus
     * Mme Justina. Le chiffre existe pour qu'on sache combien ils sont
     * et qu'on cesse de le redécouvrir en fouillant le registre.
     */
    public function nombreArtisansSansEspace(): int
    {
        return Artisan::query()
            ->actif()
            ->whereDoesntHave('attributions', $this->attributionCourante(...))
            ->count();
    }

    // =================================================================
    //  CORPS DE MÉTIER ET ENTREPRISES
    // =================================================================

    public function nombreCorpsMetiers(): int
    {
        return CorpsMetier::query()->count();
    }

    /**
     * Corps de métier portant au moins un artisan actif.
     *
     * L'écart avec le total est l'indicateur utile : une nomenclature de
     * quatorze secteurs dont cinq seulement sont représentés dit quelque
     * chose du village que la liste, elle, ne dit pas.
     */
    public function nombreCorpsMetiersRepresentes(): int
    {
        return CorpsMetier::query()
            ->whereHas('artisans', fn (Builder $requete) => $requete->where('actif', true))
            ->count();
    }

    public function nombreEntreprises(): int
    {
        return EntrepriseArtisanale::query()->count();
    }

    /**
     * Entreprises portant au moins un artisan.
     *
     * Le rattachement est facultatif — la plupart des artisans du village
     * exercent en nom propre —, donc une entreprise sans artisan n'est
     * pas une anomalie : c'est une structure déclarée dont personne ne se
     * réclame encore.
     */
    public function nombreEntreprisesAvecArtisan(): int
    {
        return EntrepriseArtisanale::query()->has('artisans')->count();
    }

    /**
     * Entreprises portant un numéro de contribuable.
     *
     * C'est l'indicateur de formalisation que la coordination remonte à
     * la tutelle : une raison sociale sans numéro est une déclaration,
     * pas une immatriculation.
     */
    public function nombreEntreprisesFormalisees(): int
    {
        return EntrepriseArtisanale::query()->whereNotNull('numero_contribuable')->count();
    }

    /**
     * Artisans rattachés à une entreprise, quelle qu'elle soit.
     */
    public function nombreArtisansEnEntreprise(): int
    {
        return Artisan::query()->whereNotNull('entreprise_id')->count();
    }

    // =================================================================
    //  PARC
    // =================================================================

    public function nombreContenants(): int
    {
        return Boutique::query()->count();
    }

    /**
     * Les seuls locaux de vente — hors sous-sol et espace vert.
     */
    public function nombreLocauxDeVente(): int
    {
        return Boutique::query()->locauxDeVente()->count();
    }

    public function nombreEspacesLocatifs(): int
    {
        return EspaceLocatif::query()->count();
    }

    /**
     * Contenants ne portant aucun espace locatif.
     *
     * Ce sont B13 et B17 au relevé du 26/08 : l'état de recouvrement ne
     * les mentionne pas, parce qu'il ne mentionne que ce qui se facture.
     * Un local sans espace renseigné n'est pas un local inexistant — la
     * distinction est la question 3 partie à la coordination.
     */
    public function nombreContenantsSansEspace(): int
    {
        return Boutique::query()->doesntHave('espacesLocatifs')->count();
    }

    /**
     * Espaces portant une attribution active couvrant aujourd'hui.
     */
    public function nombreEspacesOccupes(): int
    {
        return EspaceLocatif::query()
            ->whereHas('attributions', $this->attributionCourante(...))
            ->count();
    }

    /**
     * Espaces attribuables et libres aujourd'hui.
     *
     * « Libre » n'est pas « total moins occupés » : un espace déclaré
     * indisponible n'est ni occupé ni libre, et l'additionner aux libres
     * ferait annoncer à la coordination une capacité qui n'existe pas.
     */
    public function nombreEspacesLibres(): int
    {
        return EspaceLocatif::query()
            ->attribuable()
            ->whereDoesntHave('attributions', $this->attributionCourante(...))
            ->count();
    }

    public function nombreEspacesIndisponibles(): int
    {
        return EspaceLocatif::query()->count() - EspaceLocatif::query()->attribuable()->count();
    }

    /**
     * Taux d'occupation, en pourcentage, arrondi à une décimale.
     *
     * Rapporté aux espaces **attribuables**, pas au parc entier : un
     * espace hors service ne peut pas être occupé, et le compter au
     * dénominateur ferait baisser un taux dont personne n'est
     * responsable.
     */
    public function tauxOccupation(): float
    {
        $attribuables = EspaceLocatif::query()->attribuable()->count();

        return $attribuables > 0
            ? round($this->nombreEspacesOccupes() / $attribuables * 100, 1)
            : 0.0;
    }

    // =================================================================
    //  ATTRIBUTIONS
    // =================================================================

    public function nombreAttributionsActives(): int
    {
        return AttributionEspace::query()->where($this->attributionCourante(...))->count();
    }

    /**
     * Somme des redevances mensuelles convenues, attributions en cours.
     *
     * Figée sur l'attribution (RG-13) : ce n'est pas un calcul sur une
     * surface, c'est la somme de montants négociés espace par espace.
     */
    public function redevanceMensuelleCumulee(): int
    {
        return (int) AttributionEspace::query()
            ->where($this->attributionCourante(...))
            ->sum('redevance_convenue');
    }

    /**
     * Attributions en cours dont le terme tombe dans les trente jours.
     *
     * Une attribution sans date de fin n'y figure pas : elle n'arrive
     * jamais à terme, et la faire apparaître dans une alerte
     * d'échéance serait un contresens.
     */
    public function nombreAttributionsArrivantATerme(int $jours = 30): int
    {
        return AttributionEspace::query()
            ->where($this->attributionCourante(...))
            ->whereNotNull('date_fin')
            ->whereDate('date_fin', '<=', now()->addDays($jours))
            ->count();
    }

    // =================================================================

    /**
     * La définition unique d'« attribution en cours ».
     *
     * Active, commencée, et non terminée — une date de fin nulle valant
     * « sans terme ». Cette clause est reprise telle quelle par
     * `Artisan::getAttributionActive()` et
     * `EspaceLocatif::getOccupantActuel()` ; l'écrire ici une fois évite
     * qu'un écran compte les espaces occupés autrement qu'un autre.
     */
    protected function attributionCourante(Builder $requete): Builder
    {
        return $requete
            ->where('statut', StatutAttribution::ACTIVE->value)
            ->whereDate('date_debut', '<=', now())
            ->where(fn (Builder $sous) => $sous
                ->whereNull('date_fin')
                ->orWhereDate('date_fin', '>=', now()));
    }
}
