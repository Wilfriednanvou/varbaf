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
