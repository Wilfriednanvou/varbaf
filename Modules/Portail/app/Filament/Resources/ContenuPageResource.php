<?php

namespace Modules\Portail\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Models\JournalAudit;
use Modules\Portail\Filament\Resources\ContenuPageResource\Pages;
use Modules\Portail\Models\ContenuPage;

/**
 * Textes de présentation du portail.
 *
 * C'est un référentiel de libellés, pas un enregistrement porteur
 * d'histoire : au sens de la règle de suppression de CLAUDE.md, il est
 * corrigible et supprimable par qui peut le créer. Un texte qu'on veut
 * seulement retirer de la vitrine se désactive.
 */
class ContenuPageResource extends Resource
{
    protected static ?string $model = ContenuPage::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-text';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::PORTAIL;

    protected static ?string $navigationLabel = 'Contenus de page';

    protected static ?string $modelLabel = 'Contenu de page';

    protected static ?string $pluralModelLabel = 'Contenus de page';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_contenus_page');
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->schema([
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('cle')
                        ->label('Clé')
                        ->placeholder('village.presentation')
                        ->required()
                        ->maxLength(60)
                        ->unique(ignoreRecord: true),

                    Forms\Components\TextInput::make('ordre_affichage')
                        ->label('Ordre d\'affichage')
                        ->placeholder('0')
                        ->integer()
                        ->default(0)
                        ->required(),
                ]),

                Forms\Components\TextInput::make('titre')
                    ->label('Titre')
                    ->placeholder('Le Village Artisanal')
                    ->required(),

                Forms\Components\Textarea::make('corps')
                    ->label('Texte')
                    ->placeholder('Le texte affiché sur le site public')
                    ->rows(8)
                    ->required(),

                Forms\Components\Toggle::make('actif')
                    ->label('Affiché sur le site')
                    ->helperText('Désactiver retire le texte du site sans l\'effacer.')
                    ->default(true),

                Forms\Components\Hidden::make('modifie_par')
                    ->default(fn () => auth()->id()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('cle')
                    ->label('Clé')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('titre')
                    ->label('Titre')
                    ->searchable(),
                Tables\Columns\IconColumn::make('actif')
                    ->label('Affiché')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('ordre_affichage')
                    ->label('Ordre')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('modifiePar.name')
                    ->label('Modifié par')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Modifié le')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('cle')
            ->recordActions([
                Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Modifier')
                    ->visible(fn () => auth()->user()->can('modifier_contenu_page'))
                    ->modalHeading('Modifier le contenu')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->after(fn ($record) => JournalAudit::enregistrer(
                        'Modification contenu de page',
                        'PORTAIL',
                        'ContenuPage',
                        $record->id,
                        ['cle' => $record->cle],
                    )),

                Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Supprimer')
                    ->visible(fn () => auth()->user()->can('supprimer_contenu_page'))
                    ->modalHeading('Supprimer le contenu')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->before(fn (ContenuPage $record) => JournalAudit::enregistrer(
                        'Suppression contenu de page',
                        'PORTAIL',
                        'ContenuPage',
                        $record->id,
                        ['cle' => $record->cle],
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageContenusPage::route('/'),
        ];
    }
}
