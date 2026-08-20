<?php

namespace Modules\Artisanat\Filament\Resources;

use Closure;
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
use Illuminate\Database\Eloquent\Model;
use Modules\Artisanat\Enums\EtatBoutique;
use Modules\Artisanat\Enums\PeriodiciteRedevance;
use Modules\Artisanat\Enums\StatutAttribution;
use Modules\Artisanat\Filament\Resources\AttributionBoutiqueResource\Pages;
use Modules\Artisanat\Models\AttributionBoutique;
use Modules\Artisanat\Models\Boutique;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\JournalAudit;

/**
 * Attribution d'une boutique à un artisan.
 *
 * La règle de non-chevauchement est appliquée deux fois, à dessein :
 * le modèle la fait respecter quoi qu'il arrive, y compris hors de
 * l'interface ; la règle de formulaire ci-dessous la rejoue avant
 * l'écriture pour que l'utilisateur voie un message sous le champ
 * « boutique » au lieu d'une exception. Les deux appellent la même
 * méthode, il ne peut donc pas y avoir de divergence entre les deux
 * verdicts.
 */
class AttributionBoutiqueResource extends Resource
{
    protected static ?string $model = AttributionBoutique::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-key';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::ARTISANAT;

    protected static ?string $navigationLabel = 'Attributions de boutiques';

    protected static ?string $modelLabel = 'Attribution de boutique';

    protected static ?string $pluralModelLabel = 'Attributions de boutiques';

    protected static ?string $slug = 'attributions-boutiques';

    protected static ?int $navigationSort = 6;

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_attributions');
    }

    /**
     * Règle de formulaire miroir du contrôle du modèle.
     */
    protected static function regleNonChevauchement(): Closure
    {
        return function (Get $get, ?Model $record): Closure {
            return function (string $attribut, mixed $valeur, Closure $echouer) use ($get, $record): void {
                if (blank($valeur) || blank($get('date_debut'))) {
                    return;
                }

                $chevauchement = AttributionBoutique::requeteChevauchement(
                    (int) $valeur,
                    $get('date_debut'),
                    $get('date_fin') ?: null,
                    $record?->getKey(),
                )->first();

                if ($chevauchement) {
                    $echouer(
                        'Cette boutique porte déjà une attribution active sur la période '
                        .$chevauchement->libellePeriode().'.'
                    );
                }
            };
        };
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->schema([
                Grid::make(2)->schema([
                    Forms\Components\Select::make('artisan_id')
                        ->label('Artisan')
                        ->relationship('artisan', 'nom', fn ($query) => $query->where('actif', true))
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->identite)
                        ->searchable(['matricule', 'nom', 'prenom'])
                        ->preload()
                        ->required(),
                    // Le filtre laisse toujours passer la valeur déjà
                    // enregistrée. Filament réutilise ce même
                    // modifyQueryUsing pour résoudre l'option
                    // sélectionnée : sans cette échappatoire, ouvrir en
                    // modification une attribution dont la boutique est
                    // devenue indisponible viderait le champ, et
                    // l'enregistrement écraserait la valeur.
                    Forms\Components\Select::make('boutique_id')
                        ->label('Boutique')
                        ->relationship(
                            name: 'boutique',
                            titleAttribute: 'numero',
                            modifyQueryUsing: fn (Builder $query, ?Model $record) => $query->where(
                                fn (Builder $sousRequete) => $sousRequete
                                    ->where('etat', '!=', EtatBoutique::INDISPONIBLE->value)
                                    ->when(
                                        $record?->boutique_id,
                                        fn (Builder $q, $identifiant) => $q->orWhere('boutiques.id', $identifiant),
                                    ),
                            ),
                        )
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        // Propose le barème de la boutique. Le montant
                        // reste modifiable, puis il est figé sur
                        // l'attribution : une révision ultérieure du
                        // barème ne touchera pas les contrats en cours.
                        ->afterStateUpdated(function (mixed $state, Set $set): void {
                            $boutique = filled($state) ? Boutique::find($state) : null;

                            if ($boutique?->redevance_mensuelle !== null) {
                                $set('redevance_convenue', (string) $boutique->redevance_mensuelle);
                            }
                        })
                        ->rules([self::regleNonChevauchement()]),
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
                        ->placeholder('Laisser vide si sans terme')
                        ->afterOrEqual('date_debut'),
                ]),
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('redevance_convenue')
                        ->label('Redevance convenue (FCFA)')
                        ->placeholder('Montant figé pour toute la durée du contrat')
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    Forms\Components\Select::make('periodicite')
                        ->label('Périodicité')
                        ->options(PeriodiciteRedevance::options())
                        ->default(PeriodiciteRedevance::MENSUELLE->value)
                        ->native(false)
                        ->required(),
                ]),
                Grid::make(2)->schema([
                    // Même échappatoire que pour la boutique : un
                    // exercice clôturé après coup doit rester lisible
                    // sur les attributions qui s'y rattachent déjà.
                    Forms\Components\Select::make('exercice_id')
                        ->label('Exercice')
                        ->relationship(
                            name: 'exercice',
                            titleAttribute: 'libelle',
                            modifyQueryUsing: fn (Builder $query, ?Model $record) => $query->where(
                                fn (Builder $sousRequete) => $sousRequete
                                    ->where('cloture', false)
                                    ->when(
                                        $record?->exercice_id,
                                        fn (Builder $q, $identifiant) => $q->orWhere('exercices.id', $identifiant),
                                    ),
                            ),
                        )
                        ->default(fn () => Exercice::courant()?->getKey())
                        ->searchable()
                        ->preload()
                        ->required(),
                    // Le statut ne se saisit pas : il évolue par les
                    // actions « Résilier » et « Terminer », qui portent
                    // chacune leur trace d'audit.
                    Forms\Components\Select::make('statut')
                        ->label('Statut')
                        ->options(StatutAttribution::options())
                        ->default(StatutAttribution::ACTIVE->value)
                        ->disabled()
                        ->dehydrated(false),
                ]),
                Grid::make(2)->schema([
                    // Le premier mois est offert : la date est calculée
                    // par le modèle et seulement montrée ici, pour que
                    // l'agent qui saisit le contrat voie tout de suite
                    // à partir de quand la boutique sera facturée.
                    Forms\Components\DatePicker::make('date_debut_facturation')
                        ->label('Début de facturation')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->disabled()
                        ->dehydrated(false),
                    Forms\Components\Placeholder::make('validee_par_affichage')
                        ->label('Dossier validé par')
                        ->content(fn (?Model $record) => $record?->valideePar?->name ?? 'Pas encore validé'),
                ]),
                Forms\Components\Toggle::make('dossier_complet')
                    ->label('Dossier administratif complet')
                    ->default(false)
                    ->helperText('Demande timbrée, attestation communale, images des œuvres, plan de localisation de l\'atelier et copie de la CNI. Cocher cette case vous enregistre comme validateur du dossier'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('boutique.numero')
                    ->label('Boutique')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('artisan.matricule')
                    ->label('Matricule')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('artisan.nom')
                    ->label('Artisan')
                    ->description(fn (AttributionBoutique $record) => $record->artisan?->corpsMetier?->libelle)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('date_debut')
                    ->label('Début')
                    ->date('d/m/Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('date_debut_facturation')
                    ->label('Début de facturation')
                    ->date('d/m/Y')
                    ->description('Premier mois offert')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('date_fin')
                    ->label('Fin')
                    ->date('d/m/Y')
                    ->placeholder('Sans terme')
                    ->sortable(),
                Tables\Columns\TextColumn::make('redevance_convenue')
                    ->label('Redevance')
                    ->money('XAF')
                    ->description(fn (AttributionBoutique $record) => $record->periodicite?->getLabel())
                    ->sortable(),
                Tables\Columns\IconColumn::make('dossier_complet')
                    ->label('Dossier')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('valideePar.name')
                    ->label('Validé par')
                    ->placeholder('Pas encore validé')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('statut')
                    ->label('Statut')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('exercice.libelle')
                    ->label('Exercice')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('motif_resiliation')
                    ->label('Motif de résiliation')
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créée le')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('statut')
                    ->label('Statut')
                    ->options(StatutAttribution::options()),
                Tables\Filters\SelectFilter::make('boutique_id')
                    ->label('Boutique')
                    ->relationship('boutique', 'numero')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('exercice_id')
                    ->label('Exercice')
                    ->relationship('exercice', 'libelle'),
            ])
            ->defaultSort('date_debut', 'desc')
            ->recordActions([
                Actions\Action::make('resilier')
                    ->label('Résilier')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->iconButton()
                    ->tooltip('Résilier avant terme')
                    ->visible(fn (AttributionBoutique $record) => auth()->user()->can('resilier_attribution')
                        && $record->statut === StatutAttribution::ACTIVE)
                    ->modalHeading('Résilier l\'attribution')
                    ->modalDescription('La boutique sera libérée à la date du jour et pourra être réattribuée.')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->schema([
                        Forms\Components\Textarea::make('motif_resiliation')
                            ->label('Motif de résiliation')
                            ->placeholder('Départ de l\'artisan, impayés, réaffectation du local')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(fn (AttributionBoutique $record, array $data) => $record->resilier($data['motif_resiliation']))
                    ->after(fn (AttributionBoutique $record) => JournalAudit::enregistrer(
                        'Résiliation attribution',
                        'ARTISANAT',
                        'AttributionBoutique',
                        $record->id,
                        [
                            'boutique' => $record->boutique?->numero,
                            'artisan' => $record->artisan?->matricule,
                            'motif' => $record->motif_resiliation,
                        ],
                    )),
                Actions\Action::make('terminer')
                    ->label('Terminer')
                    ->icon('heroicon-o-check-circle')
                    ->color('gray')
                    ->iconButton()
                    ->tooltip('Clore à l\'échéance')
                    ->requiresConfirmation()
                    ->visible(fn (AttributionBoutique $record) => auth()->user()->can('terminer_attribution')
                        && $record->statut === StatutAttribution::ACTIVE)
                    ->modalHeading('Terminer l\'attribution')
                    ->modalDescription('À utiliser lorsque le contrat arrive normalement à son terme, sans rupture.')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->action(fn (AttributionBoutique $record) => $record->terminer())
                    ->after(fn (AttributionBoutique $record) => JournalAudit::enregistrer(
                        'Fin d\'attribution',
                        'ARTISANAT',
                        'AttributionBoutique',
                        $record->id,
                        ['boutique' => $record->boutique?->numero, 'artisan' => $record->artisan?->matricule],
                    )),
                Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Modifier')
                    ->visible(fn (AttributionBoutique $record) => auth()->user()->can('modifier_attribution')
                        && $record->statut === StatutAttribution::ACTIVE)
                    ->modalHeading('Modifier l\'attribution')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->after(fn (AttributionBoutique $record) => JournalAudit::enregistrer(
                        'Modification attribution',
                        'ARTISANAT',
                        'AttributionBoutique',
                        $record->id,
                        [
                            'boutique' => $record->boutique?->numero,
                            'artisan' => $record->artisan?->matricule,
                            'periode' => $record->libellePeriode(),
                        ],
                    )),
                Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Supprimer')
                    ->visible(fn () => auth()->user()->can('supprimer_attribution'))
                    ->modalHeading('Supprimer l\'attribution')
                    ->modalDescription('Une attribution rompue se résilie, elle ne se supprime pas : la suppression efface l\'historique d\'occupation.')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->before(fn (AttributionBoutique $record) => JournalAudit::enregistrer(
                        'Suppression attribution',
                        'ARTISANAT',
                        'AttributionBoutique',
                        $record->id,
                        ['boutique' => $record->boutique?->numero, 'artisan' => $record->artisan?->matricule],
                    )),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Aucune attribution enregistrée');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageAttributionsBoutiques::route('/'),
        ];
    }
}
