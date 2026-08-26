<?php

namespace Modules\Pilotage\Assistant;

use Modules\Pilotage\Indexation\Normalisateur;

/**
 * Les intentions d'agrégation que l'assistant sait reconnaître.
 *
 * **Vingt et une intentions, écrites à la main, et pas une de plus.**
 * Chacune associe des formules françaises à une méthode nommée de
 * `RapportService` ou de `ServiceAnalyseCatalogue`. Aucune requête n'est
 * fabriquée depuis la question : le pire qu'une question mal comprise
 * puisse produire, c'est le mauvais indicateur — jamais une instruction
 * que personne n'a écrite. Sur une application qui suit de l'argent
 * public, c'est la seule garantie qui vaille.
 *
 * **Le score minimal est de deux termes, et l'asymétrie est voulue.**
 * Une expression d'un seul mot — « artisans », « ventes » — capterait
 * des questions descriptives et leur répondrait par un montant. En
 * exigeant deux termes, on accepte de laisser passer quelques questions
 * d'agrégation vers la branche descriptive, où elles n'obtiendront rien
 * plutôt qu'un chiffre faux. Un faux négatif coûte une question sans
 * réponse ; un faux positif coûte un montant attribué au mauvais
 * indicateur. Les deux erreurs ne se valent pas.
 *
 * **Ce qui n'y figure pas.** Le taux de recouvrement des redevances :
 * `EcheanceRedevance` et `PaiementRedevance` ont été retirés du
 * périmètre (DT-04 de `docs/dette-technique.md`), il n'existe donc
 * aucun indicateur à router. Inventer l'intention donnerait une
 * question reconnue et une méthode absente.
 */
class CatalogueDIntentions
{
    /** @var array<int, Intention>|null */
    protected ?array $intentions = null;

    public function __construct(protected ?Normalisateur $normalisateur = null) {}

    /**
     * @return array<int, Intention>
     */
    public function toutes(): array
    {
        return $this->intentions ??= $this->construire();
    }

    public function parCle(string $cle): ?Intention
    {
        foreach ($this->toutes() as $intention) {
            if ($intention->cle === $cle) {
                return $intention;
            }
        }

        return null;
    }

    public function nombre(): int
    {
        return count($this->toutes());
    }

    /**
     * @return array<int, Intention>
     */
    protected function construire(): array
    {
        $n = $this->normalisateur ?? Normalisateur::depuisLaConfiguration();

        $definir = fn (string $cle, string $libelle, array $expressions, \Closure $resolveur, array $requis = []): Intention
            => Intention::definir($cle, $libelle, $expressions, $resolveur, $requis, $n);

        return [

            // ---------- RECETTES -------------------------------------

            $definir(
                'chiffre_affaires',
                'Chiffre d\'affaires',
                ['chiffre affaires', 'chiffre d affaires', 'montant des ventes', 'total des ventes', 'combien vendu', 'recettes des ventes'],
                function (ContexteDeCalcul $c, ParametresQuestion $p): array {
                    // L'intention s'adapte à ce que la question a nommé.
                    // Sans cela, « combien a vendu Kamdem » recevrait le
                    // chiffre du village entier — un montant juste,
                    // attribué à la mauvaise personne, ce qui est pire
                    // qu'une absence de réponse.
                    if ($p->artisanMatricule !== null) {
                        $ligne = $this->ligneParDetail($c->rapport->ventesParArtisan($p->filtre), $p->artisanMatricule);

                        return [
                            'texte' => $ligne === null
                                ? "Aucune vente enregistrée pour {$p->artisanNom} {$p->libellePeriode}."
                                : "{$p->artisanNom} a réalisé ".ContexteDeCalcul::montant($ligne['total'])
                                    .' sur '.ContexteDeCalcul::nombre($ligne['nombre']).' vente(s) '.$p->libellePeriode.'.',
                            'lignes' => $ligne === null ? [] : [$ligne],
                        ];
                    }

                    if ($p->boutiqueNumero !== null) {
                        $ligne = $this->ligneParLibelle($c->rapport->ventesParBoutique($p->filtre), $p->boutiqueNumero);

                        return [
                            'texte' => $ligne === null
                                ? "Aucune vente enregistrée pour la boutique {$p->boutiqueNumero} {$p->libellePeriode}."
                                : "La boutique {$p->boutiqueNumero} a réalisé ".ContexteDeCalcul::montant($ligne['total'])
                                    .' sur '.ContexteDeCalcul::nombre($ligne['nombre']).' vente(s) '.$p->libellePeriode.'.',
                            'lignes' => $ligne === null ? [] : [$ligne],
                        ];
                    }

                    $montant = $c->rapport->chiffreAffaires($p->filtre);

                    return [
                        'texte' => 'Chiffre d\'affaires '.$p->libellePeriode.' : '.ContexteDeCalcul::montant($montant).'.',
                        'lignes' => [],
                    ];
                },
            ),

            $definir(
                'recettes_commission',
                'Recettes de commission',
                ['recettes de commission', 'montant des commissions', 'commission du village', 'part du village', 'combien de commission'],
                fn (ContexteDeCalcul $c, ParametresQuestion $p): array => [
                    'texte' => 'Recettes de commission '.$p->libellePeriode.' : '
                        .ContexteDeCalcul::montant($c->rapport->recettesDeCommission($p->filtre)).'.',
                    'lignes' => [],
                ],
            ),

            $definir(
                'nombre_ventes',
                'Nombre de ventes',
                ['combien de ventes', 'nombre de ventes', 'nombre de transactions', 'combien de transactions'],
                fn (ContexteDeCalcul $c, ParametresQuestion $p): array => [
                    'texte' => ContexteDeCalcul::nombre($c->rapport->nombreDeVentes($p->filtre))
                        .' vente(s) enregistrée(s) '.$p->libellePeriode.'.',
                    'lignes' => [],
                ],
            ),

            $definir(
                'panier_moyen',
                'Panier moyen',
                ['panier moyen', 'montant moyen', 'vente moyenne', 'moyenne par vente'],
                function (ContexteDeCalcul $c, ParametresQuestion $p): array {
                    $nombre = $c->rapport->nombreDeVentes($p->filtre);
                    $total = $c->rapport->chiffreAffaires($p->filtre);

                    return [
                        'texte' => $nombre === 0
                            ? 'Aucune vente '.$p->libellePeriode.' : le panier moyen n\'est pas calculable.'
                            : 'Panier moyen '.$p->libellePeriode.' : '
                                .ContexteDeCalcul::montant(intdiv($total, $nombre))
                                .' ('.ContexteDeCalcul::montant($total).' sur '.ContexteDeCalcul::nombre($nombre).' ventes).',
                        'lignes' => [],
                    ];
                },
            ),

            // ---------- TRÉSORERIE -----------------------------------

            $definir(
                'dettes_artisans',
                'Dettes envers les artisans',
                ['dettes artisans', 'dettes envers artisans', 'reste a reverser', 'montant non reverse', 'part artisan non reversee'],
                fn (ContexteDeCalcul $c, ParametresQuestion $p): array => [
                    // Sans période : une dette n'a pas d'intervalle, elle
                    // est due jusqu'à son reversement (RG-13). Le
                    // rappeler évite qu'on lise ce montant comme celui
                    // du mois.
                    'texte' => 'Le village doit '.ContexteDeCalcul::montant($c->rapport->dettesEnversLesArtisans())
                        .' aux artisans, toutes périodes confondues : une dette court jusqu\'à son reversement.',
                    'lignes' => [],
                ],
            ),

            $definir(
                'solde_caisse',
                'Solde de caisse',
                ['solde de caisse', 'tresorerie disponible', 'argent en caisse', 'combien en caisse', 'soldes des caisses'],
                function (ContexteDeCalcul $c, ParametresQuestion $p): array {
                    $detail = $c->rapport->soldesParCaisse();
                    $consolide = $c->rapport->soldeDeCaisseConsolide();

                    return [
                        'texte' => $detail === []
                            ? 'Aucune caisse n\'a de section ouverte : il n\'y a pas de solde courant.'
                            : 'Trésorerie disponible : '.ContexteDeCalcul::montant($consolide)
                                .' sur '.count($detail).' caisse(s) ouverte(s).',
                        'lignes' => array_map(fn (array $ligne): array => [
                            'libelle' => $ligne['code'].' — '.$ligne['libelle'],
                            'detail' => null,
                            'nombre' => 0,
                            'total' => $ligne['solde'],
                        ], $detail),
                    ];
                },
            ),

            $definir(
                'dernier_reversement',
                'Dernier reversement',
                ['dernier reversement', 'derniere campagne', 'derniere campagne de reversement', 'montant reverse'],
                function (ContexteDeCalcul $c, ParametresQuestion $p): array {
                    $campagne = $c->rapport->dernierReversement();

                    return [
                        'texte' => $campagne === null
                            ? 'Aucune campagne de reversement n\'a encore été validée.'
                            : 'Dernière campagne validée : '.$campagne->libellePeriode().', '
                                .ContexteDeCalcul::montant($c->rapport->montantDernierReversement())
                                .' décaissés au profit de '.ContexteDeCalcul::nombre((int) $campagne->nombre_beneficiaires).' artisan(s).',
                        'lignes' => [],
                    ];
                },
            ),

            // ---------- VENTILATIONS ---------------------------------

            $definir(
                'ventes_par_boutique',
                'Ventes par boutique',
                ['ventes par boutique', 'repartition par boutique', 'ventilation par boutique', 'classement des boutiques'],
                fn (ContexteDeCalcul $c, ParametresQuestion $p): array => $this->ventilation(
                    $c->rapport->ventesParBoutique($p->filtre),
                    'Ventes par boutique '.$p->libellePeriode,
                ),
            ),

            $definir(
                'ventes_par_artisan',
                'Ventes par artisan',
                ['ventes par artisan', 'repartition par artisan', 'ventilation par artisan', 'classement des artisans'],
                fn (ContexteDeCalcul $c, ParametresQuestion $p): array => $this->ventilation(
                    $c->rapport->ventesParArtisan($p->filtre),
                    'Ventes par artisan '.$p->libellePeriode,
                ),
            ),

            $definir(
                'ventes_par_vendeur',
                'Ventes par vendeur',
                ['ventes par vendeur', 'repartition par vendeur', 'ventilation par vendeur', 'quel agent a vendu'],
                fn (ContexteDeCalcul $c, ParametresQuestion $p): array => $this->ventilation(
                    $c->rapport->ventesParVendeur($p->filtre),
                    'Ventes par vendeur '.$p->libellePeriode,
                ),
            ),

            $definir(
                'provenance_clients',
                'Provenance des clients',
                ['provenance des clients', 'origine des clients', 'd ou viennent les clients', 'repartition des clients'],
                function (ContexteDeCalcul $c, ParametresQuestion $p): array {
                    $lignes = $c->rapport->ventesParProvenanceClient($p->filtre);

                    return [
                        'texte' => $lignes === []
                            ? 'Aucune vente '.$p->libellePeriode.' : la provenance des clients n\'est pas renseignée.'
                            : 'Provenance des clients '.$p->libellePeriode.' — '.count($lignes).' catégorie(s).',
                        'lignes' => array_map(fn (array $ligne): array => $ligne + ['detail' => null], $lignes),
                    ];
                },
            ),

            $definir(
                'meilleur_artisan',
                'Artisan le plus vendeur',
                ['meilleur artisan', 'quel artisan vend le plus', 'artisan qui vend le plus', 'premier artisan'],
                fn (ContexteDeCalcul $c, ParametresQuestion $p): array => $this->premiereLigne(
                    $c->rapport->ventesParArtisan($p->filtre),
                    'artisan',
                    $p->libellePeriode,
                ),
            ),

            $definir(
                'meilleure_boutique',
                'Boutique la plus vendeuse',
                ['meilleure boutique', 'quelle boutique vend le plus', 'boutique qui vend le plus', 'premiere boutique'],
                fn (ContexteDeCalcul $c, ParametresQuestion $p): array => $this->premiereLigne(
                    $c->rapport->ventesParBoutique($p->filtre),
                    'boutique',
                    $p->libellePeriode,
                ),
            ),

            // ---------- INTENTIONS À PARAMÈTRE OBLIGATOIRE -----------

            $definir(
                'situation_artisan',
                'Situation d\'un artisan',
                ['situation artisan', 'compte artisan', 'situation de l artisan', 'point sur l artisan'],
                function (ContexteDeCalcul $c, ParametresQuestion $p): array {
                    $ligne = $this->ligneParDetail($c->rapport->ventesParArtisan($p->filtre), (string) $p->artisanMatricule);

                    return [
                        'texte' => $ligne === null
                            ? "Aucune vente enregistrée pour {$p->artisanNom} {$p->libellePeriode}."
                            : "{$p->artisanNom} : ".ContexteDeCalcul::montant($ligne['total'])
                                .' sur '.ContexteDeCalcul::nombre($ligne['nombre']).' vente(s) '.$p->libellePeriode.'.',
                        'lignes' => $ligne === null ? [] : [$ligne],
                    ];
                },
                ['artisan'],
            ),

            $definir(
                'activite_boutique',
                'Activité d\'une boutique',
                ['activite de la boutique', 'situation de la boutique', 'chiffre de la boutique', 'point sur la boutique'],
                function (ContexteDeCalcul $c, ParametresQuestion $p): array {
                    $ligne = $this->ligneParLibelle($c->rapport->ventesParBoutique($p->filtre), (string) $p->boutiqueNumero);

                    return [
                        'texte' => $ligne === null
                            ? "Aucune vente enregistrée pour la boutique {$p->boutiqueNumero} {$p->libellePeriode}."
                            : "Boutique {$p->boutiqueNumero} : ".ContexteDeCalcul::montant($ligne['total'])
                                .' sur '.ContexteDeCalcul::nombre($ligne['nombre']).' vente(s) '.$p->libellePeriode.'.',
                        'lignes' => $ligne === null ? [] : [$ligne],
                    ];
                },
                ['boutique'],
            ),

            // ---------- PARC ET CATALOGUE ----------------------------

            $definir(
                'taux_occupation',
                'Taux d\'occupation du parc',
                ['taux occupation', 'taux d occupation', 'espaces occupes', 'espaces libres', 'combien d espaces locatifs'],
                function (ContexteDeCalcul $c, ParametresQuestion $p): array {
                    $taux = $c->rapport->tauxOccupationEspaces();

                    return [
                        'texte' => $taux['total'] === 0
                            ? 'Aucun espace locatif n\'est enregistré : le taux d\'occupation n\'est pas calculable.'
                            : $taux['taux'].' % du parc est occupé — '
                                .ContexteDeCalcul::nombre($taux['occupes']).' espace(s) sur '
                                .ContexteDeCalcul::nombre($taux['total']).'.',
                        'lignes' => [],
                    ];
                },
            ),

            $definir(
                'produits_sous_seuil',
                'Produits sous le seuil d\'alerte',
                ['produits en rupture', 'produits sous le seuil', 'alerte de stock', 'stock bas', 'ruptures de stock'],
                function (ContexteDeCalcul $c, ParametresQuestion $p): array {
                    $produits = $c->rapport->produitsSousLeSeuil(10);

                    return [
                        'texte' => $produits === []
                            ? 'Aucun produit surveillé n\'est retombé à son seuil d\'alerte.'
                            : count($produits).' produit(s) au niveau de leur seuil d\'alerte.',
                        'lignes' => array_map(fn (array $ligne): array => [
                            'libelle' => $ligne['reference'].' — '.$ligne['designation'],
                            'detail' => $ligne['boutique'],
                            'nombre' => $ligne['stock'],
                            'total' => $ligne['seuil'],
                        ], $produits),
                    ];
                },
            ),

            $definir(
                'nombre_produits_sous_seuil',
                'Nombre de produits en rupture',
                ['combien de produits en rupture', 'combien de ruptures', 'nombre de produits en rupture'],
                fn (ContexteDeCalcul $c, ParametresQuestion $p): array => [
                    'texte' => ContexteDeCalcul::nombre($c->rapport->nombreDeProduitsSousLeSeuil())
                        .' produit(s) surveillé(s) sont au niveau de leur seuil d\'alerte.',
                    'lignes' => [],
                ],
            ),

            $definir(
                'produits_isoles',
                'Produits sans équivalent',
                ['produits isoles', 'produits sans equivalent', 'pieces uniques du catalogue', 'produits a mettre en avant'],
                function (ContexteDeCalcul $c, ParametresQuestion $p): array {
                    $produits = $c->analyse->produitsIsoles();

                    return [
                        'texte' => $produits->isEmpty()
                            ? 'Aucun produit isolé : chaque article du catalogue a au moins un proche.'
                            : $produits->count().' produit(s) sans équivalent au catalogue — candidats à une mise en avant.',
                        'lignes' => $produits->map(fn (array $ligne): array => [
                            'libelle' => $ligne['reference'].' — '.$ligne['designation'],
                            'detail' => $ligne['artisan'],
                            'nombre' => 0,
                            'total' => 0,
                        ])->all(),
                    ];
                },
            ),

            $definir(
                'nombre_produits_isoles',
                'Nombre de produits isolés',
                ['combien de produits isoles', 'nombre de produits isoles'],
                fn (ContexteDeCalcul $c, ParametresQuestion $p): array => [
                    'texte' => ContexteDeCalcul::nombre($c->analyse->nombreDeProduitsIsoles())
                        .' produit(s) n\'ont aucun équivalent au catalogue.',
                    'lignes' => [],
                ],
            ),

            $definir(
                'segments_satures',
                'Segments saturés',
                ['segments satures', 'offre concentree', 'concurrence entre artisans', 'produits trop nombreux'],
                function (ContexteDeCalcul $c, ParametresQuestion $p): array {
                    $segments = $c->analyse->segmentsSatures();

                    return [
                        'texte' => $segments->isEmpty()
                            ? 'Aucun segment saturé : aucun groupe de produits très proches n\'est porté par plusieurs artisans.'
                            : $segments->count().' segment(s) où plusieurs artisans proposent des articles très proches.',
                        'lignes' => $segments->map(fn (array $ligne): array => [
                            'libelle' => $ligne['reference'].' — '.$ligne['designation'],
                            'detail' => $ligne['artisan'],
                            'nombre' => $ligne['concurrents'],
                            'total' => 0,
                        ])->all(),
                    ];
                },
            ),
        ];
    }

    // =================================================================
    //  MISE EN FORME COMMUNE
    // =================================================================

    /**
     * @param  array<int, array{libelle: string, detail: ?string, nombre: int, total: int}>  $lignes
     * @return array{texte: string, lignes: array}
     */
    protected function ventilation(array $lignes, string $entete): array
    {
        return [
            'texte' => $lignes === []
                ? 'Aucune vente sur la période demandée : la ventilation est vide.'
                : $entete.' — '.count($lignes).' ligne(s), '
                    .ContexteDeCalcul::montant((int) array_sum(array_column($lignes, 'total'))).' au total.',
            'lignes' => $lignes,
        ];
    }

    /**
     * @param  array<int, array{libelle: string, detail: ?string, nombre: int, total: int}>  $lignes
     * @return array{texte: string, lignes: array}
     */
    protected function premiereLigne(array $lignes, string $quoi, string $periode): array
    {
        if ($lignes === []) {
            return ['texte' => 'Aucune vente '.$periode.' : il n\'y a pas de classement à établir.', 'lignes' => []];
        }

        $premiere = $lignes[0];

        return [
            'texte' => 'Le premier '.$quoi.' '.$periode.' est '.$premiere['libelle']
                .' avec '.ContexteDeCalcul::montant($premiere['total'])
                .' sur '.ContexteDeCalcul::nombre($premiere['nombre']).' vente(s).',
            'lignes' => array_slice($lignes, 0, 5),
        ];
    }

    /**
     * @param  array<int, array{libelle: string, detail: ?string, nombre: int, total: int}>  $lignes
     * @return array{libelle: string, detail: ?string, nombre: int, total: int}|null
     */
    protected function ligneParDetail(array $lignes, string $detail): ?array
    {
        foreach ($lignes as $ligne) {
            if ($ligne['detail'] !== null && $ligne['detail'] === $detail) {
                return $ligne;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{libelle: string, detail: ?string, nombre: int, total: int}>  $lignes
     * @return array{libelle: string, detail: ?string, nombre: int, total: int}|null
     */
    protected function ligneParLibelle(array $lignes, string $libelle): ?array
    {
        foreach ($lignes as $ligne) {
            if ($ligne['libelle'] === $libelle) {
                return $ligne;
            }
        }

        return null;
    }
}
