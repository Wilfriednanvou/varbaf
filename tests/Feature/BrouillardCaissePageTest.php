<?php

namespace Tests\Feature;

use Tests\TestCase;
use Modules\Socle\Models\User;
use Modules\Tresorerie\Models\Caisse;
use Modules\Tresorerie\Models\SectionCaisse;
use Modules\Socle\Models\VillageArtisanal;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Role;
use Spatie\Permission\Models\Permission;

class BrouillardCaissePageTest extends TestCase
{
    public function test_page_loads()
    {
        $village = VillageArtisanal::firstOrCreate(['code' => 'TEST', 'nom' => 'Test']);
        $exercice = Exercice::factory()->create(['est_cloture' => false, 'date_debut' => '2026-01-01', 'date_fin' => '2026-12-31']);
        
        $caisse = Caisse::create([
            'code' => 'CAI-TEST',
            'libelle' => 'Caisse Test',
            'village_id' => $village->id,
            'compte_comptable' => '531100',
        ]);
        
        $section = SectionCaisse::create([
            'caisse_id' => $caisse->id,
            'exercice_id' => $exercice->id,
            'caissier_id' => 1,
            'village_id' => $village->id,
            'date_ouverture' => now(),
            'etat' => 'OUVERTE',
        ]);
        
        $user = User::factory()->create();
        $role = Role::create(['name' => 'test-role', 'module' => 'TRESORERIE']);
        $permission = Permission::firstOrCreate(['name' => 'lister_mouvements_caisse', 'module' => 'TRESORERIE', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        
        $response = $this->actingAs($user)->get("/admin/caisses/{$caisse->id}/brouillard/{$section->id}");
        
        $response->assertStatus(200);
    }
}
