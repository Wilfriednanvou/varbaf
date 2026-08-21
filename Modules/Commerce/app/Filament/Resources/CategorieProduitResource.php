<?php

namespace Modules\Commerce\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Commerce\Filament\Resources\CategorieProduitResource\Pages;
use Modules\Commerce\Models\CategorieProduit;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Models\JournalAudit;

class CategorieProduitResource extends Resource
{
    protected static ?string $model = CategorieProduit::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-tag';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::COMMERCE;

    protected static ?string $navigationLabel = 'Catégories de produits';

    protected static ?string $modelLabel = 'Catégorie de produit';

    protected static ?string $pluralModelLabel = 'Catégories de produits';

    protected static ?string $slug = 'categories-produits';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'libelle';

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_categories_produits');
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->schema([
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('Code')
                        ->placeholder('BRO')
                        ->required()
                        ->maxLength(10)
                        ->unique(ignoreRecord: true),
                    Forms\Components\TextInput::make('libelle')
                        ->label('Libellé')
                        ->placeholder('Bronze')
                        ->required()
                        ->maxLength(100)
                        ->unique(ignoreRecord: true),
                ]),
                // La catégorie en cours d'édition est exclue de la
                // liste : se choisir soi-même comme parent formerait
                // une boucle, que le modèle refuse de toute façon.
                Forms\Components\Select::make('categorie_parent_id')
                    ->label('Catégorie parente')
                    ->relationship(
                        name: 'parent',
                        titleAttribute: 'libelle',
                        modifyQueryUsing: fn (Builder $query, ?Model $record) => $query
                            ->when($record, fn (Builder $q) => $q->whereKeyNot($record->getKey())),
                    )
                    ->searchable()
                    ->preload()
                    ->placeholder('Aucune — catégorie de premier niveau'),
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
                Tables\Columns\TextColumn::make('libelle')
                    ->label('Libellé')
                    ->description(fn (CategorieProduit $record) => $record->parent?->libelle)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('parent.libelle')
                    ->label('Catégorie parente')
                    ->placeholder('Premier niveau')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('enfants_count')
                    ->label('Sous-catégories')
                    ->counts('enfants')
                    ->sortable(),
                Tables\Columns\TextColumn::make('produits_count')
                    ->label('Produits')
                    ->counts('produits')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créée le')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('categorie_parent_id')
                    ->label('Catégorie parente')
                    ->relationship('parent', 'libelle')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('libelle')
            ->recordActions([
                Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Modifier')
                    ->visible(fn () => auth()->user()->can('modifier_categorie_produit'))
                    ->modalHeading('Modifier la catégorie')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->after(fn (CategorieProduit $record) => JournalAudit::enregistrer(
                        'Modification catégorie de produit',
                        'COMMERCE',
                        'CategorieProduit',
                        $record->id,
                        ['code' => $record->code, 'libelle' => $record->libelle],
                    )),
                Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Supprimer')
                    ->visible(fn () => auth()->user()->can('supprimer_categorie_produit'))
                    ->modalHeading('Supprimer la catégorie')
                    ->modalDescription('La suppression sera refusée si la catégorie porte des produits ou des sous-catégories.')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->before(fn (CategorieProduit $record) => JournalAudit::enregistrer(
                        'Suppression catégorie de produit',
                        'COMMERCE',
                        'CategorieProduit',
                        $record->id,
                        ['code' => $record->code, 'libelle' => $record->libelle],
                    )),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Aucune catégorie enregistrée');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageCategoriesProduits::route('/'),
        ];
    }
}
