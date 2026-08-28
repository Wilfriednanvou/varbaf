<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Modules\Artisanat\Filament\ArtisanatPlugin;
use Modules\Commerce\Filament\CommercePlugin;
use Modules\Pilotage\Filament\PilotagePlugin;
use Modules\Portail\Filament\PortailPlugin;
use Modules\Socle\Filament\SoclePlugin;
use Modules\Tresorerie\Filament\TresoreriePlugin;

/**
 * Panneau d'administration de l'ERP du Village Artisanal Régional de Bafoussam.
 *
 * Les ressources des modules métier sont apportées par un greffon propre
 * à chaque module, déclaré dans ->plugins(). Ce panneau ne découvre
 * lui-même que les composants du dossier app/Filament : un module reste
 * ainsi retirable en supprimant une seule ligne.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('VARBAF')
            ->colors([
                'primary' => Color::Amber,
            ])
            // **Le thème du panneau, sans quoi les vues des modules
            // s'affichent sans mise en forme.** Filament sert par défaut
            // une feuille précompilée qui ne contient que les classes
            // qu'il emploie lui-même : un utilitaire écrit dans une vue
            // de module n'y figure pas, et la page s'affiche nue sans
            // qu'aucune erreur ne le signale — c'est une panne muette,
            // pas une erreur. Le fichier désigné ici déclare les modules
            // parmi ses sources.
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            // La cloche de notifications. Sans cette ligne, l'alerte de
            // rupture de la règle 15 s'écrirait en base sans que
            // personne ne puisse la lire : c'est ici que le canal
            // « database » devient un écran.
            ->databaseNotifications()
            // Un greffon par module, dans l'ordre de dépendance du
            // tableau de CLAUDE.md. C'est la seule ligne à ajouter ici
            // lorsqu'un module arrive : le module apporte lui-même ses
            // ressources et ses pages.
            ->plugins([
                SoclePlugin::make(),
                ArtisanatPlugin::make(),
                CommercePlugin::make(),
                TresoreriePlugin::make(),
                PilotagePlugin::make(),
                PortailPlugin::make(),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
