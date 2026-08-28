<?php

namespace Modules\Portail\Filament\Resources\ArtisanVedetteResource\Pages;

use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Socle\Filament\Concerns\TitreLisible;
use Modules\Socle\Models\JournalAudit;
use Modules\Portail\Filament\Resources\ArtisanVedetteResource;
use Modules\Portail\Models\ArtisanVedette;

class ManageArtisansVedettes extends ManageRecords
{
    // Filament capitalise chaque mot du libelle pluriel pour en
    // faire le titre : « Corps De Metier » la ou le menu et le fil
    // d'Ariane disent « Corps de metier ». Voir le trait.
    use TitreLisible;

    protected static string $resource = ArtisanVedetteResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Artisans vedettes',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Mettre un artisan en avant')
                ->visible(fn () => auth()->user()->can('ajouter_artisan_vedette'))
                ->modalHeading('Mettre un artisan en avant')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->using(function (array $data): ?ArtisanVedette {
                    try {
                        return ArtisanVedette::create($data);
                    } catch (\RuntimeException $e) {
                        Notification::make()
                            ->title('Mise en avant impossible')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return null;
                    }
                })
                ->after(fn ($record) => $record
                    ? JournalAudit::enregistrer(
                        'Création artisan vedette',
                        'PORTAIL',
                        'ArtisanVedette',
                        $record->id,
                        ['artisan_id' => $record->artisan_id],
                    )
                    : null),
        ];
    }
}
