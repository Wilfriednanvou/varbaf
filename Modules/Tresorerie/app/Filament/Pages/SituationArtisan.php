<?php

namespace Modules\Tresorerie\Filament\Pages;

use Filament\Actions\Contracts\HasActions;
use Filament\Panel;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Computed;
use Modules\Artisanat\Models\Artisan;
use Modules\Commerce\Enums\EtatVente;
use Modules\Commerce\Models\Vente;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Tresorerie\Services\ServiceCompteArtisan;

/**
 * Situation financière d'un artisan (RG-15) : ventes validées, part due
 * par vente, cumul reversé, solde restant.
 *
 * Rien n'est stocké ici — `ServiceCompteArtisan` recalcule tout à
 * chaque affichage, ce qui garantit qu'annuler une vente met
 * immédiatement le solde à jour, sans étape de recalcul séparée.
 */
class SituationArtisan extends \Filament\Pages\Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithTable;

    protected string $view = 'tresorerie::pages.situation-artisan';

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-user-circle';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::TRESORERIE;

    protected static ?string $navigationLabel = 'Situation artisan';

    protected static ?string $slug = 'situation-artisan';

    protected static ?int $navigationSort = 5;

    public ?int $artisanId = null;

    public static function getRoutePath(Panel $panel): string
    {
        return '/situation-artisan/{artisan?}';
    }

    /**
     * `canAccess()` ne gouverne que la visibilité dans la navigation
     * (voir `ManageCaisseSession`) : le contrôle qui compte est
     * l'`abort_unless` de `mount()`.
     */
    public static function canAccess(): bool
    {
        return auth()->user()->can('consulter_situation_artisan');
    }

    public function mount(int|string|null $artisan = null): void
    {
        abort_unless(auth()->user()->can('consulter_situation_artisan'), 403);

        $this->artisanId = $artisan ? (int) $artisan : null;
    }

    public function getTitle(): string
    {
        return 'Situation artisan';
    }

    public function getHeading(): string
    {
        return 'Situation artisan';
    }

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Situation artisan',
        ];
    }

    #[Computed]
    public function artisan(): ?Artisan
    {
        return $this->artisanId ? Artisan::find($this->artisanId) : null;
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function artisans(): array
    {
        return Artisan::query()
            ->actif()
            ->orderBy('nom')
            ->get()
            ->mapWithKeys(fn (Artisan $a) => [$a->id => $a->identite])
            ->all();
    }

    #[Computed]
    public function totalVendu(): int
    {
        return $this->artisan ? app(ServiceCompteArtisan::class)->totalVendu($this->artisan) : 0;
    }

    #[Computed]
    public function totalReverse(): int
    {
        return $this->artisan ? app(ServiceCompteArtisan::class)->totalReverse($this->artisan) : 0;
    }

    #[Computed]
    public function soldeDu(): int
    {
        return $this->artisan ? app(ServiceCompteArtisan::class)->soldeDu($this->artisan) : 0;
    }

    /**
     * Choisir un autre artisan navigue vers son URL propre — même
     * principe que le sélecteur de section de `ManageCaisseSession` :
     * l'URL reste la source de vérité, un rafraîchissement la respecte.
     */
    public function updatedArtisanId(): void
    {
        if ($this->artisanId) {
            $this->redirect(static::getUrl(['artisan' => $this->artisanId]), navigate: true);
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Vente::query()
                    ->where('artisan_id', $this->artisanId ?? 0)
                    ->where('etat', EtatVente::VALIDEE->value)
            )
            ->columns([
                Tables\Columns\TextColumn::make('numero')
                    ->label('Ticket')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('date_vente')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('boutique.numero')
                    ->label('Boutique')
                    ->badge(),
                Tables\Columns\TextColumn::make('montant_total')
                    ->label('Montant vendu')
                    ->money('XAF')
                    ->sortable(),
                Tables\Columns\TextColumn::make('part_artisan')
                    ->label('Part due')
                    ->money('XAF')
                    ->weight('bold')
                    ->sortable(),
            ])
            ->defaultSort('date_vente', 'desc')
            ->emptyStateHeading('Aucune vente validée')
            ->emptyStateDescription('Choisissez un artisan pour consulter ses ventes et son solde.');
    }
}
