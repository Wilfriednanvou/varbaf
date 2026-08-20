<?php

namespace Modules\Socle\Filament\Resources\RoleResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Alignment;
use Modules\Socle\Filament\Resources\RoleResource;
use Modules\Socle\Models\JournalAudit;
use Spatie\Permission\Models\Role;

class ManageRoles extends ManageRecords
{
    protected static string $resource = RoleResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Rôles',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn () => auth()->user()->can('ajouter_role'))
                ->modalHeading('Nouveau rôle')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->after(fn (Role $record) => JournalAudit::enregistrer(
                    'Création rôle',
                    'SOCLE',
                    'Role',
                    $record->id,
                    ['nom' => $record->name],
                )),
        ];
    }
}
