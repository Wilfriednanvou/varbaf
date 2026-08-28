<?php

namespace Modules\Commerce\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Commerce\Filament\Resources\TauxCommissionResource\Pages;
use Modules\Commerce\Models\TauxCommission;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Models\JournalAudit;

/**
 * Historique des taux de commission.
 *
 * Écran délibérément pauvre en actions : on ajoute un taux, on corrige
 * une saisie tant qu'elle n'a pas pris effet, et c'est tout. Aucune
 * suppression n'est exposée — l'historique des taux est ce qui permet
 * de rejouer le calcul d'une commission ancienne.
 */
class TauxCommissionResource extends Resource
{
    protected static ?string $model = TauxCommission::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::COMMERCE;

    protected static ?string $navigationLabel = 'Taux de commission';

    protected static ?string $modelLabel = 'Taux de commission';

    protected static ?string $pluralModelLabel = 'Taux de commission';

    protected static ?string $slug = 'taux-commission';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_taux_commission');
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
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('taux')
                        ->label('Taux (%)')
                        ->placeholder('10')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->required()
                        ->suffix('%'),
                    Forms\Components\DatePicker::make('date_effet')
                        ->label('Date d\'effet')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->required()
                        ->unique(ignoreRecord: true),
                ]),
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('reference_decision')
                        ->label('Référence de la décision')
                        ->placeholder('Note de service n° ... du ...')
                        ->maxLength(255),
                    Forms\Components\Select::make('village_id')
                        ->label('Village')
                        ->relationship('village', 'nom')
                        ->searchable()
                        ->preload()
                        ->required(),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('taux')
                    ->label('Taux')
                    ->suffix(' %')
                    ->numeric(decimalPlaces: 2)
                    ->badge()
                    ->color(fn (TauxCommission $record): string => $record->estLeTauxActuel() ? 'success' : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('date_effet')
                    ->label('Date d\'effet')
                    ->date('d/m/Y')
                    ->description(fn (TauxCommission $record) => $record->estEntreEnVigueur()
                        ? ($record->estLeTauxActuel() ? 'Taux actuel en vigueur' : 'Ancien taux (figé)')
                        : 'À venir — encore modifiable')
                    ->color(fn (TauxCommission $record): ?string => $record->estLeTauxActuel() ? null : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('reference_decision')
                    ->label('Décision')
                    ->placeholder('Non référencée')
                    ->color(fn (TauxCommission $record): ?string => $record->estLeTauxActuel() ? null : 'gray')
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('saisiPar.name')
                    ->label('Saisi par')
                    ->placeholder('Système')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('village.nom')
                    ->label('Village')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Enregistré le')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('village_id')
                    ->label('Village')
                    ->relationship('village', 'nom'),
            ])
            ->defaultSort('date_effet', 'desc')
            ->recordActions([
                // La modification disparaît dès que le taux a pris
                // effet : le modèle refuserait l'écriture, autant ne
                // pas proposer un bouton qui mène à une exception.
                Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Corriger avant entrée en vigueur')
                    ->visible(fn (TauxCommission $record) => auth()->user()->can('modifier_taux_commission')
                        && $record->estModifiable())
                    ->modalHeading('Corriger le taux de commission')
                    ->modalDescription('Un taux ne se corrige que tant qu\'il n\'a pas pris effet. Passé sa date d\'effet, enregistrez un nouveau taux.')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->after(fn (TauxCommission $record) => JournalAudit::enregistrer(
                        'Correction taux de commission',
                        'COMMERCE',
                        'TauxCommission',
                        $record->id,
                        ['taux' => $record->taux, 'date_effet' => $record->date_effet?->toDateString()],
                    )),

                // Activer maintenant : avance la date d'effet à aujourd'hui
                // pour un taux encore à venir. Pratique pour corriger une
                // date saisie trop loin dans le futur sans recréer de ligne.
                Actions\Action::make('activer')
                    ->iconButton()
                    ->icon('heroicon-o-bolt')
                    ->color('warning')
                    ->tooltip('Activer dès aujourd\'hui')
                    ->visible(fn (TauxCommission $record) => auth()->user()->can('modifier_taux_commission')
                        && $record->estModifiable())
                    ->requiresConfirmation()
                    ->modalHeading('Activer ce taux dès aujourd\'hui ?')
                    ->modalDescription(fn (TauxCommission $record) => "Le taux {$record->libelle()} verra sa date d'effet avancée à aujourd'hui. Il deviendra immédiatement le taux en vigueur pour toutes les nouvelles ventes.")
                    ->modalSubmitActionLabel('Activer maintenant')
                    ->modalCancelActionLabel('Fermer')
                    ->action(function (TauxCommission $record): void {
                        $record->update(['date_effet' => now()->toDateString()]);
                        JournalAudit::enregistrer(
                            'Activation immédiate taux de commission',
                            'COMMERCE',
                            'TauxCommission',
                            $record->id,
                            ['taux' => $record->taux, 'date_effet' => $record->date_effet?->toDateString()],
                        );
                    })
                    ->successNotificationTitle('Taux activé avec succès'),

                // Réappliquer : crée un nouveau taux identique daté d'aujourd'hui.
                // Visible sur tous les taux, y compris les figés. C'est le seul
                // moyen de "choisir" un ancien taux sans réécrire l'historique.
                Actions\Action::make('reappliquer')
                    ->iconButton()
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->tooltip('Réappliquer ce taux à partir d\'aujourd\'hui')
                    ->visible(fn (TauxCommission $record) => auth()->user()->can('ajouter_taux_commission')
                        && ! $record->estLeTauxActuel())
                    ->requiresConfirmation()
                    ->modalHeading('Réappliquer ce taux ?')
                    ->modalDescription(fn (TauxCommission $record) => "Un nouveau taux de {$record->taux} % sera créé avec la date d'aujourd'hui. Il deviendra le taux actif pour toutes les nouvelles ventes.")
                    ->modalSubmitActionLabel('Réappliquer maintenant')
                    ->modalCancelActionLabel('Fermer')
                    ->action(function (TauxCommission $record): void {
                        $nouveau = TauxCommission::create([
                            'taux'               => $record->taux,
                            'date_effet'         => now()->toDateString(),
                            'reference_decision' => $record->reference_decision,
                            'village_id'         => $record->village_id,
                        ]);
                        JournalAudit::enregistrer(
                            'Réapplication taux de commission',
                            'COMMERCE',
                            'TauxCommission',
                            $nouveau->id,
                            ['taux' => $nouveau->taux, 'date_effet' => $nouveau->date_effet?->toDateString(), 'source_id' => $record->id],
                        );
                    })
                    ->successNotificationTitle('Taux réappliqué avec succès'),

                // Suppression : uniquement les taux non encore en vigueur.
                // Un taux en vigueur — même s'il n'est plus l'actuel — doit
                // rester pour permettre de rejouer des commissions anciennes.
                Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Supprimer ce taux')
                    ->visible(fn (TauxCommission $record) => auth()->user()->can('supprimer_taux_commission')
                        && $record->estModifiable())
                    ->modalHeading('Supprimer ce taux ?')
                    ->modalDescription(fn (TauxCommission $record) => "Le taux {$record->libelle()} sera définitivement supprimé. Cette action est irréversible.")
                    ->modalSubmitActionLabel('Supprimer')
                    ->modalCancelActionLabel('Fermer')
                    ->before(fn (TauxCommission $record) => JournalAudit::enregistrer(
                        'Suppression taux de commission',
                        'COMMERCE',
                        'TauxCommission',
                        $record->id,
                        ['taux' => $record->taux, 'date_effet' => $record->date_effet?->toDateString()],
                    )),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Aucun taux de commission enregistré')
            ->emptyStateDescription('Tant qu\'aucun taux n\'est en vigueur, aucune vente ne peut être commissionnée : la saisie de vente sera refusée.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageTauxCommission::route('/'),
        ];
    }
}
