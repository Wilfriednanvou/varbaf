<?php

namespace Modules\Artisanat\Filament\Resources;

use Closure;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Artisanat\Enums\EtatEspaceLocatif;
use Modules\Artisanat\Enums\PeriodiciteRedevance;
use Modules\Artisanat\Enums\StatutAttribution;
use Modules\Artisanat\Filament\Resources\AttributionEspaceResource\Pages;
use Modules\Artisanat\Models\AttributionEspace;
use Modules\Artisanat\Models\EspaceLocatif;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\JournalAudit;
use Modules\Socle\Services\ContexteExercice;

/**
 * Attribution d'un espace locatif à un artisan.
 *
 * L'écran attribue un espace et non une boutique : plusieurs artisans
 * cohabitent dans un même local, et c'est l'espace qui se loue.
 *
 * La règle de non-chevauchement est appliquée deux fois, à dessein : le
 * modèle la fait respecter quoi qu'il arrive, y compris hors de
 * l'interface ; la règle de formulaire ci-dessous la rejoue avant
 * l'écriture pour que l'utilisateur voie un message sous le champ
 * « espace » au lieu d'une exception. Les deux appellent la même
 * méthode, il ne peut donc pas y avoir de divergence entre les deux
 * verdicts.
 */
class AttributionEspaceResource extends Resource
{
    protected static ?string $model = AttributionEspace::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-key';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::ARTISANAT;

    protected static ?string $navigationLabel = 'Attributions d\'espaces';

    protected static ?string $modelLabel = 'Attribution d\'espace';

    protected static ?string $pluralModelLabel = 'Attributions d\'espaces';

    protected static ?string $slug = 'attributions-espaces';

    protected static ?int $navigationSort = 7;

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_attributions');
    }

    /**
     * Même portée que les autres ressources déjà bornées à l'étape 3
     * du plan multi-exercice — voir DepotResource.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->when(
                app(ContexteExercice::class)->exerciceConsulte()?->getKey(),
                fn (Builder $requete, int $exerciceId) => $requete->where('exercice_id', $exerciceId),
            );
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

                $chevauchement = AttributionEspace::requeteChevauchement(
                    (int) $valeur,
                    $get('date_debut'),
                    $get('date_fin') ?: null,
                    $record?->getKey(),
                )->first();

                if ($chevauchement) {
                    $echouer(
                        'Cet espace porte déjà une attribution active sur la période '
                        .$chevauchement->libellePeriode().'.'
                    );
                }
            };
        };
    }

    /**
     * Le libellé d'un espace dans la liste déroulante, avec sa situation.
     *
     * **Dire avant, plutôt que refuser après.** La liste propose tout ce
     * qui n'est pas déclaré indisponible — soit, au parc du village,
     * trente-six espaces dont vingt-quatre sont occupés. L'agent en
     * choisissait un, remplissait le reste du formulaire, et se faisait
     * renvoyer par la règle de non-chevauchement : deux choix sur trois
     * menaient à un échec, et rien ne le laissait deviner avant.
     *
     * **Les occupés ne sont pas masqués pour autant.** Une attribution
     * peut légitimement commencer après la libération d'un espace, et
     * filtrer sur l'occupation du jour interdirait de préparer une
     * installation à venir. On informe donc, on n'interdit pas — la règle
     * de non-chevauchement reste le filet, à sa place.
     */
    protected static function libelleDEspace(EspaceLocatif $espace): string
    {
        $courante = $espace->attributionsActives
            ->first(fn (AttributionEspace $attribution): bool => $attribution->date_debut?->isToday()
                || ($attribution->date_debut?->isPast()
                    && (blank($attribution->date_fin) || ! $attribution->date_fin->isPast())));

        $identite = $espace->identite;

        if ($espace->boutique?->numero) {
            $identite .= ' ('.$espace->boutique->numero.')';
        }

        if (! $courante) {
            return $identite.' — libre';
        }

        $jusque = $courante->date_fin
            ? 'jusqu\'au '.$courante->date_fin->format('d/m/Y')
            : 'sans terme';

        return $identite.' — occupé par '.($courante->artisan?->nom_complet ?? 'un artisan').', '.$jusque;
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
                    // modification une attribution dont l'espace est
                    // devenu indisponible viderait le champ, et
                    // l'enregistrement écraserait la valeur.
                    Forms\Components\Select::make('espace_locatif_id')
                        ->label('Espace locatif')
                        ->relationship(
                            name: 'espaceLocatif',
                            titleAttribute: 'code',
                            // Les attributions en cours sont chargées avec
                            // les espaces : le libellé de chaque option les
                            // lit sans repartir en base, faute de quoi
                            // afficher l'occupant coûterait une requête par
                            // ligne de la liste déroulante.
                            modifyQueryUsing: fn (Builder $query, ?Model $record) => $query
                                ->with(['attributionsActives.artisan', 'boutique'])
                                ->where(
                                    fn (Builder $sousRequete) => $sousRequete
                                        ->where('etat', '!=', EtatEspaceLocatif::INDISPONIBLE->value)
                                        ->when(
                                            $record?->espace_locatif_id,
                                            fn (Builder $q, $identifiant) => $q->orWhere('espaces_locatifs.id', $identifiant),
                                        ),
                                ),
                        )
                        ->getOptionLabelFromRecordUsing(fn (EspaceLocatif $record) => self::libelleDEspace($record))
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->rules([self::regleNonChevauchement()]),
                ]),
                Grid::make(2)->schema([
                    // Le cas courant est une installation qui prend effet
                    // le jour où on la saisit. La date reste modifiable —
                    // une attribution peut être antidatée à la reprise
                    // d'un contrat, ou préparée pour plus tard.
                    Forms\Components\DatePicker::make('date_debut')
                        ->label('Date de début')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->default(fn () => now()->toDateString())
                        ->required(),
                    Forms\Components\DatePicker::make('date_fin')
                        ->label('Date de fin')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->placeholder('Laisser vide si sans terme')
                        ->afterOrEqual('date_debut'),
                ]),
                Grid::make(2)->schema([
                    // La redevance ne se calcule plus depuis une surface
                    // et un tarif : c'est un montant négocié espace par
                    // espace, puis figé sur le contrat. Les bornes du
                    // barème sont celles du modèle, qui les fait
                    // respecter en dehors de cet écran aussi.
                    Forms\Components\TextInput::make('redevance_convenue')
                        ->label('Redevance mensuelle convenue (FCFA)')
                        ->placeholder('Montant figé pour toute la durée du contrat')
                        ->numeric()
                        ->minValue(AttributionEspace::REDEVANCE_MINIMALE)
                        ->maxValue(AttributionEspace::REDEVANCE_MAXIMALE)
                        ->required(),
                    Forms\Components\Select::make('periodicite')
                        ->label('Périodicité de facturation')
                        ->options(PeriodiciteRedevance::options())
                        ->default(PeriodiciteRedevance::MENSUELLE->value)
                        ->native(false)
                        ->required(),
                ]),
                Grid::make(2)->schema([
                    // Même échappatoire que pour l'espace : un exercice
                    // clôturé après coup doit rester lisible sur les
                    // attributions qui s'y rattachent déjà.
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
                    //
                    // **Absent à la création.** Il y vaut toujours
                    // « Active » et ne peut pas valoir autre chose : la
                    // case n'apprend rien et occupe une place. Elle
                    // reprend son sens en modification, où l'attribution
                    // a une histoire.
                    Forms\Components\Select::make('statut')
                        ->label('Statut')
                        ->options(StatutAttribution::options())
                        ->default(StatutAttribution::ACTIVE->value)
                        ->disabled()
                        ->dehydrated(false)
                        ->visible(fn (?Model $record): bool => $record !== null),
                ]),
                // **Ces deux champs n'existent qu'après l'enregistrement.**
                // La date de facturation est calculée par le modèle — le
                // premier mois est offert — et le validateur du dossier
                // n'est connu qu'une fois la case cochée. À la création,
                // l'un est vide et l'autre affiche « Pas encore validé » :
                // deux cases pour zéro information, sur un formulaire
                // qu'on remplit debout au guichet.
                Grid::make(2)
                    ->visible(fn (?Model $record): bool => $record !== null)
                    ->schema([
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
                // Le droit de constater la complétude est distinct du
                // droit de modifier l'attribution : une trace ne vaut que
                // si le droit de la produire est nominatif. Un compte
                // sans cette permission ne voit pas la case, et le champ
                // n'étant alors pas déshydraté, la valeur déjà
                // enregistrée est préservée telle quelle.
                Forms\Components\Toggle::make('dossier_complet')
                    ->label('Dossier administratif complet')
                    ->default(false)
                    ->visible(fn () => auth()->user()->can('valider_dossier_attribution'))
                    ->helperText('Demande timbrée, attestation communale, images des œuvres, plan de localisation de l\'atelier et copie de la CNI. Cocher cette case vous enregistre comme validateur du dossier'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('espaceLocatif.code')
                    ->label('Espace')
                    ->badge()
                    ->description(fn (AttributionEspace $record) => $record->espaceLocatif?->boutique?->numero)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('artisan.matricule')
                    ->label('Matricule')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('artisan.nom')
                    ->label('Artisan')
                    ->description(fn (AttributionEspace $record) => $record->artisan?->corpsMetier?->libelle)
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
                    ->description(fn (AttributionEspace $record) => $record->periodicite?->getLabel())
                    ->sortable(),
                // **Un dossier incomplet n'est pas une erreur.** Rendu
                // par `IconColumn::boolean()`, `false` se peignait en
                // croix rouge : la colonne affichait vingt-quatre alertes
                // là où elle décrit un avancement normal — les pièces ne
                // sont pas encore réunies, ce qui est le cas de départ de
                // tout dossier. Même contresens de couleur que celui
                // évité dans les indicateurs : le rouge est réservé à ce
                // qui appelle une correction.
                Tables\Columns\TextColumn::make('dossier_complet')
                    ->label('Dossier')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state ? 'Complet' : 'À compléter')
                    ->color(fn ($state): string => $state ? 'success' : 'gray')
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
                Tables\Filters\SelectFilter::make('espace_locatif_id')
                    ->label('Espace')
                    ->relationship('espaceLocatif', 'code')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('date_debut', 'desc')
            ->recordActions([
                Actions\Action::make('resilier')
                    ->label('Résilier')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->iconButton()
                    ->tooltip('Résilier avant terme')
                    ->visible(fn (AttributionEspace $record) => auth()->user()->can('resilier_attribution')
                        && $record->statut === StatutAttribution::ACTIVE
                        && app(ContexteExercice::class)->estModifiable())
                    ->modalHeading('Résilier l\'attribution')
                    ->modalDescription('L\'espace sera libéré à la date du jour et pourra être réattribué.')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->schema([
                        Forms\Components\Textarea::make('motif_resiliation')
                            ->label('Motif de résiliation')
                            ->placeholder('Départ de l\'artisan, impayés, réaffectation de l\'espace')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(fn (AttributionEspace $record, array $data) => $record->resilier($data['motif_resiliation']))
                    ->after(fn (AttributionEspace $record) => JournalAudit::enregistrer(
                        'Résiliation attribution',
                        'ARTISANAT',
                        'AttributionEspace',
                        $record->id,
                        [
                            'espace' => $record->espaceLocatif?->code,
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
                    ->visible(fn (AttributionEspace $record) => auth()->user()->can('terminer_attribution')
                        && $record->statut === StatutAttribution::ACTIVE
                        && app(ContexteExercice::class)->estModifiable())
                    ->modalHeading('Terminer l\'attribution')
                    ->modalDescription('À utiliser lorsque le contrat arrive normalement à son terme, sans rupture.')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->action(fn (AttributionEspace $record) => $record->terminer())
                    ->after(fn (AttributionEspace $record) => JournalAudit::enregistrer(
                        'Fin d\'attribution',
                        'ARTISANAT',
                        'AttributionEspace',
                        $record->id,
                        ['espace' => $record->espaceLocatif?->code, 'artisan' => $record->artisan?->matricule],
                    )),
                Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Modifier')
                    ->visible(fn (AttributionEspace $record) => auth()->user()->can('modifier_attribution')
                        && $record->statut === StatutAttribution::ACTIVE
                        && app(ContexteExercice::class)->estModifiable())
                    ->modalHeading('Modifier l\'attribution')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->after(fn (AttributionEspace $record) => JournalAudit::enregistrer(
                        'Modification attribution',
                        'ARTISANAT',
                        'AttributionEspace',
                        $record->id,
                        [
                            'espace' => $record->espaceLocatif?->code,
                            'artisan' => $record->artisan?->matricule,
                            'periode' => $record->libellePeriode(),
                        ],
                    )),
                Actions\DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Supprimer')
                    ->visible(fn () => auth()->user()->can('supprimer_attribution')
                        && app(ContexteExercice::class)->estModifiable())
                    ->modalHeading('Supprimer l\'attribution')
                    ->modalDescription('Une attribution rompue se résilie, elle ne se supprime pas : la suppression efface l\'historique d\'occupation.')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->before(fn (AttributionEspace $record) => JournalAudit::enregistrer(
                        'Suppression attribution',
                        'ARTISANAT',
                        'AttributionEspace',
                        $record->id,
                        ['espace' => $record->espaceLocatif?->code, 'artisan' => $record->artisan?->matricule],
                    )),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Aucune attribution enregistrée');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageAttributionsEspaces::route('/'),
        ];
    }
}
