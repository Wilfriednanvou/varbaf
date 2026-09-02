<?php

use Illuminate\Support\Facades\Route;
use Modules\Portail\Http\Controllers\AccueilController;
use Modules\Portail\Http\Controllers\ArtisanController;
use Modules\Portail\Http\Controllers\CatalogueController;
use Modules\Portail\Http\Controllers\ContactController;

/*
|--------------------------------------------------------------------------
| Routes publiques du portail
|--------------------------------------------------------------------------
|
| Chargées par `PortailServiceProvider` sur le seul groupe `web` — pour la
| session et la protection CSRF du formulaire de contact — et préfixées
| `portail.` en nom. Aucun middleware d'authentification : un visiteur du
| portail n'a pas de compte et n'en aura pas.
|
| Huit routes en lecture, une seule en écriture : l'envoi du formulaire de
| contact. Le portail ne vend pas, ne commande pas, n'encaisse pas.
|
| Les identifiants exposés sont la référence du produit et le matricule
| de l'artisan, jamais les clés techniques : ces deux valeurs sont déjà
| publiques — la référence est imprimée sur l'étiquette et sur le reçu de
| vente (RG-09) — et elles ne laissent rien deviner du volume de la base.
|
*/

Route::get('/', [AccueilController::class, 'index'])->name('accueil');

Route::get('/le-village', [AccueilController::class, 'village'])->name('village');
Route::get('/les-boutiques', [AccueilController::class, 'boutiques'])->name('boutiques');

Route::get('/catalogue', [CatalogueController::class, 'index'])->name('catalogue');
Route::get('/catalogue/{reference}', [CatalogueController::class, 'show'])->name('produit');

Route::get('/artisans', [ArtisanController::class, 'index'])->name('artisans');
Route::get('/artisans/{matricule}', [ArtisanController::class, 'show'])->name('artisan');

Route::get('/contact', [ContactController::class, 'create'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])->name('contact.envoi');
