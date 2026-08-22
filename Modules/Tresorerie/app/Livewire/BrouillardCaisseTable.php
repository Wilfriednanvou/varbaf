<?php

namespace Modules\Tresorerie\Livewire;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Component;
use Modules\Tresorerie\Enums\NatureMouvementCaisse;
use Modules\Tresorerie\Enums\SensMouvementCaisse;
use Modules\Tresorerie\Models\MouvementCaisse;

/**
 * Onglet « Brouillard » : consultation seule du journal complet de la
 * section, ventes et mouvements manuels confondus, dans l'ordre
 * chronologique (RG-04).
 *
 * Aucune action d'en-tête, aucune action de ligne : la saisie et la
 * correction vivent dans les onglets Ventes et Mouvements de caisse.
 * Ce composant ne fait qu'afficher — il ne modifie jamais rien.
 */
class BrouillardCaisseTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public int $sectionId;

    public function render()
    {
        return view('tresorerie::livewire.brouillard-caisse-table');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                MouvementCaisse::query()->where('section_id', $this->sectionId)
            )
            ->columns([
                Tables\Columns\TextColumn::make('numero_ordre')
                    ->label('N°')
                    ->sortable(),
                Tables\Columns\TextColumn::make('date_operation')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('nature')
                    ->label('Nature')
                    ->badge(),
                Tables\Columns\TextColumn::make('libelle')
                    ->label('Libellé')
                    ->searchable()
                    ->limit(50),
                Tables\Columns\TextColumn::make('sens')
                    ->label('Sens')
                    ->badge(),
                Tables\Columns\TextColumn::make('montant')
                    ->label('Montant')
                    ->money('XAF')
                    ->sortable(),
                Tables\Columns\TextColumn::make('solde_apres')
                    ->label('Solde après')
                    ->money('XAF')
                    ->sortable(),
                Tables\Columns\TextColumn::make('piece_justificative')
                    ->label('Pièce')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('saisiPar.name')
                    ->label('Saisi par')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // Ordre chronologique : le brouillard se lit du premier au
            // dernier mouvement, pas l'inverse.
            ->defaultSort('numero_ordre', 'asc')
            ->filters([
                Tables\Filters\SelectFilter::make('nature')
                    ->label('Nature')
                    ->options(NatureMouvementCaisse::options()),
                Tables\Filters\SelectFilter::make('sens')
                    ->label('Sens')
                    ->options(SensMouvementCaisse::options()),
                Tables\Filters\Filter::make('jour')
                    ->label('Jour')
                    ->form([
                        Forms\Components\DatePicker::make('jour')
                            ->label('Jour'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['jour'] ?? null, fn ($q, $date) => $q->whereDate('date_operation', $date)))
                    ->indicateUsing(fn (array $data) => $data['jour'] ?? null
                        ? ['jour' => 'Jour : ' . \Illuminate\Support\Carbon::parse($data['jour'])->format('d/m/Y')]
                        : []),
                Tables\Filters\Filter::make('intervalle')
                    ->label('Intervalle')
                    ->form([
                        Forms\Components\DatePicker::make('du')
                            ->label('Du'),
                        Forms\Components\DatePicker::make('au')
                            ->label('Au'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['du'] ?? null, fn ($q, $date) => $q->whereDate('date_operation', '>=', $date))
                            ->when($data['au'] ?? null, fn ($q, $date) => $q->whereDate('date_operation', '<=', $date));
                    }),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Aucun mouvement enregistré')
            ->emptyStateDescription('Le brouillard de caisse est vide pour cette section.');
    }
}
