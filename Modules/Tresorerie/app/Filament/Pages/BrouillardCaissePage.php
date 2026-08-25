<?php

namespace Modules\Tresorerie\Filament\Pages;

use Filament\Actions;
use Filament\Forms;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Modules\Tresorerie\Enums\NatureMouvementCaisse;
use Modules\Tresorerie\Enums\SensMouvementCaisse;
use Modules\Tresorerie\Models\Caisse;
use Modules\Tresorerie\Models\MouvementCaisse;
use Modules\Tresorerie\Models\SectionCaisse;
use Modules\Socle\Models\JournalAudit;

class BrouillardCaissePage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Brouillard de caisse';

    protected string $view = 'tresorerie::pages.brouillard-caisse-page';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'caisses-brouillard';

    public ?Caisse $caisseRecord = null;
    public ?SectionCaisse $sectionRecord = null;

    #[Url]
    public ?string $date_debut = null;

    #[Url]
    public ?string $date_fin = null;

    public static function getRoutePath(Panel $panel): string
    {
        return '/caisses/{caisse}/brouillard/{section}';
    }

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_mouvements_caisse');
    }

    public function mount(int|string $caisse, int|string $section): void
    {
        abort_unless(auth()->user()->can('lister_mouvements_caisse'), 403);

        $this->caisseRecord = Caisse::findOrFail((int) $caisse);
        $this->sectionRecord = SectionCaisse::where('caisse_id', $this->caisseRecord->id)
            ->findOrFail((int) $section);

        JournalAudit::enregistrer(
            'Consultation brouillard de caisse',
            'TRESORERIE',
            'SectionCaisse',
            $this->sectionRecord->id,
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                MouvementCaisse::query()
                    ->where('section_id', $this->sectionRecord->id)
                    ->when($this->date_debut, fn (Builder $query, $date) => $query->whereDate('date_mouvement', '>=', $date))
                    ->when($this->date_fin, fn (Builder $query, $date) => $query->whereDate('date_mouvement', '<=', $date))
            )
            ->columns([
                Tables\Columns\TextColumn::make('numero_ordre')
                    ->label('#')
                    ->sortable(),
                Tables\Columns\TextColumn::make('date_operation')
                    ->label('DATE')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('piece_justificative')
                    ->label('N° REÇU')
                    ->placeholder('—')
                    ->searchable(),
                Tables\Columns\TextColumn::make('libelle')
                    ->label('LIBELLÉ')
                    ->searchable(),
                Tables\Columns\TextColumn::make('nature')
                    ->label('TYPE')
                    ->formatStateUsing(fn ($record) => $record->nature?->getLabel() ?? $record->sens?->getLabel())
                    ->badge()
                    ->color(fn ($record) => $record->sens === SensMouvementCaisse::ENTREE ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('montant_entree')
                    ->label('ENTRÉE')
                    ->state(fn ($record) => $record->sens === SensMouvementCaisse::ENTREE ? $record->montant : null)
                    ->money('XAF')
                    ->color('success')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('montant_sortie')
                    ->label('SORTIE')
                    ->state(fn ($record) => $record->sens === SensMouvementCaisse::SORTIE ? $record->montant : null)
                    ->money('XAF')
                    ->color('danger')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('solde_courant')
                    ->label('SOLDE APRÈS')
                    ->money('XAF')
                    ->weight('bold'),
            ])
            ->defaultSort('numero_ordre', 'asc')
            ->emptyStateHeading('Aucun mouvement enregistré')
            ->emptyStateDescription('Le brouillard de caisse est vide pour cette section et cette période.')
            ->paginated(false); // Souvent on veut voir tout le brouillard d'un coup, ou bien on le pagine, laissons par défaut ou désactivé
    }

    public function getTotalEntreesProperty(): int
    {
        return MouvementCaisse::query()
            ->where('section_id', $this->sectionRecord?->id)
            ->where('sens', SensMouvementCaisse::ENTREE)
            ->when($this->date_debut, fn (Builder $query, $date) => $query->whereDate('date_operation', '>=', $date))
            ->when($this->date_fin, fn (Builder $query, $date) => $query->whereDate('date_operation', '<=', $date))
            ->sum('montant');
    }

    public function getTotalSortiesProperty(): int
    {
        return MouvementCaisse::query()
            ->where('section_id', $this->sectionRecord?->id)
            ->where('sens', SensMouvementCaisse::SORTIE)
            ->when($this->date_debut, fn (Builder $query, $date) => $query->whereDate('date_operation', '>=', $date))
            ->when($this->date_fin, fn (Builder $query, $date) => $query->whereDate('date_operation', '<=', $date))
            ->sum('montant');
    }

    public function filter(): void
    {
        // Simply triggers a re-render so the computed properties and the table update
    }
}
