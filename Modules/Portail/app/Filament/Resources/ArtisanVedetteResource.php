<?php

namespace Modules\Portail\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Artisanat\Models\Artisan;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Models\JournalAudit;
use Modules\Portail\Filament\Resources\ArtisanVedetteResource\Pages;
use Modules\Portail\Models\ArtisanVedette;

/**
 * Mises en avant d'artisans sur le portail.
 *
 * La liste ne propose que des artisans publiables. Une période close
 * s'éteint d'elle-même : rien à retirer, personne à qui y penser.
 */
class ArtisanVedetteResource extends Resource
{
    protected static ?string $model = ArtisanVedette::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-star';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::PORTAIL;

    protected static ?string $navigationLabel = 'Artisans vedettes';

    protected static ?string $modelLabel = 'Artisan vedette';

    protected static ?string $pluralModelLabel = 'Artisans vedettes';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_artisans_vedettes');
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->schema([
                Forms\Components\Select::make('artisan_id')
                    ->label('Artisan')
                    ->options(fn () => Artisan::query()
                        ->publiable()
                        ->orderBy('nom')
                        ->get()
                        ->mapWithKeys(fn (Artisan $artisan) => [$artisan->id => $artisan->identite])
                        ->all())
                    ->searchable()
                    ->required(),

                Grid::make(2)->schema([
                    Forms\Components\DatePicker::make('date_debut')
                        ->label('Début de la mise en avant')
                        ->default(now())
                        ->required(),
                    Forms\Components\DatePicker::make('date_fin')
                        ->label('Fin de la mise en avant')
                        ->after('date_debut'),
                ]),

                Forms\Components\Textarea::make('texte')
                    ->label('Texte de présentation')
                    ->placeholder('Quelques lignes sur son parcours et son savoir-faire')
                    ->rows(5)
                    ->required(),

                Forms\Components\TextInput::make('ordre_affichage')
                    ->label('Ordre d\'affichage')
                    ->placeholder('0')
                    ->integer()
                    ->default(0)
                    ->required(),

                Forms\Components\Hidden::make('cree_par')
                    ->default(fn () => auth()->id()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('artisan.nom')
                    ->label('Artisan')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('artisan.corpsMetier.libelle')
                    ->label('Corps de métier'),
                Tables\Columns\TextColumn::make('date_debut')
                    ->label('Du')
                    ->date('d/m/Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('date_fin')
                    ->label('Au')
                    ->date('d/m/Y')
                    ->placeholder('Sans terme')
                    ->sortable(),
                Tables\Columns\IconColumn::make('en_cours')
                    ->label('En cours')
                    ->boolean()
                    ->state(fn (ArtisanVedette $record) => $record->estEnCours()),
                Tables\Columns\TextColumn::make('ordre_affichage')
                    ->label('Ordre')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('date_debut', 'desc')
            ->recordActions([
                Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Modifier')
                    ->visible(fn () => auth()->user()->can('modifier_artisan_vedette'))
                    ->modalHeading('Modifier la mise en avant')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->using(function (ArtisanVedette $record, array $data): ArtisanVedette {
                        try {
                            $record->update($data);
                        } catch (\RuntimeException $e) {
                            Notification::make()
                                ->title('Mise en avant impossible')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }

                        return $record;
                    })
                    ->after(fn ($record) => JournalAudit::enregistrer(
                        'Modification artisan vedette',
                        'PORTAIL',
                        'ArtisanVedette',
                        $record->id,
                        ['artisan_id' => $record->artisan_id],
                    )),

                Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Retirer la mise en avant')
                    ->visible(fn () => auth()->user()->can('supprimer_artisan_vedette'))
                    ->modalHeading('Retirer la mise en avant')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->before(fn (ArtisanVedette $record) => JournalAudit::enregistrer(
                        'Suppression artisan vedette',
                        'PORTAIL',
                        'ArtisanVedette',
                        $record->id,
                        ['artisan_id' => $record->artisan_id],
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageArtisansVedettes::route('/'),
        ];
    }
}
