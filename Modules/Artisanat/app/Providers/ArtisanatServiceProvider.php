<?php

namespace Modules\Artisanat\Providers;

use Modules\Artisanat\Console\BootstrapArtisanExercicesCommand;
use Modules\Artisanat\Services\ReconducteurArtisans;
use Modules\Socle\Services\RegistreDeReconduction;
use Nwidart\Modules\Support\ModuleServiceProvider;

class ArtisanatServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Artisanat';

    protected string $nameLower = 'artisanat';

    public function boot(): void
    {
        parent::boot();

        // Les commandes d'un module ne sont pas decouvertes
        // automatiquement, meme principe que le Pilotage.
        if ($this->app->runningInConsole()) {
            $this->commands([
                BootstrapArtisanExercicesCommand::class,
            ]);
        }

        // Depose ici, dans boot(), pour arriver apres que le register()
        // du Socle a deja lie le registre en singleton.
        $this->app->make(RegistreDeReconduction::class)
            ->ajouter('artisans', $this->app->make(ReconducteurArtisans::class));
    }
}
