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
use Modules\Socle\Filament\Resources\UtilisateurResource\Pages;
use Modules\Socle\Models\JournalAudit;
use Modules\Socle\Models\Utilisateur;

class UtilisateurResource extends Resource
{
    protected static ?string $model = Utilisateur::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-users';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::SECURITE;

    protected static ?string $navigationLabel = 'Utilisateurs';

    protected static ?string $modelLabel = 'Utilisateur';

    protected static ?string $pluralModelLabel = 'Utilisateurs';

    protected static ?string $slug = 'utilisateurs';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_utilisateurs');
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->schema([
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nom d\'affichage')
                        ->placeholder('Nom affiché dans le panneau')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('email')
                        ->label('Adresse électronique')
                        ->email()
                        ->placeholder('prenom.nom@varbaf.cm')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),
                ]),
                Grid::make(2)->schema([
                    // Le mot de passe est haché par le cast « hashed » du
                    // modèle : le champ ne doit donc pas hacher lui-même,
                    // sous peine de double hachage. Laissé vide en
                    // modification, il n'est pas envoyé et l'ancien
                    // mot de passe est conservé.
                    Forms\Components\TextInput::make('password')
                        ->label('Mot de passe')
                        ->password()
                        ->revealable()
                        ->placeholder('Laisser vide pour ne pas changer')
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->maxLength(255),
                    Forms\Components\Select::make('agent_id')
                        ->label('Agent rattaché')
                        ->relationship('agent', 'nom')
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->identite)
                        ->searchable(['matricule', 'nom', 'prenom'])
                        ->preload(),
                ]),
                Forms\Components\Select::make('roles')
                    ->label('Rôles')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload()
                    ->required(),
                Forms\Components\Toggle::make('actif')
                    ->label('Compte actif')
                    ->default(true)
                    ->helperText('Un compte inactif ne peut plus se connecter au panneau, mais ses traces d\'audit sont conservées'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('Adresse électronique')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('agent.matricule')
                    ->label('Agent')
                    ->badge()
                    ->searchable()
                    ->placeholder('Aucun'),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Rôles')
                    ->badge()
                    ->separator(','),
                Tables\Columns\IconColumn::make('actif')
                    ->label('Actif')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('roles')
                    ->label('Rôle')
                    ->relationship('roles', 'name'),
                Tables\Filters\TernaryFilter::make('actif')
                    ->label('Actif'),
            ])
            ->defaultSort('name')
            ->recordActions([
                Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Modifier')
                    ->visible(fn () => auth()->user()->can('modifier_utilisateur'))
                    ->modalHeading('Modifier l\'utilisateur')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->after(fn (Utilisateur $record) => JournalAudit::enregistrer(
                        'Modification utilisateur',
                        'SOCLE',
                        'Utilisateur',
                        $record->id,
                        ['email' => $record->email, 'roles' => $record->roles->pluck('name')->all()],
                    )),
                Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Supprimer')
                    ->visible(fn (Utilisateur $record) => auth()->user()->can('supprimer_utilisateur')
                        && $record->id !== auth()->id())
                    ->modalHeading('Supprimer l\'utilisateur')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->before(fn (Utilisateur $record) => JournalAudit::enregistrer(
                        'Suppression utilisateur',
                        'SOCLE',
                        'Utilisateur',
                        $record->id,
                        ['email' => $record->email],
                    )),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Aucun utilisateur enregistré');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageUtilisateurs::route('/'),
        ];
    }
}
