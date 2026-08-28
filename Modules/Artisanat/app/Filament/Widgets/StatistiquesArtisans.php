<?php

namespace Modules\Artisanat\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Artisanat\Services\StatistiquesArtisanat;

/**
 * Les quatre chiffres qu'on veut voir en ouvrant l'écran des artisans.
 *
 * **Ce qu'un tableau ne dit pas.** La liste des artisans répond à « qui
 * est enregistré ? ». Elle ne répond ni à « combien sont encore actifs »,
 * ni à « combien acceptent d'être publiés », ni surtout à « combien
 * vendent sans être installés » — trois questions que la coordination se
 * pose et auxquelles elle répondait jusqu'ici en faisant défiler la
 * liste.
 *
 * Le widget n'interroge jamais la base : il demande à
 * `StatistiquesArtisanat`, qui seul sait ce qu'« actif » et « sans
 * espace » veulent dire dans ce système.
 */
class StatistiquesArtisans extends StatsOverviewWidget
{
    protected int | string | array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 4;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $stats = app(StatistiquesArtisanat::class);

        $total = $stats->nombreArtisans();
        $actifs = $stats->nombreArtisansActifs();
        $publiables = $stats->nombreArtisansPubliables();
        $sansEspace = $stats->nombreArtisansSansEspace();

        return [
            Stat::make('Artisans enregistrés', (string) $total)
                ->description('Toutes situations confondues')
                ->descriptionIcon('heroicon-m-users')
                ->color('gray'),

            Stat::make('Artisans actifs', (string) $actifs)
                ->description($total > 0
                    ? $this->part($actifs, $total).' du registre'
                    : 'Aucun artisan enregistré')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success'),

            Stat::make('Publiables sur le portail', (string) $publiables)
                ->description('Actifs ayant autorisé la publication')
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color('primary'),

            // Ni vert ni rouge : ce n'est pas une anomalie, c'est une
            // situation prévue par le modèle — le déposant non installé.
            // La colorer en danger ferait passer pour une erreur ce qui
            // est une question en attente de la coordination.
            Stat::make('Actifs sans espace attribué', (string) $sansEspace)
                ->description($sansEspace > 0
                    ? 'Déposants non installés au parc'
                    : 'Tous les artisans actifs occupent un espace')
                ->descriptionIcon('heroicon-m-question-mark-circle')
                ->color($sansEspace > 0 ? 'warning' : 'success'),
        ];
    }

    protected function part(int $nombre, int $total): string
    {
        return $total > 0 ? round($nombre / $total * 100).' %' : '—';
    }
}
