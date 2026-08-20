<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Socle\Exceptions\JournalAuditImmuableException;
use Modules\Socle\Models\JournalAudit;
use Tests\TestCase;

/**
 * Vérifie que le journal d'audit est bien en écriture seule au niveau
 * du modèle, et non seulement au niveau de la ressource Filament.
 *
 * Les tests attaquent directement le modèle : c'est le chemin qu'emprunte
 * un seeder, une commande artisan ou tinker, et c'est celui que la
 * ressource ne protégeait pas.
 */
class JournalAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function ecrire(): JournalAudit
    {
        $ecriture = JournalAudit::enregistrer(
            'Création artisan',
            'ARTISANAT',
            'Artisan',
            1,
            ['matricule' => 'ART-2026-0001'],
        );

        $this->assertNotNull($ecriture, 'L\'écriture d\'audit aurait dû être créée.');

        return $ecriture;
    }

    public function test_une_ecriture_peut_etre_creee(): void
    {
        $ecriture = $this->ecrire();

        $this->assertSame('Création artisan', $ecriture->action);
        $this->assertSame('ARTISANAT', $ecriture->module);
        $this->assertDatabaseCount('journaux_audit', 1);
    }

    public function test_une_ecriture_ne_peut_pas_etre_modifiee(): void
    {
        $ecriture = $this->ecrire();

        $this->expectException(JournalAuditImmuableException::class);

        $ecriture->update(['action' => 'Action falsifiée']);
    }

    public function test_une_ecriture_ne_peut_pas_etre_supprimee(): void
    {
        $ecriture = $this->ecrire();

        $this->expectException(JournalAuditImmuableException::class);

        $ecriture->delete();
    }

    public function test_la_tentative_de_suppression_laisse_l_ecriture_en_place(): void
    {
        $ecriture = $this->ecrire();

        try {
            $ecriture->delete();
        } catch (JournalAuditImmuableException) {
            // Attendu : on vérifie ci-dessous que rien n'a bougé.
        }

        $this->assertDatabaseCount('journaux_audit', 1);
        $this->assertSame('Création artisan', $ecriture->fresh()->action);
    }
}
