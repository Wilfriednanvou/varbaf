<?php

namespace Modules\Portail\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as RequeteBrute;
use Illuminate\Support\Facades\DB;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Commerce\Enums\StatutValidationProduit;
use Modules\Commerce\Models\CategorieProduit;
use Modules\Portail\Enums\DisponibilitePortail;
use Modules\Portail\Models\ArtisanVedette;
use Modules\Portail\Models\ContenuPage;
use Modules\Portail\Models\DemandeContact;
use Modules\Portail\Models\PublicationProduit;

/**
 * Point de lecture unique du site public.
 *
 * **Consultation seule.** Le portail ne vend pas, ne commande pas, ne
 * encaisse pas — CLAUDE.md l'énonce deux fois. La seule écriture de tout
 * le module est l'enregistrement d'une demande de contact.
 *
 * **Le stock réel ne sort jamais d'ici.** La comparaison au zéro est
 * faite par la base et seul un booléen traverse vers PHP, d'où il
 * ressort en `DisponibilitePortail`. Aucune méthode publique de ce
 * service ne rend une quantité, et aucune vue n'a de quoi en afficher
 * une.
 *
 * **Trois conditions, revérifiées à chaque lecture.** Un produit
 * n'apparaît que s'il est publié, exposé, et si son artisan autorise
 * toujours la publication. Retirer un produit de la vitrine ou retirer
 * son autorisation à un artisan le fait disparaître du portail
 * immédiatement, sans passage de nettoyage — c'est ce qui rend ces deux
 * gestes fiables.
 */
class ServicePortail
{
    /**
     * Présence en stock, calculée par la base et rendue en 0 ou 1.
     *
     * Un entier plutôt qu'un booléen SQL : selon le pilote, un booléen
     * PostgreSQL revient en `true` ou en `'t'`, et `(bool) 'f'` vaut
     * `true`. Le `case when` supprime la question.
     */
    protected const PRESENCE_EN_STOCK = "(case when coalesce((select sum(case when sens = 'ENTREE' then quantite else -quantite end)"
        .' from mouvements_stock where mouvements_stock.produit_id = publications_produit.produit_id), 0) > 0'
        .' then 1 else 0 end)';

    // =================================================================
    //  CATALOGUE
    // =================================================================

    /**
     * Les produits visibles du public, filtrables par catégorie et par
     * corps de métier.
     */
    public function catalogue(
        ?int $categorieId = null,
        ?int $corpsMetierId = null,
        int $parPage = 12,
    ): LengthAwarePaginator {
        return $this->requeteCatalogue()
            ->when(
                $categorieId,
                fn (Builder $requete, int $id) => $requete->whereHas(
                    'produit',
                    fn (Builder $sous) => $sous->where('categorie_id', $id),
                ),
            )
            ->when(
                $corpsMetierId,
                fn (Builder $requete, int $id) => $requete->whereHas(
                    'produit.artisan',
                    fn (Builder $sous) => $sous->where('corps_metier_id', $id),
                ),
            )
            ->paginate($parPage)
            ->withQueryString();
    }

    /**
     * Fiche d'un produit, désignée par sa référence — jamais par son
     * identifiant technique. La référence est déjà publique : elle est
     * imprimée sur l'étiquette et sur le reçu de vente (RG-09).
     */
    public function ficheProduit(string $reference): ?PublicationProduit
    {
        return $this->requeteCatalogue()
            ->whereHas('produit', fn (Builder $sous) => $sous->where('reference', $reference))
            ->first();
    }

    /**
     * Les autres produits visibles du même artisan, pour la fiche
     * produit.
     *
     * @return Collection<int, PublicationProduit>
     */
    public function autresProduitsDeLArtisan(PublicationProduit $publication, int $limite = 4): Collection
    {
        $artisanId = $publication->produit?->artisan_id;

        if (! $artisanId) {
            return new Collection();
        }

        return $this->requeteCatalogue()
            ->whereKeyNot($publication->getKey())
            ->whereHas('produit', fn (Builder $sous) => $sous->where('artisan_id', $artisanId))
            ->limit($limite)
            ->get();
    }

    // =================================================================
    //  ARTISANS
    // =================================================================

    /**
     * L'annuaire public : les artisans actifs ayant autorisé la
     * publication, et eux seuls.
     */
    public function artisansPublies(?int $corpsMetierId = null, int $parPage = 12): LengthAwarePaginator
    {
        return Artisan::query()
            ->publiable()
            ->with('corpsMetier')
            ->when(
                $corpsMetierId,
                fn (Builder $requete, int $id) => $requete->where('corps_metier_id', $id),
            )
            ->orderBy('nom')
            ->paginate($parPage)
            ->withQueryString();
    }

    public function ficheArtisan(string $matricule): ?Artisan
    {
        return Artisan::query()
            ->publiable()
            ->with('corpsMetier')
            ->where('matricule', $matricule)
            ->first();
    }

    /**
     * Les produits visibles d'un artisan donné.
     *
     * @return Collection<int, PublicationProduit>
     */
    public function produitsDeLArtisan(Artisan $artisan): Collection
    {
        return $this->requeteCatalogue()
            ->whereHas('produit', fn (Builder $sous) => $sous->where('artisan_id', $artisan->getKey()))
            ->get();
    }

    /**
     * Artisans mis en avant aujourd'hui.
     *
     * @return Collection<int, ArtisanVedette>
     */
    public function artisansVedettes(int $limite = 3): Collection
    {
        return ArtisanVedette::query()
            ->enCours()
            ->with('artisan.corpsMetier')
            ->orderBy('ordre_affichage')
            ->orderByDesc('date_debut')
            ->limit($limite)
            ->get();
    }

    // =================================================================
    //  FILTRES DU CATALOGUE
    // =================================================================

    /**
     * Les seules catégories qui ont quelque chose à montrer.
     *
     * Proposer un filtre qui ne rend aucun résultat donne au visiteur
     * l'impression d'un site vide plutôt que d'un catalogue ciblé.
     *
     * @return Collection<int, CategorieProduit>
     */
    public function categoriesDuCatalogue(): Collection
    {
        return CategorieProduit::query()
            ->whereIn('id', $this->identifiantsProduitsVisibles()->select('produits.categorie_id'))
            ->orderBy('libelle')
            ->get();
    }

    /**
     * @return Collection<int, CorpsMetier>
     */
    public function corpsMetiersDuCatalogue(): Collection
    {
        return CorpsMetier::query()
            ->whereIn('id', $this->identifiantsProduitsVisibles()->select('artisans.corps_metier_id'))
            ->orderBy('libelle')
            ->get();
    }

    // =================================================================
    //  DISPONIBILITÉ
    // =================================================================

    /**
     * Ce que le portail dit du stock : disponible, ou sur commande.
     *
     * La quantité n'apparaît nulle part. Quand la publication vient du
     * catalogue, la présence en stock a déjà été calculée par la base et
     * accompagne la ligne ; sinon on retombe sur le solde du journal, et
     * seul le résultat de la comparaison ressort d'ici.
     */
    public function disponibilite(PublicationProduit $publication): DisponibilitePortail
    {
        $presence = $publication->getAttribute('presence_en_stock');

        if ($presence !== null) {
            return DisponibilitePortail::depuisPresenceEnStock((int) $presence > 0);
        }

        return DisponibilitePortail::depuisPresenceEnStock(
            ($publication->produit?->getQuantiteEnStock() ?? 0) > 0
        );
    }

    // =================================================================
    //  CONTENUS ÉDITORIAUX
    // =================================================================

    public function contenu(string $cle): ?ContenuPage
    {
        return ContenuPage::query()->actif()->where('cle', $cle)->first();
    }

    /**
     * Tous les contenus actifs dont la clé commence par un préfixe —
     * « village. » pour la page de présentation, par exemple.
     *
     * @return Collection<int, ContenuPage>
     */
    public function contenus(string $prefixe): Collection
    {
        return ContenuPage::query()
            ->actif()
            ->where('cle', 'like', $prefixe.'%')
            ->orderBy('ordre_affichage')
            ->orderBy('cle')
            ->get();
    }

    // =================================================================
    //  CONTACT — la seule écriture du module
    // =================================================================

    /**
     * @param  array{nom: string, contact: string, sujet?: ?string, message: string}  $donnees
     */
    public function enregistrerDemandeContact(array $donnees, ?string $adresseIp = null): DemandeContact
    {
        return DemandeContact::create([
            'nom' => $donnees['nom'],
            'contact' => $donnees['contact'],
            'sujet' => $donnees['sujet'] ?? null,
            'message' => $donnees['message'],
            'adresse_ip' => $adresseIp,
        ]);
    }

    // =================================================================
    //  FABRIQUE DE REQUÊTES
    // =================================================================

    /**
     * Le socle de toutes les lectures du catalogue.
     *
     * `visible()` porte les trois conditions ; la présence en stock est
     * ajoutée ici pour que le catalogue n'ait pas à interroger le
     * journal de stock une fois par vignette.
     */
    protected function requeteCatalogue(): Builder
    {
        return PublicationProduit::query()
            ->visible()
            ->with(['produit.artisan.corpsMetier', 'produit.categorie'])
            ->selectRaw('publications_produit.*, '.self::PRESENCE_EN_STOCK.' as presence_en_stock')
            ->orderBy('publications_produit.ordre_affichage')
            ->orderByDesc('publications_produit.id');
    }

    /**
     * Les identifiants des produits réellement visibles, en requête
     * brute.
     *
     * Sert les listes de filtres, qui partent des catégories et des
     * corps de métier plutôt que des publications. C'est la même règle
     * que `PublicationProduit::visible()`, exprimée en SQL parce que le
     * point de départ n'est pas le même — d'où la référence explicite à
     * `StatutValidationProduit::EXPOSE`, pour qu'un changement de règle
     * se voie ici aussi.
     *
     * Les conditions reprennent exactement celles de
     * `Produit::scopePubliable()`, y compris son silence sur
     * `artisans.actif` : une liste de filtres plus stricte que le
     * catalogue afficherait moins de catégories qu'il n'y a de produits
     * visibles, et une catégorie manquerait sans raison apparente.
     */
    protected function identifiantsProduitsVisibles(): RequeteBrute
    {
        return DB::table('publications_produit')
            ->join('produits', 'produits.id', '=', 'publications_produit.produit_id')
            ->join('artisans', 'artisans.id', '=', 'produits.artisan_id')
            ->where('publications_produit.publie', true)
            ->where('produits.actif', true)
            ->where('produits.statut_validation', StatutValidationProduit::EXPOSE->value)
            ->where('artisans.autorisation_publication', true);
    }
}
