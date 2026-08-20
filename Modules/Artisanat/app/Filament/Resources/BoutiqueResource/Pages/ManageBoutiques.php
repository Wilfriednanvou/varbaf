<?php

namespace Modules\Artisanat\Filament\Resources\BoutiqueResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Artisanat\Filament\Resources\BoutiqueResource;
use Modules\Artisanat\Models\Boutique;
use Modules\Socle\Models\JournalAudit;

class ManageBoutiques extends ManageRecords
{
    protected static string $resource = BoutiqueResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Boutiques',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn () => auth()->user()->can('ajouter_boutique'))
                ->modalHeading('Nouvelle boutique')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->after(fn (Boutique $record) => JournalAudit::enregistrer(
                    'Création boutique',
                    'ARTISANAT',
                    'Boutique',
                    $record->id,
                    ['numero' => $record->numero],
                )),
        ];
    }
}
