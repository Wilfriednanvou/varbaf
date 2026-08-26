<?php

/*
|--------------------------------------------------------------------------
| Routes applicatives
|--------------------------------------------------------------------------
|
| La racine du site est servie par le module Portail, dont les routes
| publiques sont chargées par `PortailServiceProvider`
| (`Modules/Portail/routes/web.php`). Déclarer ici une route `/` la
| mettrait en concurrence avec celle du portail, et laquelle l'emporte
| dépendrait de l'ordre d'enregistrement des fournisseurs de services —
| une fragilité qu'il vaut mieux ne pas installer.
|
| Le panneau d'administration, lui, a ses propres routes, posées par
| Filament sous le préfixe `/admin`.
|
*/

use Modules\Commerce\Models\Vente;
use Barryvdh\DomPDF\Facade\Pdf;
use Modules\Socle\Models\JournalAudit;

Route::get('/ventes/{vente}/recu', function (Vente $vente) {
    abort_unless(auth()->user()?->can('imprimer_recu_vente'), 403);
    
    $vente->loadMissing(['lignes', 'artisan', 'boutique.village', 'vendeur']);

    JournalAudit::enregistrer(
        'Édition reçu de vente (session caisse)',
        'COMMERCE',
        'Vente',
        $vente->id,
        ['numero' => $vente->numero],
    );

    return Pdf::loadView('commerce::ventes.recu', [
        'vente' => $vente,
        'village' => $vente->boutique?->village,
        'genereLe' => now()->format('d/m/Y à H:i'),
    ])->stream("recu-{$vente->numero}.pdf");
})->middleware(['web', 'auth'])->name('ventes.recu');

use Modules\Tresorerie\Models\CampagneReversement;
use Modules\Tresorerie\Models\Reversement;

Route::get('/campagne-reversements/{campagne}/etat', function (CampagneReversement $campagne) {
    abort_unless(auth()->user()?->can('imprimer_etat_reversement'), 403);

    $campagne->loadMissing([
        'exercice.village',
        'reversements.artisan',
        'genereePar',
        'valideePar',
    ]);

    JournalAudit::enregistrer(
        'Édition état de reversement',
        'TRESORERIE',
        'CampagneReversement',
        $campagne->id,
        ['periode' => $campagne->libellePeriode()],
    );

    return Pdf::loadView('tresorerie::reversements.etat', [
        'campagne' => $campagne,
        'village' => $campagne->exercice?->village,
        'genereLe' => now()->format('d/m/Y à H:i'),
    ])->stream('etat-reversement-'.$campagne->periode?->format('Y-m').'.pdf');
})->middleware(['web', 'auth'])->name('campagnes.etat');

Route::get('/campagne-reversements/{campagne}/recu/{reversement}', function (CampagneReversement $campagne, Reversement $reversement) {
    abort_unless(auth()->user()?->can('imprimer_recu_reversement'), 403);
    
    abort_unless($reversement->campagne_id === $campagne->id, 404);

    $reversement->loadMissing(['artisan', 'lignes.vente']);

    JournalAudit::enregistrer(
        'Édition reçu de reversement',
        'TRESORERIE',
        'Reversement',
        $reversement->id,
        ['periode' => $campagne->libellePeriode(), 'artisan_id' => $reversement->artisan_id],
    );

    return Pdf::loadView('tresorerie::reversements.recu', [
        'reversement' => $reversement,
        'campagne' => $campagne,
        'village' => $campagne->exercice?->village,
        'genereLe' => now()->format('d/m/Y à H:i'),
    ])->stream('recu-reversement-'.$campagne->periode?->format('Y-m').'-'.($reversement->artisan?->matricule ?? $reversement->artisan_id).'.pdf');
})->middleware(['web', 'auth'])->name('campagnes.recu');
