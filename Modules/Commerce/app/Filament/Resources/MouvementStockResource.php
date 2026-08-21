<?php

namespace Modules\Commerce\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Commerce\Enums\SensMouvementStock;
use Modules\Commerce\Enums\TypeMouvementStock;
use Modules\Commerce\Filament\Resources\MouvementStockResource\Pages;
use Modules\Commerce\Models\MouvementStock;
use Modules\Commerce\Services\ServiceMouvementStock;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Models\JournalAudit;

/**
 * Journal de stock, en consultation.
 *
 * Aucune création, aucune modification, aucune suppression : les
 * écritures naissent du service, jamais de cet écran. La seule action
 * offerte est la contre-passation — qui n'efface rien et ajoute une
 * ligne de sens inverse.
 *
 * C'est la même ergonomie que celle qu'aura le brouillard de caisse :
 * un journal se lit et se corrige par ajout, il ne s'édite pas.
 */
class MouvementStockResource extends Resource
{
    protected static ?string $model = MouvementStock::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-arrows-up-down';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::COMMERCE;

    protected static ?string $navigationLabel = 'Journal de stock';

    protected static ?string $modelLabel = 'Mouvement de stock';

    protected static ?string $pluralModelLabel = 'Journal de stock';

    protected static ?string $slug = 'journal-stock';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_mouvements_stock');
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
                Tables\Columns\TextColumn::make('date_mouvement')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('produit.reference')
                    ->label('Référence')
                    ->description(fn (MouvementStock $record) => $record->produit?->designation)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('numero_ordre')
                    ->label('N° d\'ordre')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('type')
                    ->label('Nature')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sens')
                    ->label('Sens')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('quantite')
                    ->label('Quantité')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('solde_apres')
                    ->label('Solde après')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('motif')
                    ->label('Motif')
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('origine')
                    ->label('Origine')
                    ->state(fn (MouvementStock $record) => $record->libelleOrigine())
                    ->placeholder('Saisie directe')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('mouvementContrepasse.numero_ordre')
                    ->label('Contre-passe le n°')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('saisiPar.name')
                    ->label('Saisi par')
                    ->placeholder('Système')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('produit_id')
                    ->label('Produit')
                    ->relationship('produit', 'designation')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('type')
                    ->label('Nature')
                    ->options(TypeMouvementStock::options()),
                Tables\Filters\SelectFilter::make('sens')
                    ->label('Sens')
                    ->options(SensMouvementStock::options()),
            ])
            ->defaultSort('date_mouvement', 'desc')
            ->recordActions([
                Actions\Action::make('contrepasser')
                    ->label('Contre-passer')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->iconButton()
                    ->tooltip('Corriger par un mouvement inverse')
                    ->visible(fn (MouvementStock $record) => auth()->user()->can('contrepasser_mouvement_stock')
                        && ! $record->estUneContrepassation()
                        && ! $record->estContrepasse())
                    ->modalHeading('Contre-passer le mouvement')
                    ->modalDescription('Le mouvement d\'origine reste au journal. Un mouvement de sens inverse est ajouté et le référence : le journal montre l\'erreur, puis sa correction.')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->schema([
                        Forms\Components\Textarea::make('motif')
                            ->label('Motif de la contre-passation')
                            ->placeholder('Erreur de quantité à la saisie, article rendu à l\'artisan, double comptage')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(fn (MouvementStock $record, array $data) => app(ServiceMouvementStock::class)
                        ->contrepasser($record, $data['motif']))
                    ->after(fn (MouvementStock $record) => JournalAudit::enregistrer(
                        'Contre-passation mouvement de stock',
                        'COMMERCE',
                        'MouvementStock',
                        $record->id,
                        [
                            'numero_ordre' => $record->numero_ordre,
                            'produit' => $record->produit?->reference,
                            'quantite' => $record->quantite,
                        ],
                    )),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Aucun mouvement de stock')
            ->emptyStateDescription('Le journal se remplit à la validation des dépôts, aux ventes et aux retraits.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageMouvementsStock::route('/'),
        ];
    }
}
