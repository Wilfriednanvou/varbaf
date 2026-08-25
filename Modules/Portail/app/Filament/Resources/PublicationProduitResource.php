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
use Illuminate\Database\Eloquent\Builder;
use Modules\Commerce\Models\Produit;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Models\JournalAudit;
use Modules\Portail\Filament\Resources\PublicationProduitResource\Pages;
use Modules\Portail\Models\PublicationProduit;

/**
 * Fiches portail des produits.
 *
 * La liste déroulante ne propose que des produits exposés dont
 * l'artisan autorise la publication : on ne met pas l'utilisateur en
 * situation de choisir ce que le modèle refusera. La garde du modèle
 * reste néanmoins la vraie barrière — un produit peut cesser d'être
 * éligible entre l'ouverture du formulaire et son envoi.
 */
class PublicationProduitResource extends Resource
{
    protected static ?string $model = PublicationProduit::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-globe-alt';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::PORTAIL;

    protected static ?string $navigationLabel = 'Publications de produits';

    protected static ?string $modelLabel = 'Publication de produit';

    protected static ?string $pluralModelLabel = 'Publications de produits';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_publications_produit');
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->schema([
                Forms\Components\Select::make('produit_id')
                    ->label('Produit')
                    ->options(fn () => Produit::query()
                        ->publiable()
                        ->orderBy('designation')
                        ->get()
                        ->mapWithKeys(fn (Produit $produit) => [
                            $produit->id => "{$produit->reference} — {$produit->designation}",
                        ])
                        ->all())
                    ->searchable()
                    ->required()
                    ->unique(ignoreRecord: true),

                Grid::make(2)->schema([
                    Forms\Components\FileUpload::make('photo')
                        ->label('Photo de mise en avant')
                        ->image()
                        ->disk('public')
                        ->directory('portail/produits'),

                    Forms\Components\TextInput::make('ordre_affichage')
                        ->label('Ordre d\'affichage')
                        ->placeholder('0')
                        ->integer()
                        ->default(0)
                        ->required(),
                ]),

                Forms\Components\Textarea::make('description_commerciale')
                    ->label('Description commerciale')
                    ->placeholder('Le texte que verront les visiteurs du site')
                    ->rows(5),

                Forms\Components\Toggle::make('publie')
                    ->label('Publié sur le site')
                    ->helperText('Une fiche naît non publiée. Retirer le produit de la vitrine le dépublie aussi, sans toucher à cette fiche.')
                    ->default(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('produit.reference')
                    ->label('Référence')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('produit.designation')
                    ->label('Produit')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('produit.artisan.nom')
                    ->label('Artisan')
                    ->searchable(),
                Tables\Columns\IconColumn::make('publie')
                    ->label('Publié')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('produit.statut_validation')
                    ->label('Statut produit')
                    ->badge(),
                Tables\Columns\TextColumn::make('ordre_affichage')
                    ->label('Ordre')
                    ->sortable(),
                Tables\Columns\TextColumn::make('date_publication')
                    ->label('Publiée le')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('publiePar.name')
                    ->label('Publiée par')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('ordre_affichage')
            ->filters([
                Tables\Filters\TernaryFilter::make('publie')
                    ->label('Publication'),
            ])
            ->recordActions([
                Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Modifier la fiche')
                    ->visible(fn () => auth()->user()->can('modifier_publication_produit'))
                    ->modalHeading('Modifier la fiche portail')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->using(fn (PublicationProduit $record, array $data) => static::enregistrer($record, $data))
                    ->after(fn ($record) => JournalAudit::enregistrer(
                        'Modification fiche portail',
                        'PORTAIL',
                        'PublicationProduit',
                        $record->id,
                        ['produit' => $record->produit?->reference, 'publie' => $record->publie],
                    )),

                Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Retirer la fiche')
                    ->visible(fn () => auth()->user()->can('supprimer_publication_produit'))
                    ->modalHeading('Retirer la fiche du portail')
                    ->modalDescription('Le produit lui-même n\'est pas touché : seule sa fiche de vitrine disparaît.')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->before(fn (PublicationProduit $record) => JournalAudit::enregistrer(
                        'Suppression fiche portail',
                        'PORTAIL',
                        'PublicationProduit',
                        $record->id,
                        ['produit' => $record->produit?->reference],
                    )),
            ]);
    }

    /**
     * Écriture commune à la création et à la modification.
     *
     * La garde du modèle lève une exception métier quand la publication
     * n'est pas permise ; l'écran la traduit en message lisible plutôt
     * que de laisser remonter une page d'erreur.
     *
     * @param  array<string, mixed>  $donnees
     */
    public static function enregistrer(?PublicationProduit $record, array $donnees): ?PublicationProduit
    {
        // Une trace se constate : qui publie, et quand.
        if (($donnees['publie'] ?? false) && ! ($record?->publie)) {
            $donnees['publie_par'] = auth()->id();
            $donnees['date_publication'] = now();
        }

        try {
            if ($record) {
                $record->update($donnees);

                return $record;
            }

            return PublicationProduit::create($donnees);
        } catch (\RuntimeException $e) {
            Notification::make()
                ->title('Publication impossible')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return $record;
        }
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['produit.artisan', 'publiePar']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManagePublicationsProduit::route('/'),
        ];
    }
}
