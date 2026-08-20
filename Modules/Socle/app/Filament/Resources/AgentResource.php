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
use Modules\Socle\Enums\Sexe;
use Modules\Socle\Filament\Resources\AgentResource\Pages;
use Modules\Socle\Models\Agent;
use Modules\Socle\Models\JournalAudit;

class AgentResource extends Resource
{
    protected static ?string $model = Agent::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-identification';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::SOCLE;

    protected static ?string $navigationLabel = 'Agents';

    protected static ?string $modelLabel = 'Agent';

    protected static ?string $pluralModelLabel = 'Agents';

    protected static ?string $slug = 'agents';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'nom';

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_agents');
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->schema([
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('nom')
                        ->label('Nom')
                        ->placeholder('Nom de famille')
                        ->required()
                        ->maxLength(100),
                    Forms\Components\TextInput::make('prenom')
                        ->label('Prénom')
                        ->placeholder('Prénom')
                        ->maxLength(100),
                ]),
                Grid::make(2)->schema([
                    Forms\Components\Select::make('sexe')
                        ->label('Sexe')
                        ->options(Sexe::options())
                        ->native(false),
                    Forms\Components\TextInput::make('fonction')
                        ->label('Fonction')
                        ->placeholder('Caissier, coordonnateur, agent commercial')
                        ->maxLength(100),
                ]),
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('telephone')
                        ->label('Téléphone')
                        ->tel()
                        ->placeholder('6XX XX XX XX')
                        ->maxLength(30),
                    Forms\Components\TextInput::make('email')
                        ->label('Adresse électronique')
                        ->email()
                        ->placeholder('prenom.nom@varbaf.cm')
                        ->maxLength(255),
                ]),
                Grid::make(2)->schema([
                    Forms\Components\DatePicker::make('date_prise_service')
                        ->label('Date de prise de service')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->maxDate(now()),
                    Forms\Components\Select::make('village_id')
                        ->label('Village')
                        ->relationship('village', 'nom')
                        ->searchable()
                        ->preload()
                        ->required(),
                ]),
                Forms\Components\Toggle::make('actif')
                    ->label('Agent actif')
                    ->default(true)
                    ->helperText('Un agent inactif n\'apparaîtra pas dans les listes de sélection des vendeurs et des caissiers'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('matricule')
                    ->label('Matricule')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->copyable(),
                Tables\Columns\TextColumn::make('nom')
                    ->label('Nom')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('prenom')
                    ->label('Prénom')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sexe')
                    ->label('Sexe')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('fonction')
                    ->label('Fonction')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('telephone')
                    ->label('Téléphone')
                    ->searchable(),
                Tables\Columns\TextColumn::make('village.nom')
                    ->label('Village')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('date_prise_service')
                    ->label('Prise de service')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                Tables\Filters\SelectFilter::make('village_id')
                    ->label('Village')
                    ->relationship('village', 'nom'),
                Tables\Filters\SelectFilter::make('sexe')
                    ->label('Sexe')
                    ->options(Sexe::options()),
                Tables\Filters\TernaryFilter::make('actif')
                    ->label('Actif'),
            ])
            ->defaultSort('matricule')
            ->recordActions([
                Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Modifier')
                    ->visible(fn () => auth()->user()->can('modifier_agent'))
                    ->modalHeading('Modifier l\'agent')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->after(fn (Agent $record) => JournalAudit::enregistrer(
                        'Modification agent',
                        'SOCLE',
                        'Agent',
                        $record->id,
                        ['matricule' => $record->matricule, 'nom' => $record->nom_complet],
                    )),
                Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Supprimer')
                    ->visible(fn () => auth()->user()->can('supprimer_agent'))
                    ->modalHeading('Supprimer l\'agent')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->before(fn (Agent $record) => JournalAudit::enregistrer(
                        'Suppression agent',
                        'SOCLE',
                        'Agent',
                        $record->id,
                        ['matricule' => $record->matricule, 'nom' => $record->nom_complet],
                    )),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Aucun agent enregistré');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageAgents::route('/'),
        ];
    }
}
