<?php

namespace Modules\Commerce\Filament\Resources;

use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Commerce\Enums\EtatVente;
use Modules\Commerce\Enums\ModeReglement;
use Modules\Commerce\Enums\ProvenanceClient;
use Modules\Commerce\Filament\Resources\VenteResource\Pages;
use Modules\Commerce\Models\Produit;
use Modules\Commerce\Models\Vente;
use Modules\Commerce\Services\ServiceVente;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Models\JournalAudit;

/**
 * Écran de vente.
 *
 * Le cheminement impose la boutique avant les produits (RG-09 bis) :
 * c'est ce qui rend RG-08 — une vente, une boutique — structurellement
 * inviolable dans l'interface. `ServiceVente` reverifie la règle de son
 * côté, parce qu'un import de données n'emprunte pas ce cheminement.
 *
 * Une vente enregistrée ne se modifie pas : elle s'annule. L'écran
 * n'offre donc ni action de modification ni suppression.
 */
class VenteResource extends Resource
{
    protected static ?string $model = Vente::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::COMMERCE;

    protected static ?string $navigationLabel = 'Ventes';

    protected static ?string $modelLabel = 'Vente';

    protected static ?string $pluralModelLabel = 'Ventes';

    protected static ?string $slug = 'ventes';

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'numero';

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_ventes');
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->schema([
                // 1. La boutique d'abord. Tant qu'elle n'est pas
                //    choisie, la liste des produits reste vide.
                Forms\Components\Select::make('boutique_id')
                    ->label('Boutique')
                    ->relationship(
                        name: 'boutique',
                        titleAttribute: 'numero',
                        modifyQueryUsing: fn (Builder $query) => $query->orderBy('numero'),
                    )
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->dehydrated(false),
                // 2. Puis les produits de cette seule boutique.
                Forms\Components\Repeater::make('lignes')
                    ->label('Articles vendus')
                    ->addActionLabel('Ajouter un article')
                    ->reorderable(false)
                    ->defaultItems(1)
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('produit_id')
                            ->label('Produit')
                            ->options(fn (Get $get) => filled($get('../../boutique_id'))
                                ? Produit::query()
                                    ->vendable()
                                    ->where('boutique_id', $get('../../boutique_id'))
                                    ->orderBy('designation')
                                    ->get()
                                    ->mapWithKeys(fn (Produit $produit) => [
                                        $produit->id => "{$produit->designation} — {$produit->prix_unitaire} FCFA (stock : {$produit->getQuantiteEnStock()})",
                                    ])
                                    ->all()
                                : [])
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
                    ]),
                Grid::make(2)->schema([
                    Forms\Components\Select::make('mode_reglement')
                        ->label('Mode de règlement')
                        ->options(ModeReglement::options())
                        ->default(ModeReglement::ESPECES->value)
                        ->native(false)
                        ->required(),
                    Forms\Components\Select::make('provenance_client')
                        ->label('Provenance du client')
                        ->options(ProvenanceClient::options())
                        ->native(false)
                        ->placeholder('Non renseignée'),
                ]),
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('nom_client')
                        ->label('Nom du client')
                        ->placeholder('Facultatif')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('contact_client')
                        ->label('Téléphone ou adresse électronique')
                        ->placeholder('Facultatif')
                        ->maxLength(255),
                ]),
                Forms\Components\Toggle::make('accepte_notifications')
                    ->label('Le client accepte de recevoir les informations du village')
                    ->default(false)
                    ->helperText('Consentement recueilli oralement au comptoir : il conditionne l\'envoi des annonces d\'événements'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('numero')
                    ->label('Ticket')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->copyable(),
                Tables\Columns\TextColumn::make('date_vente')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('boutique.numero')
                    ->label('Boutique')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('artisan.nom')
                    ->label('Artisan')
                    ->description(fn (Vente $record) => $record->artisan?->matricule)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('montant_total')
                    ->label('Encaissé')
                    ->money('XAF')
                    ->sortable(),
                Tables\Columns\TextColumn::make('montant_commission')
                    ->label('Commission')
                    ->money('XAF')
                    ->description(fn (Vente $record) => "{$record->taux_commission} %")
                    ->sortable(),
                Tables\Columns\TextColumn::make('part_artisan')
                    ->label('Part artisan')
                    ->money('XAF')
                    ->sortable(),
                Tables\Columns\TextColumn::make('mode_reglement')
                    ->label('Règlement')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('vendeur.nom')
                    ->label('Vendeur')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('nom_client')
                    ->label('Client')
                    ->placeholder('De passage')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('etat')
                    ->label('État')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('etat')
                    ->label('État')
                    ->options(EtatVente::options()),
                Tables\Filters\SelectFilter::make('boutique_id')
                    ->label('Boutique')
                    ->relationship('boutique', 'numero')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('artisan_id')
                    ->label('Artisan')
                    ->relationship('artisan', 'nom')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('exercice_id')
                    ->label('Exercice')
                    ->relationship('exercice', 'libelle'),
            ])
            ->defaultSort('date_vente', 'desc')
            ->recordActions([
                Actions\Action::make('recu')
                    ->label('Reçu')
                    ->icon('heroicon-o-document-arrow-down')
                    ->iconButton()
                    ->tooltip('Éditer le reçu de vente')
                    ->visible(fn () => auth()->user()->can('imprimer_recu_vente'))
                    ->action(function (Vente $record) {
                        $record->loadMissing(['lignes', 'artisan', 'boutique.village', 'vendeur']);

                        JournalAudit::enregistrer(
                            'Édition reçu de vente',
                            'COMMERCE',
                            'Vente',
                            $record->id,
                            ['numero' => $record->numero],
                        );

                        return Pdf::loadView('commerce::ventes.recu', [
                            'vente' => $record,
                            'village' => $record->boutique?->village,
                            'genereLe' => now()->format('d/m/Y à H:i'),
                        ])->download("recu-{$record->numero}.pdf");
                    }),
                Actions\Action::make('annuler')
                    ->label('Annuler')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->iconButton()
                    ->tooltip('Annuler la vente')
                    ->visible(fn (Vente $record) => auth()->user()->can('annuler_vente') && $record->estValidee())
                    ->modalHeading('Annuler la vente')
                    ->modalDescription('Les articles reviennent en stock et l\'encaissement est contre-passé en caisse. La vente reste au registre, avec son motif d\'annulation.')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->schema([
                        Forms\Components\Textarea::make('motif_annulation')
                            ->label('Motif de l\'annulation')
                            ->placeholder('Erreur de saisie, client revenu sur son achat, article défectueux')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(fn (Vente $record, array $data) => app(ServiceVente::class)
                        ->annuler($record, $data['motif_annulation']))
                    ->after(fn (Vente $record) => JournalAudit::enregistrer(
                        'Annulation vente',
                        'COMMERCE',
                        'Vente',
                        $record->id,
                        [
                            'numero' => $record->numero,
                            'montant' => $record->montant_total,
                            'motif' => $record->motif_annulation,
                        ],
                    )),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Aucune vente enregistrée')
            ->emptyStateDescription('La saisie commence par le choix d\'une boutique, puis des produits de cette boutique.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageVentes::route('/'),
        ];
    }
}
