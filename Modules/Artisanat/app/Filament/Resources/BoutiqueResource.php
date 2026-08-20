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
use Modules\Artisanat\Enums\EtatBoutique;
use Modules\Artisanat\Filament\Resources\BoutiqueResource\Pages;
use Modules\Artisanat\Models\Boutique;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Models\JournalAudit;

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
                    // L'unicité est portée par le couple
                    // (village, numéro) en base : la règle de
                    // formulaire est restreinte de la même façon,
                    // sinon deux villages ne pourraient pas avoir
                    // chacun une boutique B-01.
                    Forms\Components\TextInput::make('numero')
                        ->label('Numéro')
                        ->placeholder('B-01')
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
                    Forms\Components\TextInput::make('emplacement')
                        ->label('Emplacement')
                        ->placeholder('Rez-de-chaussée')
                        ->datalist(['Sous-sol', 'Rez-de-chaussée', 'Étage'])
                        ->maxLength(60),
                ]),
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('redevance_mensuelle')
                        ->label('Redevance mensuelle de référence (FCFA)')
                        ->placeholder('Montant du barème en vigueur')
                        ->numeric()
                        ->minValue(0),
                    // L'état n'est modifiable que pour poser ou lever
                    // INDISPONIBLE : DISPONIBLE et OCCUPEE sont
                    // recalculés par les attributions, et une saisie
                    // manuelle serait écrasée au premier mouvement.
                    Forms\Components\Select::make('etat')
                        ->label('État')
                        ->options(EtatBoutique::options())
                        ->default(EtatBoutique::DISPONIBLE->value)
                        ->native(false)
                        ->required(),
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
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('superficie')
                    ->label('Superficie')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' m²')
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('redevance_mensuelle')
                    ->label('Redevance de référence')
                    ->money('XAF')
                    ->sortable()
                    ->placeholder('À renseigner'),
                Tables\Columns\TextColumn::make('etat')
                    ->label('État')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('occupant')
                    ->label('Occupant')
                    ->state(fn (Boutique $record) => $record->getOccupantActuel()?->nom_complet)
                    ->placeholder('Libre'),
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
                Tables\Filters\SelectFilter::make('etat')
                    ->label('État')
                    ->options(EtatBoutique::options()),
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
                        ['numero' => $record->numero, 'etat' => $record->etat?->value],
                    )),
                Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Supprimer')
                    ->visible(fn () => auth()->user()->can('supprimer_boutique'))
                    ->modalHeading('Supprimer la boutique')
                    ->modalDescription('La suppression sera refusée si la boutique porte des attributions. Passez-la plutôt en indisponible.')
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
