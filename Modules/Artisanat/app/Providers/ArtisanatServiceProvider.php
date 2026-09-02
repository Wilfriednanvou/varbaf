<?php

namespace Modules\Artisanat\Providers;

use Modules\Artisanat\Console\BootstrapArtisanExercicesCommand;
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
    }
}
