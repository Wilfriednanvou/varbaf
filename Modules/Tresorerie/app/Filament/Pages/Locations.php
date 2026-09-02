<?php

namespace Modules\Tresorerie\Filament\Pages;

use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Artisanat\Enums\StatutAttribution;
use Modules\Artisanat\Models\AttributionEspace;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Services\ContexteExercice;
use Modules\Tresorerie\Services\ServiceLocations;

/**
 * Les locations du parc, vues depuis la trésorerie.
 *
 * **La forme de cet écran est celle de l'état de recouvrement du
 * village**, transcrit dans `docs/donnees/parc-locatif.csv` : un espace,
 * son occupant, son métier, la redevance convenue, le dû, l'encaissé, le
 * reste. La coordination tient déjà ses comptes ainsi ; reproduire sa
 * mise en forme évite de lui demander de traduire.
 *
 * **Rien n'est saisi ici.** Le dû se dérive des attributions et de la
 * règle 13, l'encaissé se somme depuis le brouillard. Un montant qui
 * s'affiche mais ne se saisit pas ne peut pas diverger de sa source —
 * c'est le même parti que le solde artisan (RG-15) et que le stock
 * (règle 3).
 *
 * La dépendance descend : la Trésorerie est le module 4, l'Artisanat le
 * module 2. Lire les attributions est permis ; l'inverse ne le serait
 * pas.
 */
class Locations extends \Filament\Pages\Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithTable;

    protected string $view = 'tresorerie::pages.locations';

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-building-storefront';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::TRESORERIE;

    protected static ?string $navigationLabel = 'Locations';

    protected static ?string $slug = 'locations';

    protected static ?int $navigationSort = 6;

    /**
     * `canAccess()` ne gouverne que la visibilité dans la navigation :
     * le contrôle qui compte est l'`abort_unless` de `mount()`.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can('lister_attributions') ?? false;
    }

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('lister_attributions'), 403);
    }

    public function getTitle(): string
    {
        return 'Locations et redevances';
    }

    public function getHeading(): string
    {
        return 'Locations et redevances';
    }

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Locations',
        ];
    }

    /**
     * @return array{attributions: int, mensuel: int, du: int, encaisse: int, reste: int, a_jour: int}
     */
    public function totaux(): array
    {
        return app(ServiceLocations::class)->totaux(exerciceId: $this->exerciceConsulteId());
    }

    /**
     * @return array{nombre: int, montant: int}
     */
    public function orphelins(): array
    {
        return app(ServiceLocations::class)->encaissementsNonRattaches();
    }

    /**
     * L'état par attribution, indexé pour la table.
     *
     * @return array<int, object>
     */
    public function etat(): array
    {
        return app(ServiceLocations::class)->etatDuParc(exerciceId: $this->exerciceConsulteId())->keyBy('id')->all();
    }

    /**
     * L'exercice affiché par le sélecteur global de la barre
     * supérieure — jamais forcément l'actif, voir `ContexteExercice`.
     */
    protected function exerciceConsulteId(): ?int
    {
        return app(ContexteExercice::class)->exerciceConsulte()?->getKey();
    }

    public function table(Table $table): Table
    {
        $etat = $this->etat();

        return $table
            ->query(
                AttributionEspace::query()
                    ->with(['espaceLocatif.boutique', 'artisan.corpsMetier'])
                    ->where('statut', StatutAttribution::ACTIVE->value)
                    ->when(
                        $this->exerciceConsulteId(),
                        fn (Builder $requete, int $exerciceId) => $requete->where('exercice_id', $exerciceId),
                    )
            )
            ->defaultSort('espaceLocatif.code')
            ->columns([
                Tables\Columns\TextColumn::make('espaceLocatif.code')
                    ->label('Espace')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('espaceLocatif.boutique.numero')
                    ->label('Local')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('artisan.identite')
                    ->label('Occupant')
                    ->searchable(['artisans.nom', 'artisans.prenom'])
                    ->wrap(),

                Tables\Columns\TextColumn::make('artisan.corpsMetier.libelle')
                    ->label('Métier')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('redevance_convenue')
                    ->label('Mensualité')
                    ->numeric(thousandsSeparator: ' ')
                    ->suffix(' F')
                    ->sortable()
                    ->alignEnd(),

                // Le nombre de mensualités échues explique la colonne
                // suivante. Sans lui, un dû de 144 000 F sur une
                // redevance de 12 000 F reste une affirmation ; avec lui,
                // il se vérifie de tête.
                Tables\Columns\TextColumn::make('mois')
                    ->label('Mois dus')
                    ->state(fn (AttributionEspace $ligne) => $etat[$ligne->getKey()]->mois_dus ?? 0)
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('du')
                    ->label('Dû')
                    ->state(fn (AttributionEspace $ligne) => $etat[$ligne->getKey()]->du ?? 0)
                    ->numeric(thousandsSeparator: ' ')
                    ->suffix(' F')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('encaisse')
                    ->label('Encaissé')
                    ->state(fn (AttributionEspace $ligne) => $etat[$ligne->getKey()]->encaisse ?? 0)
                    ->numeric(thousandsSeparator: ' ')
                    ->suffix(' F')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('reste')
                    ->label('Reste')
                    ->state(fn (AttributionEspace $ligne) => $etat[$ligne->getKey()]->reste ?? 0)
                    ->numeric(thousandsSeparator: ' ')
                    ->suffix(' F')
                    ->weight('bold')
                    ->color(fn (AttributionEspace $ligne) => ($etat[$ligne->getKey()]->reste ?? 0) > 0 ? 'danger' : 'success')
                    ->alignEnd(),

                Tables\Columns\IconColumn::make('a_jour')
                    ->label('À jour')
                    ->state(fn (AttributionEspace $ligne) => $etat[$ligne->getKey()]->a_jour ?? false)
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('solde')
                    ->label('Redevances')
                    ->placeholder('Toutes')
                    ->trueLabel('Reste à percevoir')
                    ->falseLabel('À jour')
                    // Le filtre porte sur une valeur dérivée, que SQL ne
                    // connaît pas : les identifiants sont calculés en
                    // amont, puis la requête se restreint à eux. Sur
                    // vingt-quatre attributions, c'est sans effet
                    // mesurable — et c'est honnête, là où un `whereRaw`
                    // recopierait la formule du dû dans une seconde
                    // définition qui finirait par diverger.
                    ->queries(
                        true: fn (Builder $requete) => $requete->whereIn(
                            'attributions_espaces.id',
                            collect($etat)->where('a_jour', false)->keys()->all() ?: [0],
                        ),
                        false: fn (Builder $requete) => $requete->whereIn(
                            'attributions_espaces.id',
                            collect($etat)->where('a_jour', true)->keys()->all() ?: [0],
                        ),
                    ),
            ])
            ->paginated([25, 50, 'all'])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('Aucune attribution en cours')
            ->emptyStateDescription('Le parc ne porte aucune location active pour l\'exercice.');
    }
}
