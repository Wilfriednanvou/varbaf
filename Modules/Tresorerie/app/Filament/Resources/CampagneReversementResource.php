<?php

namespace Modules\Tresorerie\Filament\Resources;

use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Models\JournalAudit;
use Modules\Tresorerie\Enums\StatutCampagneReversement;
use Modules\Tresorerie\Filament\Resources\CampagneReversementResource\Pages;
use Modules\Tresorerie\Models\CampagneReversement;
use Modules\Tresorerie\Models\Reversement;
use Modules\Tresorerie\Services\ServiceCampagneReversement;

/**
 * Campagnes de reversement (RG-16 à RG-21).
 *
 * L'écran suit la séparation posée par RG-23 : « Préparer » et
 * « Valider » sont deux actions distinctes, portées par deux
 * permissions distinctes, tenues par deux profils distincts. Préparer
 * se refait autant de fois qu'on veut ; valider ne se fait qu'une fois.
 */
class CampagneReversementResource extends Resource
{
    protected static ?string $model = CampagneReversement::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-banknotes';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::TRESORERIE;

    protected static ?string $navigationLabel = 'Campagnes de reversement';

    protected static ?string $modelLabel = 'Campagne de reversement';

    protected static ?string $pluralModelLabel = 'Campagnes de reversement';

    protected static ?int $navigationSort = 6;

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_campagnes_reversement');
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->schema([
                Grid::make(2)->schema([
                    Forms\Components\DatePicker::make('periode')
                        ->label('Mois concerné')
                        ->default(now()->startOfMonth())
                        ->required(),
                    Forms\Components\DatePicker::make('date_arrete')
                        ->label('Date d\'arrêté')
                        ->default(now()->endOfMonth())
                        ->required(),
                ]),

                // `exercice_id` ne figure pas au formulaire : c'est
                // l'exercice en cours, posé à l'enregistrement par
                // `ManageCampagnesReversement`. Une valeur dérivée ne se
                // choisit pas.
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('periode')
                    ->label('Période')
                    ->formatStateUsing(fn ($state) => $state?->translatedFormat('F Y'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('date_arrete')
                    ->label('Arrêtée au')
                    ->date('d/m/Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('statut')
                    ->label('Statut')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('nombre_beneficiaires')
                    ->label('Bénéficiaires')
                    ->sortable(),
                Tables\Columns\TextColumn::make('montant_total')
                    ->label('Total décaissé')
                    ->money('XAF')
                    ->weight('bold')
                    ->sortable(),
                Tables\Columns\TextColumn::make('date_generation')
                    ->label('Préparée le')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('genereePar.name')
                    ->label('Préparée par')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('date_validation')
                    ->label('Validée le')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('valideePar.name')
                    ->label('Validée par')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('periode', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('statut')
                    ->label('Statut')
                    ->options(StatutCampagneReversement::options()),
            ])
            ->recordActions([
                Actions\Action::make('preparer')
                    ->label('Préparer')
                    ->icon('heroicon-o-calculator')
                    ->iconButton()
                    ->tooltip('Recalculer les parts dues')
                    ->color('primary')
                    ->visible(fn (CampagneReversement $record) => $record->estEnPreparation()
                        && auth()->user()->can('preparer_campagne_reversement'))
                    ->requiresConfirmation()
                    ->modalHeading('Préparer la campagne')
                    ->modalDescription('Le calcul précédent sera entièrement refait depuis les ventes. Aucune écriture en caisse à ce stade.')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->action(function (CampagneReversement $record) {
                        try {
                            $campagne = app(ServiceCampagneReversement::class)->preparer($record);
                        } catch (\RuntimeException $e) {
                            Notification::make()
                                ->title('Préparation impossible')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        JournalAudit::enregistrer(
                            'Préparation campagne de reversement',
                            'TRESORERIE',
                            'CampagneReversement',
                            $campagne->id,
                            [
                                'periode' => $campagne->libellePeriode(),
                                'beneficiaires' => $campagne->nombre_beneficiaires,
                                'montant_total' => $campagne->montant_total,
                            ],
                        );

                        Notification::make()
                            ->title('Campagne préparée')
                            ->body("{$campagne->nombre_beneficiaires} artisan(s) à payer, "
                                .number_format((float) $campagne->montant_total, 0, ',', ' ').' FCFA au total.')
                            ->success()
                            ->send();
                    }),

                Actions\Action::make('valider')
                    ->label('Valider')
                    ->icon('heroicon-o-lock-closed')
                    ->iconButton()
                    ->tooltip('Rattacher les ventes et décaisser')
                    ->color('danger')
                    ->visible(fn (CampagneReversement $record) => $record->estEnPreparation()
                        && auth()->user()->can('valider_campagne_reversement'))
                    ->requiresConfirmation()
                    ->modalHeading('Valider la campagne')
                    ->modalDescription(fn (CampagneReversement $record) => "Les ventes retenues seront rattachées définitivement et "
                        .number_format((float) $record->montant_total, 0, ',', ' ')
                        .' FCFA sortiront de la caisse. Cette action est irréversible.')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->action(function (CampagneReversement $record) {
                        try {
                            $campagne = app(ServiceCampagneReversement::class)->valider($record);
                        } catch (\RuntimeException $e) {
                            Notification::make()
                                ->title('Validation impossible')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        JournalAudit::enregistrer(
                            'Validation campagne de reversement',
                            'TRESORERIE',
                            'CampagneReversement',
                            $campagne->id,
                            [
                                'periode' => $campagne->libellePeriode(),
                                'beneficiaires' => $campagne->nombre_beneficiaires,
                                'montant_total' => $campagne->montant_total,
                            ],
                        );

                        Notification::make()
                            ->title('Campagne validée')
                            ->body(number_format((float) $campagne->montant_total, 0, ',', ' ')
                                ." FCFA décaissés au profit de {$campagne->nombre_beneficiaires} artisan(s).")
                            ->success()
                            ->send();
                    }),

                Actions\Action::make('etat')
                    ->label('État récapitulatif')
                    ->icon('heroicon-o-document-chart-bar')
                    ->iconButton()
                    ->tooltip('Éditer l\'état récapitulatif')
                    ->visible(fn () => auth()->user()->can('imprimer_etat_reversement'))
                    ->url(fn (CampagneReversement $record) => route('campagnes.etat', $record->id))
                    ->openUrlInNewTab(),

                Actions\Action::make('recu')
                    ->label('Reçu artisan')
                    ->icon('heroicon-o-document-arrow-down')
                    ->iconButton()
                    ->tooltip('Éditer le reçu d\'un artisan')
                    ->visible(fn () => auth()->user()->can('imprimer_recu_reversement'))
                    ->modalHeading('Reçu de reversement')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Éditer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->form([
                        // RG-18 : un reçu par artisan, signé par lui. La
                        // liste ne propose que les artisans de cette
                        // campagne — un reçu ne se fabrique pas pour
                        // quelqu'un qui n'y figure pas.
                        Forms\Components\Select::make('reversement_id')
                            ->label('Artisan')
                            ->options(fn (CampagneReversement $record) => $record->reversements()
                                ->with('artisan')
                                ->get()
                                ->mapWithKeys(fn (Reversement $reversement) => [
                                    $reversement->id => $reversement->artisan?->nom_complet
                                        ?? "Artisan #{$reversement->artisan_id}",
                                ])
                                ->all())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (CampagneReversement $record, array $data) {
                        $reversement = $record->reversements()
                            ->with(['artisan', 'lignes.vente'])
                            ->find($data['reversement_id']);

                        if (! $reversement) {
                            Notification::make()
                                ->title('Reversement introuvable')
                                ->body("Cet artisan ne figure pas dans la campagne.")
                                ->danger()
                                ->send();

                            return;
                        }

                        return redirect()->route('campagnes.recu', ['campagne' => $record->id, 'reversement' => $data['reversement_id']]);
                    }),

                Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Abandonner cette préparation')
                    ->visible(fn (CampagneReversement $record) => $record->estEnPreparation()
                        && auth()->user()->can('supprimer_campagne_reversement'))
                    ->modalHeading('Abandonner la campagne')
                    ->modalDescription('La préparation et ses calculs seront effacés. Aucune vente n\'a encore été rattachée.')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    // L'audit des suppressions est enregistré dans
                    // `before()` : après coup, l'enregistrement n'est
                    // plus lisible (convention amendée de CLAUDE.md).
                    ->before(fn (CampagneReversement $record) => JournalAudit::enregistrer(
                        'Abandon campagne de reversement',
                        'TRESORERIE',
                        'CampagneReversement',
                        $record->id,
                        ['periode' => $record->libellePeriode()],
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageCampagnesReversement::route('/'),
        ];
    }
}
