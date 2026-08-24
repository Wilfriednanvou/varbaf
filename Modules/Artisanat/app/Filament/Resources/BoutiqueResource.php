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
use Modules\Artisanat\Enums\ZoneBoutique;
use Modules\Artisanat\Filament\Resources\BoutiqueResource\Pages;
use Modules\Artisanat\Models\Boutique;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Models\JournalAudit;

/**
 * Le parc de locaux, et rien d'autre.
 *
 * L'occupant, l'état d'occupation et la redevance ont quitté cet écran
 * en même temps qu'ils ont quitté le modèle : ils appartiennent à
 * l'espace locatif. Ce qui se saisit ici est ce qui décrit le bâtiment.
 */
class BoutiqueResource extends Resource
{
    protected static ?string $model = Boutique::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-building-storefront';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::ARTISANAT;

    protected static ?string $navigationLabel = 'Boutiques';

    protected static ?string $modelLabel = 'Boutique';

    protected static ?string $pluralModelLabel = 'Boutiques';

    protected static ?string $slug = 'boutiques';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'numero';

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_boutiques');
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->schema([
                Grid::make(2)->schema([
                    // L'unicité est portée par le couple (village,
                    // numéro) en base : la règle de formulaire est
                    // restreinte de la même façon, sinon deux villages ne
                    // pourraient pas avoir chacun une boutique B01.
                    Forms\Components\TextInput::make('numero')
                        ->label('Numéro')
                        ->placeholder('B01')
                        ->required()
                        ->maxLength(20)
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $regle, Get $get) => $regle->where('village_id', $get('village_id')),
                        ),
                    Forms\Components\Select::make('village_id')
                        ->label('Village')
                        ->relationship('village', 'nom')
                        ->searchable()
                        ->preload()
                        ->required(),
                ]),
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('superficie')
                        ->label('Superficie (m²)')
                        ->placeholder('12')
                        ->numeric()
                        ->minValue(0),
                    Forms\Components\Select::make('emplacement')
                        ->label('Emplacement')
                        ->options(ZoneBoutique::options())
                        ->placeholder('Sélectionnez une zone')
                        ->native(false),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('numero')
                    ->label('Numéro')
                    ->searchable()
                    ->sortable()
                    ->badge(),
                Tables\Columns\TextColumn::make('emplacement')
                    ->label('Emplacement')
                    ->formatStateUsing(fn (?string $state) => $state ? ZoneBoutique::from($state)->getLabel() : null)
                    ->badge()
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('superficie')
                    ->label('Superficie')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' m²')
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('nombre_espaces')
                    ->label('Espaces')
                    ->state(fn (Boutique $record) => $record->espacesLocatifs()->count())
                    ->badge(),
                Tables\Columns\TextColumn::make('occupants')
                    ->label('Occupants')
                    ->state(fn (Boutique $record) => collect($record->occupantsActuels())
                        ->map(fn ($artisan) => $artisan->nom_complet)
                        ->implode(', '))
                    ->wrap()
                    ->placeholder('Aucun'),
                Tables\Columns\TextColumn::make('village.nom')
                    ->label('Village')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créée le')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('village_id')
                    ->label('Village')
                    ->relationship('village', 'nom'),
            ])
            ->defaultSort('numero')
            ->recordActions([
                Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Modifier')
                    ->visible(fn () => auth()->user()->can('modifier_boutique'))
                    ->modalHeading('Modifier la boutique')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->after(fn (Boutique $record) => JournalAudit::enregistrer(
                        'Modification boutique',
                        'ARTISANAT',
                        'Boutique',
                        $record->id,
                        ['numero' => $record->numero],
                    )),
                Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Supprimer')
                    ->visible(fn () => auth()->user()->can('supprimer_boutique'))
                    ->modalHeading('Supprimer la boutique')
                    ->modalDescription('La suppression sera refusée si la boutique abrite des espaces locatifs. Retirez d\'abord ses espaces du parc.')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->before(fn (Boutique $record) => JournalAudit::enregistrer(
                        'Suppression boutique',
                        'ARTISANAT',
                        'Boutique',
                        $record->id,
                        ['numero' => $record->numero],
                    )),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Aucune boutique enregistrée');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageBoutiques::route('/'),
        ];
    }
}
