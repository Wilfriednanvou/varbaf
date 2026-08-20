<?php

namespace Modules\Artisanat\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use Modules\Artisanat\Enums\TypeEspace;
use Modules\Artisanat\Filament\Resources\EspaceResource\Pages;
use Modules\Artisanat\Models\Espace;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Models\JournalAudit;

class EspaceResource extends Resource
{
    protected static ?string $model = Espace::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-rectangle-group';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::ARTISANAT;

    protected static ?string $navigationLabel = 'Espaces';

    protected static ?string $modelLabel = 'Espace';

    protected static ?string $pluralModelLabel = 'Espaces';

    protected static ?string $slug = 'espaces';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'nom';

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_espaces');
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->schema([
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('nom')
                        ->label('Nom')
                        ->placeholder('Salle de réunion 1')
                        ->required()
                        ->maxLength(100)
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $regle, Get $get) => $regle->where('village_id', $get('village_id')),
                        ),
                    Forms\Components\Select::make('type')
                        ->label('Type')
                        ->options(TypeEspace::options())
                        ->native(false)
                        ->required(),
                ]),
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('capacite')
                        ->label('Capacité (personnes)')
                        ->placeholder('40')
                        ->numeric()
                        ->minValue(0),
                    Forms\Components\TextInput::make('tarif_journalier')
                        ->label('Tarif journalier (FCFA)')
                        ->placeholder('Montant du barème en vigueur')
                        ->numeric()
                        ->minValue(0),
                ]),
                Forms\Components\Select::make('village_id')
                    ->label('Village')
                    ->relationship('village', 'nom')
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\Toggle::make('disponible')
                    ->label('Espace disponible')
                    ->default(true)
                    ->helperText('Un espace indisponible reste au référentiel mais n\'est plus proposé à la mise à disposition'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nom')
                    ->label('Nom')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('capacite')
                    ->label('Capacité')
                    ->numeric()
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('tarif_journalier')
                    ->label('Tarif journalier')
                    ->money('XAF')
                    ->sortable()
                    ->placeholder('À renseigner'),
                Tables\Columns\IconColumn::make('disponible')
                    ->label('Disponible')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('village.nom')
                    ->label('Village')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Type')
                    ->options(TypeEspace::options()),
                Tables\Filters\SelectFilter::make('village_id')
                    ->label('Village')
                    ->relationship('village', 'nom'),
                Tables\Filters\TernaryFilter::make('disponible')
                    ->label('Disponible'),
            ])
            ->defaultSort('nom')
            ->recordActions([
                Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Modifier')
                    ->visible(fn () => auth()->user()->can('modifier_espace'))
                    ->modalHeading('Modifier l\'espace')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->after(fn (Espace $record) => JournalAudit::enregistrer(
                        'Modification espace',
                        'ARTISANAT',
                        'Espace',
                        $record->id,
                        ['nom' => $record->nom, 'type' => $record->type?->value],
                    )),
                Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Supprimer')
                    ->visible(fn () => auth()->user()->can('supprimer_espace'))
                    ->modalHeading('Supprimer l\'espace')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->before(fn (Espace $record) => JournalAudit::enregistrer(
                        'Suppression espace',
                        'ARTISANAT',
                        'Espace',
                        $record->id,
                        ['nom' => $record->nom],
                    )),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Aucun espace enregistré')
            ->emptyStateDescription('Salles de réunion, salles d\'apprentissage, stands et parkings du village.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageEspaces::route('/'),
        ];
    }
}
