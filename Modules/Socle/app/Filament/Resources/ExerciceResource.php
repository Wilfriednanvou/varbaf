<?php

namespace Modules\Socle\Filament\Resources;

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
use Modules\Socle\Exceptions\ExerciceNonCloturableException;
use Modules\Socle\Filament\Resources\ExerciceResource\Pages;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\JournalAudit;

class ExerciceResource extends Resource
{
    protected static ?string $model = Exercice::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::SOCLE;

    protected static ?string $navigationLabel = 'Exercices';

    protected static ?string $modelLabel = 'Exercice';

    protected static ?string $pluralModelLabel = 'Exercices';

    protected static ?string $slug = 'exercices';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'libelle';

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_exercices');
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->schema([
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('libelle')
                        ->label('Libellé')
                        ->placeholder('2026-2027')
                        ->required()
                        ->maxLength(50),
                    Forms\Components\Select::make('village_id')
                        ->label('Village')
                        ->relationship('village', 'nom')
                        ->searchable()
                        ->preload()
                        ->required(),
                ]),
                Grid::make(2)->schema([
                    Forms\Components\DatePicker::make('date_debut')
                        ->label('Date de début')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->required(),
                    Forms\Components\DatePicker::make('date_fin')
                        ->label('Date de fin')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->required()
                        ->afterOrEqual('date_debut'),
                ]),
                Forms\Components\Toggle::make('en_cours')
                    ->label('Exercice en cours')
                    ->default(false)
                    ->helperText('Un seul exercice peut être en cours par village : activer celui-ci désactivera automatiquement le précédent'),
                Forms\Components\Toggle::make('cloture')
                    ->label('Exercice clôturé')
                    ->default(false)
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('La clôture se fait depuis l\'action dédiée du tableau : elle est irréversible'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('libelle')
                    ->label('Libellé')
                    ->searchable()
                    ->sortable()
                    ->badge(),
                Tables\Columns\TextColumn::make('village.nom')
                    ->label('Village')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('date_debut')
                    ->label('Début')
                    ->date('d/m/Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('date_fin')
                    ->label('Fin')
                    ->date('d/m/Y')
                    ->sortable(),
                Tables\Columns\IconColumn::make('en_cours')
                    ->label('En cours')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\IconColumn::make('cloture')
                    ->label('Clôturé')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('village_id')
                    ->label('Village')
                    ->relationship('village', 'nom'),
                Tables\Filters\TernaryFilter::make('en_cours')
                    ->label('En cours'),
                Tables\Filters\TernaryFilter::make('cloture')
                    ->label('Clôturé'),
            ])
            ->defaultSort('date_debut', 'desc')
            ->recordActions([
                Actions\Action::make('activer')
                    ->label('Activer')
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->iconButton()
                    ->tooltip('Rendre cet exercice courant')
                    ->requiresConfirmation()
                    ->visible(fn (Exercice $record) => auth()->user()->can('activer_exercice')
                        && ! $record->en_cours
                        && ! $record->cloture)
                    ->modalHeading('Activer l\'exercice')
                    ->modalDescription('L\'exercice actuellement en cours pour ce village sera désactivé.')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->action(fn (Exercice $record) => $record->activer())
                    ->after(fn (Exercice $record) => JournalAudit::enregistrer(
                        'Activation exercice',
                        'SOCLE',
                        'Exercice',
                        $record->id,
                        ['libelle' => $record->libelle],
                    )),
                Actions\Action::make('cloturer')
                    ->label('Clôturer')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->iconButton()
                    ->tooltip('Clôturer définitivement')
                    ->requiresConfirmation()
                    ->visible(fn (Exercice $record) => auth()->user()->can('cloturer_exercice') && ! $record->cloture)
                    ->modalHeading('Clôturer l\'exercice')
                    ->modalDescription('La clôture est irréversible : plus aucune écriture ne pourra être rattachée à cet exercice.')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->action(function (Exercice $record, Actions\Action $action): void {
                        try {
                            $record->cloturer();
                        } catch (ExerciceNonCloturableException $refus) {
                            // Le refus vient du registre des verrous : une
                            // caisse encore ouverte, une campagne non
                            // validée. Il se dit à la coordination en
                            // toutes lettres, avec ce qu'il lui reste à
                            // faire — un message qui disparaît en trois
                            // secondes n'aiderait personne.
                            Notification::make()
                                ->title('Clôture refusée')
                                ->body($refus->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();

                            $action->halt();
                        }
                    })
                    // La trace ne s'écrit que si la clôture a eu lieu :
                    // journaliser une action refusée ferait mentir le
                    // journal d'audit.
                    ->after(fn (Exercice $record) => $record->cloture
                        ? JournalAudit::enregistrer(
                            'Clôture exercice',
                            'SOCLE',
                            'Exercice',
                            $record->id,
                            ['libelle' => $record->libelle],
                        )
                        : null),
                Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Modifier')
                    ->visible(fn (Exercice $record) => auth()->user()->can('modifier_exercice') && $record->estModifiable())
                    ->modalHeading('Modifier l\'exercice')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->after(fn (Exercice $record) => JournalAudit::enregistrer(
                        'Modification exercice',
                        'SOCLE',
                        'Exercice',
                        $record->id,
                        ['libelle' => $record->libelle],
                    )),
                Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Supprimer')
                    ->visible(fn (Exercice $record) => auth()->user()->can('supprimer_exercice') && $record->estModifiable())
                    ->modalHeading('Supprimer l\'exercice')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->before(fn (Exercice $record) => JournalAudit::enregistrer(
                        'Suppression exercice',
                        'SOCLE',
                        'Exercice',
                        $record->id,
                        ['libelle' => $record->libelle],
                    )),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Aucun exercice enregistré');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageExercices::route('/'),
        ];
    }
}
