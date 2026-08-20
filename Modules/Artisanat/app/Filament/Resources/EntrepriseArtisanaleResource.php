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
use Modules\Artisanat\Filament\Resources\EntrepriseArtisanaleResource\Pages;
use Modules\Artisanat\Models\EntrepriseArtisanale;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Models\JournalAudit;

class EntrepriseArtisanaleResource extends Resource
{
    protected static ?string $model = EntrepriseArtisanale::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-briefcase';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::ARTISANAT;

    protected static ?string $navigationLabel = 'Entreprises artisanales';

    protected static ?string $modelLabel = 'Entreprise artisanale';

    protected static ?string $pluralModelLabel = 'Entreprises artisanales';

    protected static ?string $slug = 'entreprises-artisanales';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'raison_sociale';

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_entreprises');
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->schema([
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('raison_sociale')
                        ->label('Raison sociale')
                        ->placeholder('Établissement NGUEMO et Fils')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('numero_contribuable')
                        ->label('Numéro de contribuable')
                        ->placeholder('P0123456789A')
                        ->maxLength(30)
                        ->unique(ignoreRecord: true),
                ]),
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('telephone')
                        ->label('Téléphone')
                        ->tel()
                        ->placeholder('6XX XX XX XX')
                        ->maxLength(30),
                    Forms\Components\Select::make('village_id')
                        ->label('Village')
                        ->relationship('village', 'nom')
                        ->searchable()
                        ->preload()
                        ->required(),
                ]),
                Forms\Components\TextInput::make('adresse')
                    ->label('Adresse')
                    ->placeholder('Quartier Djeleng, Bafoussam')
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('raison_sociale')
                    ->label('Raison sociale')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('numero_contribuable')
                    ->label('Contribuable')
                    ->searchable()
                    ->placeholder('Non formalisée')
                    ->copyable(),
                Tables\Columns\TextColumn::make('telephone')
                    ->label('Téléphone')
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('artisans_count')
                    ->label('Artisans')
                    ->counts('artisans')
                    ->sortable(),
                Tables\Columns\TextColumn::make('village.nom')
                    ->label('Village')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('adresse')
                    ->label('Adresse')
                    ->placeholder('—')
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
            ->defaultSort('raison_sociale')
            ->recordActions([
                Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Modifier')
                    ->visible(fn () => auth()->user()->can('modifier_entreprise'))
                    ->modalHeading('Modifier l\'entreprise artisanale')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->after(fn (EntrepriseArtisanale $record) => JournalAudit::enregistrer(
                        'Modification entreprise artisanale',
                        'ARTISANAT',
                        'EntrepriseArtisanale',
                        $record->id,
                        ['raison_sociale' => $record->raison_sociale],
                    )),
                Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Supprimer')
                    ->visible(fn () => auth()->user()->can('supprimer_entreprise'))
                    ->modalHeading('Supprimer l\'entreprise artisanale')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->before(fn (EntrepriseArtisanale $record) => JournalAudit::enregistrer(
                        'Suppression entreprise artisanale',
                        'ARTISANAT',
                        'EntrepriseArtisanale',
                        $record->id,
                        ['raison_sociale' => $record->raison_sociale],
                    )),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Aucune entreprise artisanale enregistrée')
            ->emptyStateDescription('La plupart des artisans exercent en nom propre : cette liste ne recense que les structures formelles.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageEntreprisesArtisanales::route('/'),
        ];
    }
}
