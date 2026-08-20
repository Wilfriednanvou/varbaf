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
use Modules\Artisanat\Filament\Resources\ArtisanResource\Pages;
use Modules\Artisanat\Models\Artisan;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Enums\Sexe;
use Modules\Socle\Models\JournalAudit;

class ArtisanResource extends Resource
{
    protected static ?string $model = Artisan::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-user-group';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::ARTISANAT;

    protected static ?string $navigationLabel = 'Artisans';

    protected static ?string $modelLabel = 'Artisan';

    protected static ?string $pluralModelLabel = 'Artisans';

    protected static ?string $slug = 'artisans';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'nom';

    /**
     * Départements de la région de l'Ouest, proposés en suggestion.
     *
     * Volontairement une suggestion et non une liste fermée : le
     * village accueille aussi des artisans venus d'autres régions, et
     * un enum les rendrait impossibles à saisir.
     *
     * @return array<int, string>
     */
    public static function departementsSuggeres(): array
    {
        return [
            'Bamboutos', 'Haut-Nkam', 'Hauts-Plateaux', 'Koung-Khi',
            'Menoua', 'Mifi', 'Ndé', 'Noun',
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_artisans');
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->schema([
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('nom')
                        ->label('Nom')
                        ->placeholder('Nom de famille')
                        ->required()
                        ->maxLength(100),
                    Forms\Components\TextInput::make('prenom')
                        ->label('Prénom')
                        ->placeholder('Prénom')
                        ->maxLength(100),
                ]),
                Grid::make(2)->schema([
                    Forms\Components\Select::make('sexe')
                        ->label('Sexe')
                        ->options(Sexe::options())
                        ->native(false),
                    Forms\Components\Select::make('corps_metier_id')
                        ->label('Corps de métier')
                        ->relationship('corpsMetier', 'libelle')
                        ->searchable()
                        ->preload()
                        ->required(),
                ]),
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('telephone')
                        ->label('Téléphone')
                        ->tel()
                        ->placeholder('6XX XX XX XX')
                        ->maxLength(30),
                    Forms\Components\TextInput::make('email')
                        ->label('Adresse électronique')
                        ->email()
                        ->placeholder('prenom.nom@exemple.cm')
                        ->maxLength(255),
                ]),
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('adresse')
                        ->label('Adresse')
                        ->placeholder('Quartier, ville')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('departement_origine')
                        ->label('Département d\'origine')
                        ->placeholder('Mifi')
                        ->datalist(self::departementsSuggeres())
                        ->maxLength(60),
                ]),
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('numero_enregistrement')
                        ->label('Numéro d\'enregistrement communal')
                        ->placeholder('Numéro au répertoire de la commune')
                        ->maxLength(40),
                    Forms\Components\Select::make('entreprise_id')
                        ->label('Entreprise artisanale')
                        ->relationship('entreprise', 'raison_sociale')
                        ->searchable()
                        ->preload(),
                ]),
                Grid::make(2)->schema([
                    Forms\Components\Select::make('village_id')
                        ->label('Village')
                        ->relationship('village', 'nom')
                        ->searchable()
                        ->preload()
                        ->required(),
                    // Le disque est nommé explicitement. Sans lui,
                    // Filament retient config('filament.default_filesystem_disk'),
                    // qui vaut env('FILESYSTEM_DISK') — soit « local »,
                    // dont la racine est storage/app/private : la photo
                    // serait écrite hors de toute URL publique et ne
                    // s'afficherait jamais.
                    Forms\Components\FileUpload::make('photo')
                        ->label('Photo')
                        ->image()
                        ->imageEditor()
                        ->disk('public')
                        ->directory('artisans')
                        ->visibility('public')
                        ->maxSize(2048),
                ]),
                Forms\Components\Toggle::make('actif')
                    ->label('Artisan actif')
                    ->default(true)
                    ->helperText('Un artisan inactif n\'apparaîtra pas dans les listes de sélection des attributions et des ventes'),
                Forms\Components\Toggle::make('autorisation_publication')
                    ->label('Autorise la publication sur le portail public')
                    ->default(false)
                    ->helperText('Sans cette autorisation, ni le nom, ni la photo, ni les produits de l\'artisan ne paraîtront sur le site vitrine'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Même disque que le champ de dépôt : sans cette ligne
                // la colonne construirait l'URL sur le disque par
                // défaut et n'afficherait qu'une image cassée.
                Tables\Columns\ImageColumn::make('photo')
                    ->label('Photo')
                    ->disk('public')
                    ->circular()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('matricule')
                    ->label('Matricule')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->copyable(),
                Tables\Columns\TextColumn::make('nom')
                    ->label('Nom')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('prenom')
                    ->label('Prénom')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('corpsMetier.libelle')
                    ->label('Corps de métier')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('telephone')
                    ->label('Téléphone')
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('entreprise.raison_sociale')
                    ->label('Entreprise')
                    ->placeholder('En nom propre')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('departement_origine')
                    ->label('Département')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('numero_enregistrement')
                    ->label('N° d\'enregistrement')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('actif')
                    ->label('Actif')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\IconColumn::make('autorisation_publication')
                    ->label('Publication')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('village.nom')
                    ->label('Village')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('corps_metier_id')
                    ->label('Corps de métier')
                    ->relationship('corpsMetier', 'libelle')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('village_id')
                    ->label('Village')
                    ->relationship('village', 'nom'),
                Tables\Filters\SelectFilter::make('sexe')
                    ->label('Sexe')
                    ->options(Sexe::options()),
                Tables\Filters\TernaryFilter::make('actif')
                    ->label('Actif'),
                Tables\Filters\TernaryFilter::make('autorisation_publication')
                    ->label('Autorise la publication'),
            ])
            ->defaultSort('matricule')
            ->recordActions([
                Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Modifier')
                    ->visible(fn () => auth()->user()->can('modifier_artisan'))
                    ->modalHeading('Modifier l\'artisan')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->after(fn (Artisan $record) => JournalAudit::enregistrer(
                        'Modification artisan',
                        'ARTISANAT',
                        'Artisan',
                        $record->id,
                        ['matricule' => $record->matricule, 'nom' => $record->nom_complet],
                    )),
                Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Supprimer')
                    ->visible(fn () => auth()->user()->can('supprimer_artisan'))
                    ->modalHeading('Supprimer l\'artisan')
                    ->modalDescription('La suppression sera refusée si l\'artisan porte des attributions ou des ventes. Préférez la désactivation.')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->before(fn (Artisan $record) => JournalAudit::enregistrer(
                        'Suppression artisan',
                        'ARTISANAT',
                        'Artisan',
                        $record->id,
                        ['matricule' => $record->matricule, 'nom' => $record->nom_complet],
                    )),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Aucun artisan enregistré');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageArtisans::route('/'),
        ];
    }
}
