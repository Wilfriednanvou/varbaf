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
use Modules\Artisanat\Enums\EtatEspaceLocatif;
use Modules\Artisanat\Enums\ZoneBoutique;
use Modules\Artisanat\Filament\Resources\EspaceLocatifResource\Pages;
use Modules\Artisanat\Models\EspaceLocatif;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Models\JournalAudit;

/**
 * Découpage du parc en places de vente.
 *
 * À ne pas confondre avec la ressource « Espaces », qui gère les salles
 * de réunion, d'apprentissage et le parking : ici, il s'agit des
 * emplacements loués aux artisans à l'intérieur des boutiques.
 *
 * Le code ne figure pas au formulaire : il se compose à la création à
 * partir de la boutique, et il est ensuite figé.
 */
class EspaceLocatifResource extends Resource
{
    protected static ?string $model = EspaceLocatif::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::ARTISANAT;

    protected static ?string $navigationLabel = 'Espaces locatifs';

    protected static ?string $modelLabel = 'Espace locatif';

    protected static ?string $pluralModelLabel = 'Espaces locatifs';

    protected static ?string $slug = 'espaces-locatifs';

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'code';

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_espaces_locatifs');
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->schema([
                Grid::make(2)->schema([
                    // Verrouillée après création : le code de l'espace
                    // est dérivé de la boutique et figure sur les
                    // contrats signés. Un espace ne déménage pas d'un
                    // local à un autre — on le retire du parc et on en
                    // crée un autre.
                    Forms\Components\Select::make('boutique_id')
                        ->label('Boutique')
                        ->relationship('boutique', 'numero')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->disabled(fn (?EspaceLocatif $record) => $record !== null)
                        ->dehydrated(fn (?EspaceLocatif $record) => $record === null),
                    Forms\Components\Placeholder::make('code_affiche')
                        ->label('Code')
                        ->content(fn (?EspaceLocatif $record) => $record?->code
                            ?? 'Composé automatiquement à partir de la boutique : B01 donne B0101, puis B0102'),
                ]),
                Forms\Components\TextInput::make('libelle')
                    ->label('Nom d\'usage')
                    ->placeholder('Côté rue, fond gauche, comptoir central')
                    ->maxLength(120),
                // L'état n'est modifiable que pour poser ou lever
                // INDISPONIBLE : DISPONIBLE et OCCUPE sont recalculés par
                // les attributions, et une saisie manuelle serait écrasée
                // au premier mouvement de contrat.
                Forms\Components\Select::make('etat')
                    ->label('État')
                    ->options(EtatEspaceLocatif::options())
                    ->default(EtatEspaceLocatif::DISPONIBLE->value)
                    ->native(false)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('boutique.numero')
                    ->label('Boutique')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('libelle')
                    ->label('Nom d\'usage')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('etat')
                    ->label('État')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('occupant')
                    ->label('Occupant')
                    ->state(fn (EspaceLocatif $record) => $record->getOccupantActuel()?->nom_complet)
                    ->placeholder('Libre'),
                Tables\Columns\TextColumn::make('boutique.emplacement')
                    ->label('Emplacement')
                    ->formatStateUsing(fn (?string $state) => $state ? ZoneBoutique::from($state)->getLabel() : null)
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('etat')
                    ->label('État')
                    ->options(EtatEspaceLocatif::options()),
                Tables\Filters\SelectFilter::make('boutique_id')
                    ->label('Boutique')
                    ->relationship('boutique', 'numero')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('code')
            ->recordActions([
                Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Modifier')
                    ->visible(fn () => auth()->user()->can('modifier_espace_locatif'))
                    ->modalHeading('Modifier l\'espace locatif')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->after(fn (EspaceLocatif $record) => JournalAudit::enregistrer(
                        'Modification espace locatif',
                        'ARTISANAT',
                        'EspaceLocatif',
                        $record->id,
                        ['code' => $record->code, 'etat' => $record->etat?->value],
                    )),
                Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Supprimer')
                    ->visible(fn () => auth()->user()->can('supprimer_espace_locatif'))
                    ->modalHeading('Supprimer l\'espace locatif')
                    ->modalDescription('La suppression sera refusée si l\'espace porte des attributions. Passez-le plutôt en indisponible.')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->before(fn (EspaceLocatif $record) => JournalAudit::enregistrer(
                        'Suppression espace locatif',
                        'ARTISANAT',
                        'EspaceLocatif',
                        $record->id,
                        ['code' => $record->code],
                    )),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Aucun espace locatif enregistré')
            ->emptyStateDescription('Chaque boutique se découpe en une ou plusieurs places de vente, qui sont l\'unité réellement attribuée aux artisans.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageEspacesLocatifs::route('/'),
        ];
    }
}
