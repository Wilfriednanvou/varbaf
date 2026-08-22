<?php

namespace Modules\Commerce\Filament\Resources;

use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Commerce\Enums\EtatVente;
use Modules\Commerce\Filament\Resources\VenteResource\Pages;
use Modules\Commerce\Models\Vente;
use Modules\Commerce\Services\ServiceVente;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Models\JournalAudit;

/**
 * Écran de consultation des ventes.
 *
 * La saisie n'a qu'un seul chemin : le composant Livewire
 * `Modules\Tresorerie\Livewire\VentesCaisseTable`, embarqué dans la
 * session de caisse — seul endroit où `docs/specification-tresorerie.md`
 * (§7.5) autorise l'écran de vente à vivre. Cette ressource ne propose
 * donc ni création ni modification ni suppression : elle liste les
 * ventes, imprime le reçu et permet l'annulation, deux opérations de
 * consultation plutôt que de saisie.
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

    public static function canCreate(): bool
    {
        return false;
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
