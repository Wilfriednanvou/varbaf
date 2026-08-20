<?php

namespace Modules\Socle\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Enums\Sexe;
use Modules\Socle\Models\Agent;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;

/**
 * Jeu d'amorçage minimal : le village réel, son exercice courant, et le
 * compte d'administration qui permet la première connexion.
 *
 * Conformément à la règle de CLAUDE.md, aucune donnée fictive du type
 * « Village test » : les valeurs ci-dessous sont celles du Village
 * Artisanal Régional de Bafoussam. Les données de production — artisans,
 * boutiques, registre de ventes transcrit — seront apportées par les
 * seeders de leurs modules respectifs.
 *
 * Le seeder est idempotent : il s'appuie sur firstOrCreate et peut donc
 * être rejoué sans dupliquer le village ni l'exercice.
 */
class SuperUtilisateurSeeder extends Seeder
{
    public function run(): void
    {
        $village = VillageArtisanal::firstOrCreate(
            ['code' => 'VARBAF'],
            [
                'nom' => 'Village Artisanal Régional de Bafoussam',
                'categorie' => CategorieVillage::REGIONAL,
                'region' => 'Ouest',
                'adresse' => 'Bafoussam, département de la Mifi',
                'telephone' => null,
                'email' => null,
                'nombre_boutiques' => 24,
                'superficie' => null,
                'actif' => true,
            ],
        );

        // L'exercice court sur l'année civile : c'est le découpage
        // retenu avec la coordination, et il coïncide avec la période
        // du registre de ventes transcrit.
        $annee = (int) now()->format('Y');

        $exercice = Exercice::firstOrCreate(
            ['village_id' => $village->id, 'libelle' => (string) $annee],
            [
                'date_debut' => "{$annee}-01-01",
                'date_fin' => "{$annee}-12-31",
                'cloture' => false,
            ],
        );

        // Passe par activer() plutôt que par un champ du firstOrCreate :
        // la bascule de l'exercice précédent doit rester le fait du
        // modèle, pas du seeder.
        if (! $exercice->en_cours) {
            $exercice->activer();
        }

        $agent = Agent::firstOrCreate(
            ['email' => 'admin@varbaf.local'],
            [
                'nom' => 'Administrateur',
                'prenom' => 'Système',
                'sexe' => Sexe::MASCULIN,
                'fonction' => 'Administrateur du système',
                'date_prise_service' => now()->toDateString(),
                'actif' => true,
                'village_id' => $village->id,
            ],
        );

        $utilisateur = Utilisateur::firstOrCreate(
            ['email' => 'admin@varbaf.local'],
            [
                'name' => 'Administrateur VARBAF',
                // Mot de passe d'amorçage : à changer à la première
                // connexion. Il n'ouvre que l'installation locale.
                'password' => 'varbaf2026',
                'actif' => true,
                'agent_id' => $agent->id,
            ],
        );

        $utilisateur->syncRoles([Utilisateur::ROLE_SUPER_UTILISATEUR]);

        $this->command?->info("Village « {$village->nom} » et exercice {$exercice->libelle} en place.");
        $this->command?->info('Compte super-utilisateur : admin@varbaf.local / varbaf2026');
    }
}
