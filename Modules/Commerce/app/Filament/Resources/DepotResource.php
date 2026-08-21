<?php

namespace Modules\Commerce\Filament\Resources;

use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Artisanat\Models\Artisan;
use Modules\Commerce\Enums\StatutDepot;
use Modules\Commerce\Filament\Resources\DepotResource\Pages;
use Modules\Commerce\Models\Depot;
use Modules\Commerce\Models\Produit;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\JournalAudit;

/**
 * Dépôts d'articles.
 *
 * L'écran suit les deux temps du document : on constitue la liste en
 * brouillon, puis on valide — ce qui écrit les entrées au journal de
 * stock, fige les lignes et rend la décharge imprimable.
 */
class DepotResource extends Resource
{
    protected static ?string $model = Depot::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::COMMERCE;

    protected static ?string $navigationLabel = 'Dépôts';

    protected static ?string $modelLabel = 'Dépôt';

    protected static ?string $pluralModelLabel = 'Dépôts';

    protected static ?string $slug = 'depots';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'numero';

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_depots');
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->schema([
                Grid::make(2)->schema([
                    Forms\Components\Select::make('artisan_id')
                        ->label('Artisan déposant')
                        ->relationship('artisan', 'nom', fn (Builder $query) => $query->where('actif', true))
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->identite)
                        ->searchable(['matricule', 'nom', 'prenom'])
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (mixed $state, Set $set): void {
                            $boutique = filled($state)
                                ? Artisan::find($state)?->getAttributionActive()?->boutique_id
                                : null;

                            if ($boutique) {
                                $set('boutique_id', $boutique);
                            }
                        }),
                    Forms\Components\Select::make('boutique_id')
                        ->label('Boutique')
                        ->relationship('boutique', 'numero')
                        ->searchable()
                        ->preload()
                        ->required(),
                ]),
                Grid::make(2)->schema([
                    Forms\Components\DatePicker::make('date_depot')
                        ->label('Date du dépôt')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->default(now())
                        ->maxDate(now())
                        ->required(),
                    Forms\Components\Select::make('exercice_id')
                        ->label('Exercice')
                        ->relationship(
                            name: 'exercice',
                            titleAttribute: 'libelle',
                            modifyQueryUsing: fn (Builder $query, ?Model $record) => $query->where(
                                fn (Builder $sousRequete) => $sousRequete
                                    ->where('cloture', false)
                                    ->when(
                                        $record?->exercice_id,
                                        fn (Builder $q, $identifiant) => $q->orWhere('exercices.id', $identifiant),
                                    ),
                            ),
                        )
                        ->default(fn () => Exercice::courant()?->getKey())
                        ->searchable()
                        ->preload()
                        ->required(),
                ]),
                // Les articles ne se saisissent qu'en brouillon : après
                // validation, le document vaut décharge signée.
                Forms\Components\Repeater::make('lignes')
                    ->label('Articles confiés')
                    ->relationship('lignes')
                    ->addActionLabel('Ajouter un article')
                    ->reorderable(false)
                    ->defaultItems(1)
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('produit_id')
                            ->label('Produit')
                            ->options(fn (Get $get) => Produit::query()
                                ->where('actif', true)
                                ->when($get('../../boutique_id'), fn (Builder $q, $boutique) => $q->where('boutique_id', $boutique))
                                ->orderBy('reference')
                                ->pluck('designation', 'id')
                                ->all())
                            ->searchable()
                            ->required()
                            ->distinct()
                            ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                        Forms\Components\TextInput::make('quantite')
                            ->label('Quantité')
                            ->placeholder('1')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                    ])
                    ->disabled(fn (?Depot $record) => $record?->estValide() ?? false),
                Forms\Components\Textarea::make('observations')
                    ->label('Observations')
                    ->placeholder('État des pièces, remarques particulières, conditions convenues')
                    ->rows(3),
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
                    ->badge()
                    ->copyable(),
                Tables\Columns\TextColumn::make('date_depot')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('artisan.nom')
                    ->label('Artisan')
                    ->description(fn (Depot $record) => $record->artisan?->matricule)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('boutique.numero')
                    ->label('Boutique')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('lignes_count')
                    ->label('Lignes')
                    ->counts('lignes')
                    ->sortable(),
                Tables\Columns\TextColumn::make('articles')
                    ->label('Articles')
                    ->state(fn (Depot $record) => $record->nombreArticles()),
                Tables\Columns\TextColumn::make('statut')
                    ->label('Statut')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('validePar.name')
                    ->label('Reçu par')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('date_validation')
                    ->label('Validé le')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('exercice.libelle')
                    ->label('Exercice')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('statut')
                    ->label('Statut')
                    ->options(StatutDepot::options()),
                Tables\Filters\SelectFilter::make('artisan_id')
                    ->label('Artisan')
                    ->relationship('artisan', 'nom')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('exercice_id')
                    ->label('Exercice')
                    ->relationship('exercice', 'libelle'),
            ])
            ->defaultSort('numero', 'desc')
            ->recordActions([
                Actions\Action::make('valider')
                    ->label('Valider')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->iconButton()
                    ->tooltip('Valider et entrer en stock')
                    ->requiresConfirmation()
                    ->visible(fn (Depot $record) => auth()->user()->can('valider_depot') && $record->estModifiable())
                    ->modalHeading('Valider le dépôt')
                    ->modalDescription('Les articles entrent en stock, les lignes sont figées et la décharge devient imprimable. La validation est irréversible.')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->action(fn (Depot $record) => $record->valider())
                    ->after(fn (Depot $record) => JournalAudit::enregistrer(
                        'Validation dépôt',
                        'COMMERCE',
                        'Depot',
                        $record->id,
                        [
                            'numero' => $record->numero,
                            'artisan' => $record->artisan?->matricule,
                            'articles' => $record->nombreArticles(),
                        ],
                    )),
                Actions\Action::make('decharge')
                    ->label('Décharge')
                    ->icon('heroicon-o-document-arrow-down')
                    ->iconButton()
                    ->tooltip('Imprimer la décharge')
                    ->visible(fn (Depot $record) => auth()->user()->can('imprimer_decharge_depot') && $record->estValide())
                    ->action(function (Depot $record) {
                        $record->loadMissing(['lignes', 'artisan.corpsMetier', 'boutique.village', 'exercice', 'validePar']);

                        JournalAudit::enregistrer(
                            'Édition décharge de dépôt',
                            'COMMERCE',
                            'Depot',
                            $record->id,
                            ['numero' => $record->numero],
                        );

                        // dompdf renvoie déjà une réponse HTTP complète ;
                        // Filament la relaie telle quelle au navigateur.
                        return Pdf::loadView('commerce::depots.decharge', [
                            'depot' => $record,
                            'village' => $record->boutique?->village,
                            'genereLe' => now()->format('d/m/Y à H:i'),
                        ])->download("decharge-{$record->numero}.pdf");
                    }),
                Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Modifier')
                    ->visible(fn (Depot $record) => auth()->user()->can('modifier_depot') && $record->estModifiable())
                    ->modalHeading('Modifier le dépôt')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->after(fn (Depot $record) => JournalAudit::enregistrer(
                        'Modification dépôt',
                        'COMMERCE',
                        'Depot',
                        $record->id,
                        ['numero' => $record->numero],
                    )),
                Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Supprimer')
                    ->visible(fn (Depot $record) => auth()->user()->can('supprimer_depot') && $record->estModifiable())
                    ->modalHeading('Supprimer le dépôt')
                    ->modalDescription('Seul un brouillon se supprime : un dépôt validé est la pièce qui justifie la détention des biens.')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->before(fn (Depot $record) => JournalAudit::enregistrer(
                        'Suppression dépôt',
                        'COMMERCE',
                        'Depot',
                        $record->id,
                        ['numero' => $record->numero],
                    )),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Aucun dépôt enregistré')
            ->emptyStateDescription('Le dépôt est la décharge par laquelle le village reconnaît détenir les articles confiés par un artisan.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageDepots::route('/'),
        ];
    }
}
