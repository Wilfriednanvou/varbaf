<?php

namespace Modules\Socle\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Filament\Resources\RoleResource\Pages;
use Modules\Socle\Models\JournalAudit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Gestion des profils d'habilitation.
 *
 * Les permissions sont présentées groupées par module : c'est la
 * raison d'être des colonnes « module » et « description » ajoutées à
 * la table des permissions. Une liste à plat de plus de cent entrées
 * serait inexploitable par la coordination du village.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-shield-check';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::SECURITE;

    protected static ?string $navigationLabel = 'Rôles';

    protected static ?string $modelLabel = 'Rôle';

    protected static ?string $pluralModelLabel = 'Rôles';

    protected static ?string $slug = 'roles';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_roles');
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->schema([
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nom du rôle')
                        ->placeholder('caissier')
                        ->required()
                        ->maxLength(125)
                        ->unique(ignoreRecord: true),
                    Forms\Components\TextInput::make('description')
                        ->label('Description')
                        ->placeholder('Tient la caisse et arrête la journée')
                        ->maxLength(255),
                ]),
                Forms\Components\CheckboxList::make('permissions')
                    ->label('Permissions')
                    ->relationship('permissions', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Permission $record) => $record->description ?: $record->name)
                    ->searchable()
                    ->bulkToggleable()
                    ->columns(2)
                    ->gridDirection('row'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Rôle')
                    ->searchable()
                    ->sortable()
                    ->badge(),
                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->searchable()
                    ->wrap()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('permissions_count')
                    ->label('Permissions')
                    ->counts('permissions')
                    ->sortable(),
                Tables\Columns\TextColumn::make('users_count')
                    ->label('Comptes')
                    ->counts('users')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->recordActions([
                Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Modifier')
                    ->visible(fn () => auth()->user()->can('modifier_role'))
                    ->modalHeading('Modifier le rôle')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->after(fn (Role $record) => JournalAudit::enregistrer(
                        'Modification rôle',
                        'SOCLE',
                        'Role',
                        $record->id,
                        ['nom' => $record->name, 'permissions' => $record->permissions->pluck('name')->all()],
                    )),
                Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Supprimer')
                    ->visible(fn (Role $record) => auth()->user()->can('supprimer_role')
                        && $record->name !== \Modules\Socle\Models\Utilisateur::ROLE_SUPER_UTILISATEUR)
                    ->modalHeading('Supprimer le rôle')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->before(fn (Role $record) => JournalAudit::enregistrer(
                        'Suppression rôle',
                        'SOCLE',
                        'Role',
                        $record->id,
                        ['nom' => $record->name],
                    )),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Aucun rôle enregistré');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageRoles::route('/'),
        ];
    }
}
