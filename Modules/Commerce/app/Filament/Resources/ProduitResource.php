<?php

namespace Modules\Commerce\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Modules\Artisanat\Models\Artisan;
use Modules\Commerce\Fiches\AnalyseurFicheTechnique;
use Modules\Commerce\Enums\StatutValidationProduit;
use Modules\Commerce\Filament\Resources\ProduitResource\Pages;
use Modules\Commerce\Models\Produit;
use Modules\Commerce\Services\ServiceMouvementStock;
use Modules\Commerce\Services\ServiceValidationProduit;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Models\JournalAudit;

/**
 * Catalogue des produits déposés.
 *
 * Le statut de validation n'est jamais saisi au formulaire : il évolue
 * par trois actions distinctes, chacune portant sa permission. C'est la
 * traduction de la règle 14 — seul le chef de section Production
 * valide, et la mise en vitrine relève de la Promotion.
 */
class ProduitResource extends Resource
{
    protected static ?string $model = Produit::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cube';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::COMMERCE;

    protected static ?string $navigationLabel = 'Produits';

    protected static ?string $modelLabel = 'Produit';

    protected static ?string $pluralModelLabel = 'Produits';

    protected static ?string $slug = 'produits';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'designation';

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_produits');
    }

    /**
     * Compteur des produits sous leur seuil d'alerte, affiché dans la
     * navigation.
     *
     * C'est la part de la règle 15 qui reste utile même si personne ne
     * consulte ses notifications : le chiffre est là, en permanence,
     * sur l'écran d'accueil du panneau.
     */
    public static function getNavigationBadge(): ?string
    {
        $sousLeSeuil = static::getModel()::query()->sousLeSeuil()->count();

        return $sousLeSeuil > 0 ? (string) $sousLeSeuil : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Produits dont le stock a atteint le seuil d\'alerte';
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->schema([
                // La fiche technique ouvre le formulaire : elle pré-remplit
                // ce qui suit. Placée en fin de schéma, elle écraserait ou
                // dupliquerait une saisie déjà faite.
                Forms\Components\FileUpload::make('fiche_technique')
                    ->label('Fiche technique de l\'artisan (Word)')
                    ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
                    ->disk('public')
                    ->directory('fiches-techniques')
                    ->visibility('public')
                    ->maxSize(8192)
                    ->hint('Le document est conservé ; les rubriques en sont extraites')
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                        $fichier = is_array($state) ? reset($state) : $state;

                        if (! $fichier instanceof TemporaryUploadedFile) {
                            return;
                        }

                        $fiche = (new AnalyseurFicheTechnique)->analyser($fichier->getRealPath());

                        $set('_signalements', $fiche->messages());
                        $set('_categorie_fiche', $fiche->categorieTexte);

                        if (! $fiche->estExploitable()) {
                            return;
                        }

                        // **Ne jamais écraser une saisie.** Chaque report
                        // est conditionné au champ vide : l'agent qui a
                        // déjà corrigé une désignation ne doit pas la voir
                        // disparaître parce qu'il rattache la fiche après
                        // coup. Le document propose, il ne décide pas.
                        $reprises = [];

                        if (blank($get('designation')) && filled($fiche->designation)) {
                            $set('designation', $fiche->designation);
                            $reprises[] = 'designation';
                        }

                        if (blank($get('description')) && filled($fiche->description)) {
                            $set('description', $fiche->description);
                            $reprises[] = 'description';
                        }

                        if (blank($get('caracteristiques'))) {
                            $set('caracteristiques', collect($fiche->pourStockage())
                                ->mapWithKeys(fn (array $rubrique): array => [(string) Str::uuid() => $rubrique])
                                ->all());
                            $reprises[] = 'caracteristiques';
                        }

                        if (blank($get('photo')) && $fiche->image !== null) {
                            $chemin = 'produits/'.Str::uuid().'.'.$fiche->extensionImage;
                            Storage::disk('public')->put($chemin, $fiche->image);
                            $set('photo', [(string) Str::uuid() => $chemin]);
                            $reprises[] = 'photo';
                        }

                        // Ni le prix ni la catégorie ni l'artisan ne sont
                        // reportés. Les trois relèvent d'un référentiel du
                        // village, qui fait autorité sur le document — et
                        // la fiche de l'huile de palmiste porte trois prix
                        // pour trois conditionnements (DT-13).
                        $set('_reprises', $reprises);
                    }),

                Forms\Components\Hidden::make('_reprises')->dehydrated(false),
                Forms\Components\Hidden::make('_signalements')->dehydrated(false),
                Forms\Components\Hidden::make('_categorie_fiche')->dehydrated(false),

                // Ce que la fiche n'a pas rempli se dit à l'écran.
                // Une analyse qui ne rend que ce qu'elle a compris laisse
                // croire qu'elle a tout compris — c'est la famille de
                // défauts recensée au journal du 27/08.
                Forms\Components\Placeholder::make('_signalements_affiches')
                    ->label('Ce que la fiche n\'a pas renseigné')
                    ->hidden(fn (Get $get): bool => blank($get('_signalements')))
                    ->content(fn (Get $get): string => '— '.implode("\n— ", (array) $get('_signalements'))),

                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('designation')
                        ->label('Désignation')
                        ->placeholder('Masque cérémoniel en bois d\'iroko')
                        ->required()
                        ->maxLength(255)
                        // Un champ pré-rempli doit se distinguer d'un champ
                        // saisi : sans cela, une valeur extraite d'un
                        // document rédigé par un tiers prend à l'écran le
                        // statut d'une valeur validée par le village.
                        ->hint(fn (Get $get): ?string => in_array('designation', (array) $get('_reprises'), true)
                            ? 'Repris de la fiche technique'
                            : null),
                    Forms\Components\Select::make('categorie_id')
                        ->label('Catégorie')
                        ->relationship('categorie', 'libelle')
                        ->searchable()
                        ->preload()
                        ->required()
                        // La fiche nomme sa catégorie avec les mots de
                        // l'artisan — « Huile végétale » — qui ne sont pas
                        // ceux de la nomenclature du village. Le
                        // rapprochement par nom est le problème qui a coûté
                        // le plus cher à la reprise du registre : il est
                        // affiché, pas appliqué. L'agent choisit.
                        ->hint(fn (Get $get): ?string => filled($get('_categorie_fiche'))
                            ? 'La fiche indique : '.$get('_categorie_fiche')
                            : null),
                ]),
                Forms\Components\Textarea::make('description')
                    ->label('Description')
                    ->placeholder('Matière, dimensions, technique, particularités de la pièce')
                    ->rows(3)
                    ->hint(fn (Get $get): ?string => in_array('description', (array) $get('_reprises'), true)
                        ? 'Repris de la fiche technique'
                        : null),

                /*
                 * Les rubriques de la fiche technique.
                 *
                 * **Pourquoi un répéteur et non des champs.** Les trois
                 * fiches réelles du village n'ont pas deux rubriques
                 * comparables : « Caractéristiques techniques » porte
                 * hauteur, diamètre et poids pour un tabouret, indice
                 * d'acide et point de fusion pour une huile. Le document
                 * décide de ses rubriques ; le formulaire les accueille
                 * telles quelles, et l'agent en ajoute ou en retire.
                 */
                Forms\Components\Repeater::make('caracteristiques')
                    ->label('Rubriques de la fiche technique')
                    ->schema([
                        Forms\Components\TextInput::make('rubrique')
                            ->label('Rubrique')
                            ->placeholder('Matières premières utilisées')
                            ->required()
                            ->maxLength(120),
                        Forms\Components\Textarea::make('contenu')
                            ->label('Contenu')
                            ->placeholder('Une ligne par élément, comme sur la fiche')
                            ->rows(3)
                            ->required(),
                    ])
                    ->itemLabel(fn (array $state): ?string => $state['rubrique'] ?? null)
                    ->addActionLabel('Ajouter une rubrique')
                    ->collapsed()
                    ->collapsible()
                    ->reorderable()
                    ->defaultItems(0)
                    ->hint(fn (Get $get): ?string => in_array('caracteristiques', (array) $get('_reprises'), true)
                        ? 'Reprises de la fiche technique'
                        : null),
                Grid::make(2)->schema([
                    // Choisir l'artisan renseigne la boutique : le
                    // produit est déposé là où son auteur est
                    // attributaire — c'est-à-dire dans la boutique qui
                    // abrite l'espace locatif qu'il occupe. La valeur
                    // reste modifiable — un produit peut être exposé
                    // ailleurs — mais le cas courant ne demande aucune
                    // saisie.
                    Forms\Components\Select::make('artisan_id')
                        ->label('Artisan')
                        ->relationship('artisan', 'nom', fn (Builder $query) => $query->where('actif', true))
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->identite)
                        ->searchable(['matricule', 'nom', 'prenom'])
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (mixed $state, Set $set): void {
                            $artisan = filled($state) ? Artisan::find($state) : null;
                            $boutique = $artisan?->getAttributionActive()?->espaceLocatif?->boutique_id;

                            if ($boutique) {
                                $set('boutique_id', $boutique);
                            }
                        }),
                    Forms\Components\Select::make('boutique_id')
                        ->label('Boutique')
                        ->relationship('boutique', 'numero')
                        ->searchable()
                        ->preload()
                        ->required()
                        // Verrouillée après création : la référence a
                        // été calculée depuis cette boutique et ne
                        // suivra pas un déménagement. Déplacer un
                        // produit relèvera d'une action dédiée, avec sa
                        // trace, quand le besoin se présentera.
                        ->disabled(fn (?Produit $record) => $record !== null)
                        ->dehydrated(fn (?Produit $record) => $record === null),
                ]),
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('prix_unitaire')
                        ->label('Prix unitaire (FCFA)')
                        ->placeholder('25000')
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    Forms\Components\TextInput::make('seuil_alerte')
                        ->label('Seuil d\'alerte de rupture')
                        ->placeholder('Laisser vide pour ne pas surveiller ce produit')
                        ->numeric()
                        ->minValue(0),
                ]),
                Forms\Components\FileUpload::make('photo')
                    ->label('Photo')
                    ->image()
                    ->imageEditor()
                    ->disk('public')
                    ->directory('produits')
                    ->visibility('public')
                    ->maxSize(2048)
                    // Les fiches du village portent une photographie
                    // intégrée, de 27 à 125 Ko. C'est le report le plus
                    // rentable de tous : les 285 produits repris du
                    // registre n'ont aucune photo, et le portail en vit.
                    ->hint(fn (Get $get): ?string => in_array('photo', (array) $get('_reprises'), true)
                        ? 'Reprise de la fiche technique'
                        : null),
                Forms\Components\Toggle::make('piece_unique')
                    ->label('Pièce unique')
                    ->default(false)
                    ->helperText('Œuvre non reproductible : une fois vendue, elle ne sera pas réapprovisionnée'),
                Forms\Components\Toggle::make('actif')
                    ->label('Produit actif')
                    ->default(true)
                    ->helperText('Un produit inactif disparaît de l\'écran de vente sans perdre son historique'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('photo')
                    ->label('Photo')
                    ->disk('public')
                    ->square()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('reference')
                    ->label('Référence')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->copyable(),
                Tables\Columns\TextColumn::make('designation')
                    ->label('Désignation')
                    ->description(fn (Produit $record) => $record->categorie?->libelle)
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('artisan.nom')
                    ->label('Artisan')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('boutique.numero')
                    ->label('Boutique')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('prix_unitaire')
                    ->label('Prix unitaire')
                    ->money('XAF')
                    ->sortable(),
                // Le stock est un cumul du journal : il se lit, il ne
                // se saisit pas. La pastille rouge signale que le
                // produit est retombé au niveau de son seuil.
                Tables\Columns\TextColumn::make('stock')
                    ->label('En stock')
                    ->state(fn (Produit $record) => $record->getQuantiteEnStock())
                    ->badge()
                    ->color(fn (Produit $record) => $record->estSousLeSeuil() ? 'danger' : 'gray')
                    ->description(fn (Produit $record) => $record->seuil_alerte !== null
                        ? "Seuil : {$record->seuil_alerte}"
                        : null),
                Tables\Columns\TextColumn::make('statut_validation')
                    ->label('Statut')
                    ->badge()
                    ->sortable(),
                Tables\Columns\IconColumn::make('piece_unique')
                    ->label('Pièce unique')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('seuil_alerte')
                    ->label('Seuil d\'alerte')
                    ->numeric()
                    ->placeholder('Non surveillé')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('actif')
                    ->label('Actif')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Déposé le')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('statut_validation')
                    ->label('Statut')
                    ->options(StatutValidationProduit::options()),
                Tables\Filters\SelectFilter::make('categorie_id')
                    ->label('Catégorie')
                    ->relationship('categorie', 'libelle')
                    ->searchable()
                    ->preload(),
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
                Tables\Filters\TernaryFilter::make('actif')
                    ->label('Actif'),
                Tables\Filters\Filter::make('sous_le_seuil')
                    ->label('Sous le seuil d\'alerte')
                    ->query(fn (Builder $query) => $query->sousLeSeuil()),
            ])
            ->defaultSort('reference')
            ->recordActions([
                // Le retrait passe par le service : c'est lui qui
                // refuse de sortir plus que le solde, pour la vente
                // comme pour la reprise par l'artisan.
                Actions\Action::make('retirer_du_stock')
                    ->label('Retirer du stock')
                    ->icon('heroicon-o-arrow-uturn-up')
                    ->color('warning')
                    ->iconButton()
                    ->tooltip('Reprise par l\'artisan')
                    ->visible(fn (Produit $record) => auth()->user()->can('retirer_stock')
                        && $record->getQuantiteEnStock() > 0)
                    ->modalHeading('Reprise d\'articles par l\'artisan')
                    ->modalDescription(fn (Produit $record) => "Stock actuellement en dépôt : {$record->getQuantiteEnStock()}. La reprise ne peut pas dépasser ce solde.")
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->schema([
                        Forms\Components\TextInput::make('quantite')
                            ->label('Quantité reprise')
                            ->placeholder('1')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        Forms\Components\Textarea::make('motif')
                            ->label('Motif')
                            ->placeholder('Reprise par l\'artisan, retour atelier, participation à une exposition')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(fn (Produit $record, array $data) => app(ServiceMouvementStock::class)
                        ->retirer($record, (int) $data['quantite'], $data['motif']))
                    ->after(fn (Produit $record) => JournalAudit::enregistrer(
                        'Retrait de stock',
                        'COMMERCE',
                        'Produit',
                        $record->id,
                        ['reference' => $record->reference, 'solde' => $record->getQuantiteEnStock()],
                    )),
                Actions\Action::make('valider')
                    ->label('Valider')
                    ->icon('heroicon-o-check-badge')
                    ->color('info')
                    ->iconButton()
                    ->tooltip('Valider le produit')
                    ->requiresConfirmation()
                    ->visible(fn (Produit $record) => auth()->user()->can('valider_produit')
                        && $record->statut_validation->peutDevenir(StatutValidationProduit::VALIDE))
                    ->modalHeading('Valider le produit')
                    ->modalDescription('Un produit validé devient vendable au comptoir. La mise en vitrine du portail public reste une étape distincte.')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->action(fn (Produit $record) => app(ServiceValidationProduit::class)->valider($record))
                    ->after(fn (Produit $record) => JournalAudit::enregistrer(
                        'Validation produit',
                        'COMMERCE',
                        'Produit',
                        $record->id,
                        ['reference' => $record->reference, 'designation' => $record->designation],
                    )),
                Actions\Action::make('exposer')
                    ->label('Exposer')
                    ->icon('heroicon-o-sparkles')
                    ->color('success')
                    ->iconButton()
                    ->tooltip('Mettre en vitrine')
                    ->requiresConfirmation()
                    ->visible(fn (Produit $record) => auth()->user()->can('exposer_produit')
                        && $record->statut_validation->peutDevenir(StatutValidationProduit::EXPOSE))
                    ->modalHeading('Mettre le produit en vitrine')
                    ->modalDescription('Le produit ne paraîtra sur le portail public que si l\'artisan a donné son autorisation de publication.')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->action(fn (Produit $record) => app(ServiceValidationProduit::class)->exposer($record))
                    ->after(fn (Produit $record) => JournalAudit::enregistrer(
                        'Mise en vitrine produit',
                        'COMMERCE',
                        'Produit',
                        $record->id,
                        ['reference' => $record->reference, 'publiable' => $record->estPubliable()],
                    )),
                Actions\Action::make('retirer')
                    ->label('Retirer')
                    ->icon('heroicon-o-archive-box-x-mark')
                    ->color('danger')
                    ->iconButton()
                    ->tooltip('Retirer de la circulation')
                    ->requiresConfirmation()
                    ->visible(fn (Produit $record) => auth()->user()->can('retirer_produit')
                        && $record->statut_validation->peutDevenir(StatutValidationProduit::RETIRE))
                    ->modalHeading('Retirer le produit')
                    ->modalDescription('Le produit cesse d\'être vendable et publiable. Son historique et sa référence sont conservés.')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->action(fn (Produit $record) => app(ServiceValidationProduit::class)->retirer($record))
                    ->after(fn (Produit $record) => JournalAudit::enregistrer(
                        'Retrait produit',
                        'COMMERCE',
                        'Produit',
                        $record->id,
                        ['reference' => $record->reference, 'designation' => $record->designation],
                    )),
                Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Modifier')
                    ->visible(fn () => auth()->user()->can('modifier_produit'))
                    ->modalHeading('Modifier le produit')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->after(fn (Produit $record) => JournalAudit::enregistrer(
                        'Modification produit',
                        'COMMERCE',
                        'Produit',
                        $record->id,
                        ['reference' => $record->reference, 'designation' => $record->designation],
                    )),
                Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Supprimer')
                    ->visible(fn () => auth()->user()->can('supprimer_produit'))
                    ->modalHeading('Supprimer le produit')
                    ->modalDescription('Un produit déjà vendu ne se supprime pas : retirez-le de la circulation pour préserver l\'historique.')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->before(fn (Produit $record) => JournalAudit::enregistrer(
                        'Suppression produit',
                        'COMMERCE',
                        'Produit',
                        $record->id,
                        ['reference' => $record->reference, 'designation' => $record->designation],
                    )),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Aucun produit déposé')
            ->emptyStateDescription('Les produits sont déposés par les artisans, puis validés par la section Production avant d\'être vendables.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageProduits::route('/'),
        ];
    }
}
