<?php

namespace Modules\Artisanat\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Artisanat\Filament\Resources\CorpsMetierResource\Pages;
use Modules\Artisanat\Models\CorpsMetier;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Models\JournalAudit;

class CorpsMetierResource extends Resource
{
    protected static ?string $model = CorpsMetier::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-swatch';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::ARTISANAT;

    protected static ?string $navigationLabel = 'Corps de métier';

    protected static ?string $modelLabel = 'Corps de métier';

    protected static ?string $pluralModelLabel = 'Corps de métier';

    protected static ?string $slug = 'corps-metiers';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'libelle';

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_corps_metiers');
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->schema([
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('Code')
                        ->placeholder('VAN')
                        ->required()
                        ->maxLength(10)
                        ->unique(ignoreRecord: true),
                    Forms\Components\TextInput::make('libelle')
                        ->label('Libellé')
                        ->placeholder('Vannerie')
                        ->required()
                        ->maxLength(100)
                        ->unique(ignoreRecord: true),
                ]),
                Forms\Components\Textarea::make('description')
                    ->label('Description')
                    ->placeholder('Tressage de fibres végétales : paniers, nattes, corbeilles')
                    ->rows(3),
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
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('artisans_count')
                    ->label('Artisans')
                    ->counts('artisans')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('libelle')
            ->recordActions([
                Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Modifier')
                    ->visible(fn () => auth()->user()->can('modifier_corps_metier'))
                    ->modalHeading('Modifier le corps de métier')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->after(fn (CorpsMetier $record) => JournalAudit::enregistrer(
                        'Modification corps de métier',
                        'ARTISANAT',
                        'CorpsMetier',
                        $record->id,
                        ['code' => $record->code, 'libelle' => $record->libelle],
                    )),
                Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Supprimer')
                    ->visible(fn () => auth()->user()->can('supprimer_corps_metier'))
                    ->modalHeading('Supprimer le corps de métier')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->before(fn (CorpsMetier $record) => JournalAudit::enregistrer(
                        'Suppression corps de métier',
                        'ARTISANAT',
                        'CorpsMetier',
                        $record->id,
                        ['code' => $record->code, 'libelle' => $record->libelle],
                    )),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Aucun corps de métier enregistré');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageCorpsMetiers::route('/'),
        ];
    }
}
