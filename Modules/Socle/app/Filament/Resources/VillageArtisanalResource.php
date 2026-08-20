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
use Modules\Socle\Enums\CategorieVillage;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Filament\Resources\VillageArtisanalResource\Pages;
use Modules\Socle\Models\JournalAudit;
use Modules\Socle\Models\VillageArtisanal;

class VillageArtisanalResource extends Resource
{
    protected static ?string $model = VillageArtisanal::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-building-office-2';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::SOCLE;

    protected static ?string $navigationLabel = 'Villages artisanaux';

    protected static ?string $modelLabel = 'Village artisanal';

    protected static ?string $pluralModelLabel = 'Villages artisanaux';

    protected static ?string $slug = 'villages-artisanaux';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'nom';

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_villages');
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->schema([
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('Code')
                        ->placeholder('VARBAF')
                        ->required()
                        ->maxLength(20)
                        ->unique(ignoreRecord: true),
                    Forms\Components\TextInput::make('nom')
                        ->label('Nom')
                        ->placeholder('Village Artisanal Régional de Bafoussam')
                        ->required()
                        ->maxLength(255),
                ]),
                Grid::make(2)->schema([
                    Forms\Components\Select::make('categorie')
                        ->label('Catégorie')
                        ->options(CategorieVillage::options())
                        ->default(CategorieVillage::REGIONAL->value)
                        ->required(),
                    Forms\Components\TextInput::make('region')
                        ->label('Région')
                        ->placeholder('Ouest')
                        ->required()
                        ->maxLength(100),
                ]),
                Forms\Components\TextInput::make('adresse')
                    ->label('Adresse')
                    ->placeholder('Quartier Djeleng, Bafoussam')
                    ->maxLength(255),
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('telephone')
                        ->label('Téléphone')
                        ->tel()
                        ->placeholder('6XX XX XX XX')
                        ->maxLength(30),
                    Forms\Components\TextInput::make('email')
                        ->label('Adresse électronique')
                        ->email()
                        ->placeholder('contact@varbaf.cm')
                        ->maxLength(255),
                ]),
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('nombre_boutiques')
                        ->label('Nombre de boutiques')
                        ->placeholder('24')
                        ->numeric()
                        ->minValue(0)
                        ->default(0),
                    Forms\Components\TextInput::make('superficie')
                        ->label('Superficie (m²)')
                        ->placeholder('3500')
                        ->numeric()
                        ->minValue(0),
                ]),
                Forms\Components\Toggle::make('actif')
                    ->label('Village actif')
                    ->default(true)
                    ->helperText('Un village inactif n\'apparaîtra plus dans les listes de sélection des autres modules'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable()
                    ->badge(),
                Tables\Columns\TextColumn::make('nom')
                    ->label('Nom')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('categorie')
                    ->label('Catégorie')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('region')
                    ->label('Région')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('telephone')
                    ->label('Téléphone')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('nombre_boutiques')
                    ->label('Boutiques')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('actif')
                    ->label('Actif')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Modifié le')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('categorie')
                    ->label('Catégorie')
                    ->options(CategorieVillage::options()),
                Tables\Filters\TernaryFilter::make('actif')
                    ->label('Actif'),
            ])
            ->defaultSort('nom')
            ->recordActions([
                Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Modifier')
                    ->visible(fn () => auth()->user()->can('modifier_village'))
                    ->modalHeading('Modifier le village')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->after(fn (VillageArtisanal $record) => JournalAudit::enregistrer(
                        'Modification village',
                        'SOCLE',
                        'VillageArtisanal',
                        $record->id,
                        ['code' => $record->code, 'nom' => $record->nom],
                    )),
                Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Supprimer')
                    ->visible(fn () => auth()->user()->can('supprimer_village'))
                    ->modalHeading('Supprimer le village')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->before(fn (VillageArtisanal $record) => JournalAudit::enregistrer(
                        'Suppression village',
                        'SOCLE',
                        'VillageArtisanal',
                        $record->id,
                        ['code' => $record->code, 'nom' => $record->nom],
                    )),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Aucun village enregistré')
            ->emptyStateDescription('Créez le village artisanal avant de saisir les exercices et les agents.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageVillagesArtisanaux::route('/'),
        ];
    }
}
