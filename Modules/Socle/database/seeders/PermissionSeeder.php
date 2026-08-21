<?php

namespace Modules\Socle\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Socle\Models\Utilisateur;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Référentiel des permissions et des profils d'habilitation.
 *
 * Ce seeder est le point de vérité unique des permissions du projet :
 * chaque module ajoute ses lignes dans le tableau ci-dessous plutôt que
 * de créer son propre seeder, ce qui rend la matrice des habilitations
 * lisible d'un seul coup d'œil au moment de la soutenance.
 *
 * Le seeder est idempotent : il utilise updateOrCreate, si bien qu'un
 * nouveau passage après ajout d'un module n'écrase aucune attribution
 * existante.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Le registre de spatie met les permissions en cache. Sans
        // purge préalable, un migrate:fresh --seed relit un cache
        // pointant sur des identifiants qui n'existent plus.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions() as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name'], 'guard_name' => 'web'],
                ['module' => $permission['module'], 'description' => $permission['description']],
            );
        }

        foreach ($this->roles() as $nom => $definition) {
            $role = Role::updateOrCreate(
                ['name' => $nom, 'guard_name' => 'web'],
                ['description' => $definition['description']],
            );

            // Le super-utilisateur ne reçoit aucune permission nominale :
            // le Gate::before du SocleServiceProvider les lui accorde
            // toutes. Lui en attribuer ferait double emploi et laisserait
            // croire que la liste est limitative.
            if ($nom === Utilisateur::ROLE_SUPER_UTILISATEUR) {
                continue;
            }

            $role->syncPermissions($definition['permissions']);
        }

        $this->retirerLesRolesObsoletes();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array<int, array{name: string, module: string, description: string}>
     */
    protected function permissions(): array
    {
        return [
            // === SOCLE : villages ===
            ['name' => 'lister_villages', 'module' => 'SOCLE', 'description' => 'Consulter les villages artisanaux'],
            ['name' => 'ajouter_village', 'module' => 'SOCLE', 'description' => 'Créer un village artisanal'],
            ['name' => 'modifier_village', 'module' => 'SOCLE', 'description' => 'Modifier un village artisanal'],
            ['name' => 'supprimer_village', 'module' => 'SOCLE', 'description' => 'Supprimer un village artisanal'],

            // === SOCLE : exercices ===
            ['name' => 'lister_exercices', 'module' => 'SOCLE', 'description' => 'Consulter les exercices'],
            ['name' => 'ajouter_exercice', 'module' => 'SOCLE', 'description' => 'Créer un exercice'],
            ['name' => 'modifier_exercice', 'module' => 'SOCLE', 'description' => 'Modifier un exercice'],
            ['name' => 'supprimer_exercice', 'module' => 'SOCLE', 'description' => 'Supprimer un exercice'],
            ['name' => 'activer_exercice', 'module' => 'SOCLE', 'description' => 'Rendre un exercice courant'],
            ['name' => 'cloturer_exercice', 'module' => 'SOCLE', 'description' => 'Clôturer définitivement un exercice'],

            // === SOCLE : agents ===
            ['name' => 'lister_agents', 'module' => 'SOCLE', 'description' => 'Consulter les agents'],
            ['name' => 'ajouter_agent', 'module' => 'SOCLE', 'description' => 'Créer un agent'],
            ['name' => 'modifier_agent', 'module' => 'SOCLE', 'description' => 'Modifier un agent'],
            ['name' => 'supprimer_agent', 'module' => 'SOCLE', 'description' => 'Supprimer un agent'],

            // === SÉCURITÉ : utilisateurs ===
            ['name' => 'lister_utilisateurs', 'module' => 'SOCLE', 'description' => 'Consulter les comptes utilisateurs'],
            ['name' => 'ajouter_utilisateur', 'module' => 'SOCLE', 'description' => 'Créer un compte utilisateur'],
            ['name' => 'modifier_utilisateur', 'module' => 'SOCLE', 'description' => 'Modifier un compte utilisateur'],
            ['name' => 'supprimer_utilisateur', 'module' => 'SOCLE', 'description' => 'Supprimer un compte utilisateur'],

            // === SÉCURITÉ : rôles ===
            ['name' => 'lister_roles', 'module' => 'SOCLE', 'description' => 'Consulter les rôles'],
            ['name' => 'ajouter_role', 'module' => 'SOCLE', 'description' => 'Créer un rôle'],
            ['name' => 'modifier_role', 'module' => 'SOCLE', 'description' => 'Modifier un rôle et ses permissions'],
            ['name' => 'supprimer_role', 'module' => 'SOCLE', 'description' => 'Supprimer un rôle'],

            // === SÉCURITÉ : audit ===
            ['name' => 'lister_journaux_audit', 'module' => 'SOCLE', 'description' => 'Consulter le journal d\'audit'],

            // === ARTISANAT : corps de métier ===
            ['name' => 'lister_corps_metiers', 'module' => 'ARTISANAT', 'description' => 'Consulter les corps de métier'],
            ['name' => 'ajouter_corps_metier', 'module' => 'ARTISANAT', 'description' => 'Créer un corps de métier'],
            ['name' => 'modifier_corps_metier', 'module' => 'ARTISANAT', 'description' => 'Modifier un corps de métier'],
            ['name' => 'supprimer_corps_metier', 'module' => 'ARTISANAT', 'description' => 'Supprimer un corps de métier'],

            // === ARTISANAT : entreprises artisanales ===
            ['name' => 'lister_entreprises', 'module' => 'ARTISANAT', 'description' => 'Consulter les entreprises artisanales'],
            ['name' => 'ajouter_entreprise', 'module' => 'ARTISANAT', 'description' => 'Créer une entreprise artisanale'],
            ['name' => 'modifier_entreprise', 'module' => 'ARTISANAT', 'description' => 'Modifier une entreprise artisanale'],
            ['name' => 'supprimer_entreprise', 'module' => 'ARTISANAT', 'description' => 'Supprimer une entreprise artisanale'],

            // === ARTISANAT : artisans ===
            ['name' => 'lister_artisans', 'module' => 'ARTISANAT', 'description' => 'Consulter les artisans'],
            ['name' => 'ajouter_artisan', 'module' => 'ARTISANAT', 'description' => 'Enregistrer un artisan'],
            ['name' => 'modifier_artisan', 'module' => 'ARTISANAT', 'description' => 'Modifier un artisan'],
            ['name' => 'supprimer_artisan', 'module' => 'ARTISANAT', 'description' => 'Supprimer un artisan'],

            // === ARTISANAT : boutiques ===
            ['name' => 'lister_boutiques', 'module' => 'ARTISANAT', 'description' => 'Consulter les boutiques'],
            ['name' => 'ajouter_boutique', 'module' => 'ARTISANAT', 'description' => 'Créer une boutique'],
            ['name' => 'modifier_boutique', 'module' => 'ARTISANAT', 'description' => 'Modifier une boutique'],
            ['name' => 'supprimer_boutique', 'module' => 'ARTISANAT', 'description' => 'Supprimer une boutique'],

            // === ARTISANAT : espaces ===
            ['name' => 'lister_espaces', 'module' => 'ARTISANAT', 'description' => 'Consulter les espaces'],
            ['name' => 'ajouter_espace', 'module' => 'ARTISANAT', 'description' => 'Créer un espace'],
            ['name' => 'modifier_espace', 'module' => 'ARTISANAT', 'description' => 'Modifier un espace'],
            ['name' => 'supprimer_espace', 'module' => 'ARTISANAT', 'description' => 'Supprimer un espace'],

            // === ARTISANAT : attributions de boutiques ===
            ['name' => 'lister_attributions', 'module' => 'ARTISANAT', 'description' => 'Consulter les attributions de boutiques'],
            ['name' => 'ajouter_attribution', 'module' => 'ARTISANAT', 'description' => 'Attribuer une boutique à un artisan'],
            ['name' => 'modifier_attribution', 'module' => 'ARTISANAT', 'description' => 'Modifier une attribution de boutique'],
            ['name' => 'valider_dossier_attribution', 'module' => 'ARTISANAT', 'description' => 'Constater la complétude du dossier administratif d\'une attribution'],
            ['name' => 'resilier_attribution', 'module' => 'ARTISANAT', 'description' => 'Résilier une attribution avant terme'],
            ['name' => 'terminer_attribution', 'module' => 'ARTISANAT', 'description' => 'Clore une attribution arrivée à échéance'],
            ['name' => 'supprimer_attribution', 'module' => 'ARTISANAT', 'description' => 'Supprimer une attribution de boutique'],

            // === COMMERCE : catégories de produits ===
            ['name' => 'lister_categories_produits', 'module' => 'COMMERCE', 'description' => 'Consulter les catégories de produits'],
            ['name' => 'ajouter_categorie_produit', 'module' => 'COMMERCE', 'description' => 'Créer une catégorie de produit'],
            ['name' => 'modifier_categorie_produit', 'module' => 'COMMERCE', 'description' => 'Modifier une catégorie de produit'],
            ['name' => 'supprimer_categorie_produit', 'module' => 'COMMERCE', 'description' => 'Supprimer une catégorie de produit'],

            // === COMMERCE : taux de commission ===
            ['name' => 'lister_taux_commission', 'module' => 'COMMERCE', 'description' => 'Consulter l\'historique des taux de commission'],
            ['name' => 'ajouter_taux_commission', 'module' => 'COMMERCE', 'description' => 'Enregistrer un nouveau taux de commission'],
            ['name' => 'modifier_taux_commission', 'module' => 'COMMERCE', 'description' => 'Corriger un taux de commission non encore entré en vigueur'],

            // === COMMERCE : produits ===
            ['name' => 'lister_produits', 'module' => 'COMMERCE', 'description' => 'Consulter le catalogue des produits'],
            ['name' => 'ajouter_produit', 'module' => 'COMMERCE', 'description' => 'Déposer un produit au catalogue'],
            ['name' => 'modifier_produit', 'module' => 'COMMERCE', 'description' => 'Modifier un produit'],
            ['name' => 'supprimer_produit', 'module' => 'COMMERCE', 'description' => 'Supprimer un produit'],
            ['name' => 'valider_produit', 'module' => 'COMMERCE', 'description' => 'Valider un produit soumis (section Production)'],
            ['name' => 'exposer_produit', 'module' => 'COMMERCE', 'description' => 'Mettre un produit en vitrine (section Promotion)'],
            ['name' => 'retirer_produit', 'module' => 'COMMERCE', 'description' => 'Retirer un produit de la circulation'],

            // === COMMERCE : dépôts ===
            ['name' => 'lister_depots', 'module' => 'COMMERCE', 'description' => 'Consulter les dépôts d\'articles'],
            ['name' => 'ajouter_depot', 'module' => 'COMMERCE', 'description' => 'Enregistrer un dépôt en brouillon'],
            ['name' => 'modifier_depot', 'module' => 'COMMERCE', 'description' => 'Modifier un dépôt en brouillon'],
            ['name' => 'supprimer_depot', 'module' => 'COMMERCE', 'description' => 'Supprimer un dépôt en brouillon'],
            ['name' => 'valider_depot', 'module' => 'COMMERCE', 'description' => 'Valider un dépôt : entrée en stock et décharge signée'],
            ['name' => 'imprimer_decharge_depot', 'module' => 'COMMERCE', 'description' => 'Éditer la décharge de dépôt'],

            // === COMMERCE : journal de stock ===
            ['name' => 'lister_mouvements_stock', 'module' => 'COMMERCE', 'description' => 'Consulter le journal de stock'],
            ['name' => 'retirer_stock', 'module' => 'COMMERCE', 'description' => 'Enregistrer une reprise d\'articles par l\'artisan'],
            ['name' => 'contrepasser_mouvement_stock', 'module' => 'COMMERCE', 'description' => 'Corriger un mouvement de stock par contre-passation'],

            // === COMMERCE : ventes ===
            ['name' => 'lister_ventes', 'module' => 'COMMERCE', 'description' => 'Consulter le registre des ventes'],
            ['name' => 'ajouter_vente', 'module' => 'COMMERCE', 'description' => 'Saisir une vente au comptoir'],
            ['name' => 'annuler_vente', 'module' => 'COMMERCE', 'description' => 'Annuler une vente : retour en stock et contre-passation en caisse'],
            ['name' => 'imprimer_recu_vente', 'module' => 'COMMERCE', 'description' => 'Éditer le reçu de vente'],
        ];
    }

    /**
     * Profils calqués sur l'organigramme réel de la structure :
     * coordonnateur, coordonnateur adjoint et cinq chefs de section.
     *
     * Chaque section ne reçoit que ce que ses attributions justifient.
     * C'est la lecture directe de la règle de séparation des rôles de
     * CLAUDE.md : la section qui vendra n'est pas celle qui tient le
     * parc de boutiques, ni celle qui clôturera une section de caisse.
     * Les modules Commerce et Trésorerie compléteront ces listes sans
     * élargir aucun périmètre.
     *
     * `super_utilisateur` est tenu à part : c'est un compte technique,
     * pas une fonction. Le coordonnateur est un rôle métier — il valide
     * les dossiers, attribue les boutiques, consulte les tableaux de
     * bord. Les fusionner voudrait dire qu'une personne exerçant une
     * fonction administrative peut modifier ses propres permissions et
     * effacer des traces : dans une structure qui manipule de l'argent
     * public, c'est précisément ce qu'un contrôle interne cherche à
     * empêcher. En pratique le coordonnateur du village portera sans
     * doute les deux rôles, mais ce sera une décision d'attribution
     * explicite, jamais une propriété du modèle.
     *
     * **Suppressions.** Elles suivent une seule règle : ce qui porte une
     * histoire ne se supprime pas, ce qui n'est qu'un libellé se
     * corrige. Artisan, attribution, boutique, exercice et village
     * restent hors de portée de tout rôle métier — on les désactive, on
     * les résilie, on les clôture. Corps de métier et entreprise
     * artisanale s'ouvrent en revanche à qui peut les créer : sans
     * cela, un chef de section ayant saisi un doublon devrait appeler
     * l'administrateur pour corriger sa propre erreur, et en pratique
     * il contournerait en laissant traîner une ligne « à supprimer ».
     *
     * @return array<string, array{description: string, permissions: array<int, string>}>
     */
    protected function roles(): array
    {
        // Lecture du référentiel : le socle commun à toutes les
        // sections, sans lequel aucun écran métier n'est exploitable.
        $lectureReferentiel = [
            'lister_villages',
            'lister_exercices',
            'lister_corps_metiers',
            'lister_artisans',
            'lister_entreprises',
            'lister_boutiques',
            'lister_espaces',
            'lister_attributions',
            'lister_categories_produits',
            'lister_produits',
            'lister_depots',
            'lister_mouvements_stock',
            'lister_ventes',
        ];

        return [
            Utilisateur::ROLE_SUPER_UTILISATEUR => [
                'description' => 'Administrateur technique : toutes les permissions, y compris celles des modules à venir',
                'permissions' => [],
            ],

            'coordonnateur' => [
                'description' => 'Coordonnateur : dirige le village, arrête les exercices et signe les attributions',
                'permissions' => [
                    ...$lectureReferentiel,
                    'modifier_village',
                    'ajouter_exercice', 'modifier_exercice', 'activer_exercice', 'cloturer_exercice',
                    'lister_agents', 'ajouter_agent', 'modifier_agent',
                    'lister_utilisateurs', 'ajouter_utilisateur', 'modifier_utilisateur',
                    'lister_roles',
                    'lister_journaux_audit',
                    'ajouter_corps_metier', 'modifier_corps_metier', 'supprimer_corps_metier',
                    'ajouter_entreprise', 'modifier_entreprise', 'supprimer_entreprise',
                    'ajouter_artisan', 'modifier_artisan',
                    'ajouter_boutique', 'modifier_boutique',
                    'ajouter_espace', 'modifier_espace',
                    'ajouter_attribution', 'modifier_attribution',
                    'resilier_attribution', 'terminer_attribution',
                    // Le droit de produire la trace est distinct du
                    // droit de modifier l'enregistrement : constater la
                    // complétude d'un dossier engage celui qui coche,
                    // et n'appartient qu'au coordonnateur.
                    'valider_dossier_attribution',
                    'ajouter_categorie_produit', 'modifier_categorie_produit', 'supprimer_categorie_produit',
                    // Le taux de commission engage la recette du
                    // village : sa décision revient à la direction.
                    'lister_taux_commission', 'ajouter_taux_commission', 'modifier_taux_commission',
                    'ajouter_produit', 'modifier_produit', 'retirer_produit',
                    // Règle 13 amendée : la responsabilité de la
                    // validation reste au chef de section Production,
                    // le coordonnateur supplée en son absence. Sans
                    // cela, une seule absence bloquerait toute entrée
                    // en exposition — un point de défaillance unique
                    // sur le flux principal. Le contrôle est
                    // qualitatif, non financier : RG-23 ne s'y applique
                    // pas, et le journal d'audit conserve de toute
                    // façon l'identité du validateur réel.
                    'valider_produit',
                    'ajouter_depot', 'modifier_depot', 'supprimer_depot',
                    'valider_depot', 'imprimer_decharge_depot',
                    'retirer_stock', 'contrepasser_mouvement_stock',
                    // La direction annule et réédite, mais ne saisit
                    // pas : RG-23 sépare l'agent de saisie du profil
                    // habilité à défaire une opération.
                    'annuler_vente', 'imprimer_recu_vente',
                ],
            ],

            'coordonnateur_adjoint' => [
                'description' => 'Coordonnateur adjoint : seconde le coordonnateur au quotidien, sans les actes irréversibles',
                'permissions' => [
                    ...$lectureReferentiel,
                    'lister_agents', 'ajouter_agent', 'modifier_agent',
                    'lister_journaux_audit',
                    'ajouter_corps_metier', 'modifier_corps_metier', 'supprimer_corps_metier',
                    'ajouter_entreprise', 'modifier_entreprise', 'supprimer_entreprise',
                    'ajouter_artisan', 'modifier_artisan',
                    'ajouter_boutique', 'modifier_boutique',
                    'ajouter_espace', 'modifier_espace',
                    'ajouter_attribution', 'modifier_attribution',
                    'terminer_attribution',
                    'ajouter_categorie_produit', 'modifier_categorie_produit', 'supprimer_categorie_produit',
                    'lister_taux_commission',
                    'ajouter_produit', 'modifier_produit', 'retirer_produit',
                    'ajouter_depot', 'modifier_depot', 'supprimer_depot',
                    'valider_depot', 'imprimer_decharge_depot',
                    'retirer_stock',
                    'imprimer_recu_vente',
                    // Volontairement absents : cloturer_exercice et
                    // resilier_attribution. Clôturer un exercice est
                    // irréversible, résilier rompt un contrat : ces deux
                    // actes engagent le coordonnateur lui-même.
                ],
            ],

            'chef_section_production' => [
                'description' => 'Chef de section Production : encadre les artisans et la production, valide les produits',
                'permissions' => [
                    ...$lectureReferentiel,
                    'ajouter_corps_metier', 'modifier_corps_metier', 'supprimer_corps_metier',
                    'ajouter_artisan', 'modifier_artisan',
                    'ajouter_categorie_produit', 'modifier_categorie_produit', 'supprimer_categorie_produit',
                    'ajouter_produit', 'modifier_produit', 'retirer_produit',
                    // Règle 13 : « Seul le chef de section Production
                    // valide. » Aucun autre rôle métier ne porte cette
                    // permission, pas même le coordonnateur.
                    'valider_produit',
                    // Le dépôt et le stock sont le quotidien de la
                    // section : elle reçoit les pièces, les compte et
                    // les rend.
                    'ajouter_depot', 'modifier_depot', 'supprimer_depot',
                    'valider_depot', 'imprimer_decharge_depot',
                    'retirer_stock', 'contrepasser_mouvement_stock',
                ],
            ],

            'chef_section_formation' => [
                'description' => 'Chef de section Formation : organise les sessions et gère les salles d\'apprentissage',
                'permissions' => [
                    ...$lectureReferentiel,
                    'ajouter_espace', 'modifier_espace',
                ],
            ],

            'chef_section_administrative_financiere' => [
                'description' => 'Chef de section Administrative et Financière : tient le parc de boutiques, les attributions et les redevances',
                'permissions' => [
                    ...$lectureReferentiel,
                    'lister_agents', 'ajouter_agent', 'modifier_agent',
                    'lister_journaux_audit',
                    'ajouter_entreprise', 'modifier_entreprise', 'supprimer_entreprise',
                    'ajouter_boutique', 'modifier_boutique',
                    'ajouter_attribution', 'modifier_attribution',
                    'resilier_attribution', 'terminer_attribution',
                    // Lecture seule sur le taux : la section suit la
                    // recette, elle ne fixe pas le barème.
                    'lister_taux_commission',
                    // Elle réédite les décharges et les reçus pour les
                    // dossiers administratifs, sans toucher au stock.
                    'imprimer_decharge_depot',
                    'imprimer_recu_vente',
                ],
            ],

            'chef_section_promotion_commercialisation' => [
                'description' => 'Chef de section Promotion et Commercialisation : expose et vend les produits, anime le portail public',
                'permissions' => [
                    ...$lectureReferentiel,
                    // Lecture seule sur le référentiel : la section a
                    // besoin de savoir quelle boutique appartient à quel
                    // artisan pour vendre, pas de modifier le parc. Ses
                    // permissions de vente arriveront avec le module
                    // Commerce — et la règle de séparation des rôles
                    // interdit qu'elles se cumulent avec la tenue de
                    // caisse.
                    //
                    // La mise en vitrine, elle, est son métier : elle
                    // choisit ce que le portail met en avant, parmi les
                    // produits que la Production a validés.
                    'exposer_produit', 'retirer_produit',
                    // La saisie de vente est son métier. Elle ne peut
                    // pas annuler ce qu'elle a saisi : RG-23 réserve ce
                    // geste à un profil distinct de l'agent de saisie.
                    'ajouter_vente', 'imprimer_recu_vente',
                ],
            ],

            'chef_section_orientation_information_documentation' => [
                'description' => 'Chef de section Orientation, Information et Documentation : accueille les visiteurs et tient les dossiers des artisans',
                'permissions' => [
                    ...$lectureReferentiel,
                    'ajouter_artisan', 'modifier_artisan',
                    'ajouter_entreprise', 'modifier_entreprise', 'supprimer_entreprise',
                ],
            ],
        ];
    }

    /**
     * Retire les profils de la première version, remplacés par
     * l'organigramme réel de la structure.
     *
     * La liste est nominative, jamais « tout ce qui n'est pas déclaré » :
     * un rôle créé à la main depuis l'écran des rôles ne doit pas
     * disparaître au prochain passage du seeder. `migrate:fresh` rend
     * ce nettoyage inutile, mais un `db:seed` sur une base existante
     * laisserait sinon traîner des rôles porteurs de permissions.
     */
    protected function retirerLesRolesObsoletes(): void
    {
        $obsoletes = ['agent_commercial', 'caissier'];

        Role::query()
            ->whereIn('name', $obsoletes)
            ->get()
            ->each(function (Role $role): void {
                $role->syncPermissions([]);
                $role->delete();
            });
    }
}
