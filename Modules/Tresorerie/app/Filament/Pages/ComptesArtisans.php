<?php

namespace Modules\Tresorerie\Filament\Pages;

use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Modules\Commerce\Models\TauxCommission;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Tresorerie\Services\ServiceCompteArtisan;

/**
 * Ce que le village doit à chacun de ses artisans, tous ensemble.
 *
 * **L'écran qu'on regarde avant de lancer une campagne.** `SituationArtisan`
 * répond à « où en est celui-ci ? » ; celui-ci répond à « combien la
 * prochaine campagne va-t-elle coûter, et à qui ? ». Les deux lisent le
 * même service et la même règle — RG-15, solde dû = somme des parts
 * artisan moins somme des reversements — mais une décision de
 * décaissement ne se prend pas artisan par artisan.
 *
 * **Aucun montant n'est stocké ici**, et l'écran ne décide rien : c'est
 * la campagne de reversement qui engage l'argent, avec sa validation
 * réservée à un profil distinct de l'agent de saisie (RG-23). Ce tableau
 * informe ; il ne paie pas.
 *
 * Le taux de commission affiché est celui **en vigueur aujourd'hui**, et
 * il est présenté comme tel. Il ne sert pas à recalculer les montants :
 * chaque vente porte le taux figé à sa date (règle 10), et c'est ce taux
 * figé qui a produit la part artisan. Afficher le taux courant à côté de
 * cumuls historiques serait trompeur si on laissait croire qu'il les
 * explique — d'où la mention de sa date d'effet.
 */
class ComptesArtisans extends \Filament\Pages\Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithTable;

    protected string $view = 'tresorerie::pages.comptes-artisans';

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-users';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::TRESORERIE;

    protected static ?string $navigationLabel = 'Comptes artisans';

    protected static ?string $slug = 'comptes-artisans';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('consulter_situation_artisan') ?? false;
    }

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('consulter_situation_artisan'), 403);
    }

    public function getTitle(): string
    {
        return 'Comptes artisans';
    }

    public function getHeading(): string
    {
        return 'Comptes artisans';
    }

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Comptes artisans',
        ];
    }

    /**
     * @return array{artisans: int, crediteurs: int, vendu: int, commission: int, part: int, reverse: int, du: int}
     */
    public function totaux(): array
    {
        $comptes = app(ServiceCompteArtisan::class)->comptesDeTousLesArtisans();

        return [
            'artisans' => $comptes->count(),
            // Ceux à qui le village doit quelque chose : c'est le nombre
            // de décaissements que la prochaine campagne produira.
            'crediteurs' => $comptes->where('solde_du', '>', 0)->count(),
            'vendu' => (int) $comptes->sum('montant_vendu'),
            'commission' => (int) $comptes->sum('commission'),
            'part' => (int) $comptes->sum('part_artisan'),
            'reverse' => (int) $comptes->sum('total_reverse'),
            'du' => (int) $comptes->sum('solde_du'),
        ];
    }

    public function tauxEnVigueur(): ?TauxCommission
    {
        return TauxCommission::query()
            ->enVigueurAu(Carbon::today())
            ->first();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): \Illuminate\Support\Collection => app(ServiceCompteArtisan::class)
                ->comptesDeTousLesArtisans()
                ->map(fn (object $c): array => (array) $c)
                ->keyBy('id'))
            ->columns([
                Tables\Columns\TextColumn::make('matricule')
                    ->label('Matricule')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('nom')
                    ->label('Artisan')
                    ->formatStateUsing(fn ($state, array $record): string => trim($record['nom'].' '.($record['prenom'] ?? '')))
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('nombre_ventes')
                    ->label('Ventes')
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('montant_vendu')
                    ->label('Vendu')
                    ->numeric(thousandsSeparator: ' ')
                    ->suffix(' F')
                    ->sortable()
                    ->alignEnd(),

                // La commission du village, à côté de la part de
                // l'artisan : les deux se lisent ensemble ou ne se
                // lisent pas. C'est la ligne qui rend le taux tangible.
                Tables\Columns\TextColumn::make('commission')
                    ->label('Commission village')
                    ->numeric(thousandsSeparator: ' ')
                    ->suffix(' F')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('part_artisan')
                    ->label('Part artisan')
                    ->numeric(thousandsSeparator: ' ')
                    ->suffix(' F')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('total_reverse')
                    ->label('Déjà reversé')
                    ->numeric(thousandsSeparator: ' ')
                    ->suffix(' F')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('solde_du')
                    ->label('Solde dû')
                    ->numeric(thousandsSeparator: ' ')
                    ->suffix(' F')
                    ->weight('bold')
                    ->color(fn ($state): string => (int) $state > 0 ? 'danger' : 'gray')
                    ->sortable()
                    ->alignEnd(),
            ])
            ->defaultSort('solde_du', 'desc')
            ->paginated([25, 50, 'all'])
            ->emptyStateHeading('Aucun artisan')
            ->emptyStateDescription('Le registre ne porte encore aucun artisan.');
    }
}
