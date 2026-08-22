<?php

namespace Modules\Tresorerie\Filament\Resources;

use Filament\Actions;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Models\JournalAudit;
use Modules\Tresorerie\Filament\Resources\LibelleMouvementResource\Pages;
use Modules\Tresorerie\Models\LibelleMouvement;

class LibelleMouvementResource extends Resource
{
    protected static ?string $model = LibelleMouvement::class;
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-tag';
    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::TRESORERIE;
    protected static ?string $navigationLabel = 'Libellés de mouvement';
    protected static ?string $modelLabel = 'Libellé de mouvement';
    protected static ?string $pluralModelLabel = 'Libellés de mouvement';
    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_libelles_mouvement');
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->schema([
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('Code')
                        ->placeholder('VENTE')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(30),
                    Forms\Components\TextInput::make('libelle')
                        ->label('Libellé')
                        ->placeholder('Vente de produits artisanaux')
                        ->required(),
                ]),
                Grid::make(2)->schema([
                    Forms\Components\Select::make('sens')
                        ->label('Sens par défaut')
                        ->options([
                            'ENTREE' => 'Entrée',
                            'SORTIE' => 'Sortie',
                            'MIXTE' => 'Mixte',
                        ])
                        ->default('MIXTE')
                        ->required(),
                    Forms\Components\Toggle::make('actif')
                        ->label('Actif')
                        ->default(true)
                        ->helperText('Un libellé inactif n\'apparaîtra pas dans les listes de saisie'),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('libelle')
                    ->label('Libellé')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sens')
                    ->label('Sens')
                    ->badge()
                    ->sortable(),
                Tables\Columns\IconColumn::make('actif')
                    ->label('Actif')
                    ->boolean(),
            ])
            ->recordActions([
                Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Modifier')
                    ->visible(fn () => auth()->user()->can('modifier_libelle_mouvement'))
                    ->modalHeading('Modifier le libellé')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->after(function ($record) {
                        JournalAudit::enregistrer(
                            'Modification libellé mouvement',
                            'TRESORERIE',
                            'LibelleMouvement',
                            $record->id,
                            ['code' => $record->code]
                        );
                    }),
                Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Supprimer')
                    ->visible(fn () => auth()->user()->can('supprimer_libelle_mouvement'))
                    ->before(function ($record) {
                        JournalAudit::enregistrer(
                            'Suppression libellé mouvement',
                            'TRESORERIE',
                            'LibelleMouvement',
                            $record->id,
                            ['code' => $record->code, 'libelle' => $record->libelle]
                        );
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageLibellesMouvement::route('/'),
        ];
    }
}
