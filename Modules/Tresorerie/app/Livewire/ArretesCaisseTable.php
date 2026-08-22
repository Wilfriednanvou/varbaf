<?php

namespace Modules\Tresorerie\Livewire;

use Filament\Actions;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Socle\Models\JournalAudit;
use Modules\Tresorerie\Livewire\Concerns\VerifieSectionOuverte;
use Modules\Tresorerie\Models\ArreteCaisse;
use Modules\Tresorerie\Services\ServiceArreteCaisse;

/**
 * Onglet « Arrêtés » : contrôle physique quotidien de la caisse
 * (RG-25 à RG-27).
 *
 * Le solde théorique est calculé et affiché avant même la saisie — le
 * caissier voit l'écart se former pendant qu'il compte, pas après coup.
 * L'écart lui-même n'est jamais saisi : `ServiceArreteCaisse` le déduit,
 * et le modèle refuse l'enregistrement si un écart non nul n'est pas
 * commenté (RG-26).
 *
 * **Défense côté serveur.** Comme les autres onglets, `creerArrete()`
 * est le point d'entrée réel — appelable hors de toute action Filament,
 * et revérifie l'état de la section avant d'écrire.
 */
class ArretesCaisseTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use VerifieSectionOuverte;

    /**
     * `#[Locked]` : une propriété publique Livewire non verrouillée est
     * réinscriptible depuis le navigateur. Un arrêté est un constat daté
     * et immuable — la caisse qu'il constate ne se choisit pas côté
     * client.
     */
    #[Locked]
    public int $sectionId;

    public function render()
    {
        return view('tresorerie::livewire.arretes-caisse-table');
    }

    protected function soldeTheoriqueAffiche(?string $date): string
    {
        $section = $this->section();

        if (! $section || blank($date)) {
            return '—';
        }

        $solde = app(ServiceArreteCaisse::class)->soldeTheorique($section, Carbon::parse($date));

        return number_format($solde, 0, ',', ' ') . ' FCFA';
    }

    protected function ecart(?string $date, mixed $soldePhysique): ?int
    {
        $section = $this->section();

        if (! $section || blank($date) || $soldePhysique === null || $soldePhysique === '') {
            return null;
        }

        $soldeTheorique = app(ServiceArreteCaisse::class)->soldeTheorique($section, Carbon::parse($date));

        return (int) $soldePhysique - $soldeTheorique;
    }

    /**
     * Enregistre l'arrêté du jour — point d'entrée unique appelé par
     * l'action Filament, mais aussi directement testable.
     */
    public function creerArrete(array $data): void
    {
        if ($this->refuserSiSectionFermee('Cette section de caisse est clôturée : consultation seule, aucun arrêté ne peut être saisi.')) {
            return;
        }

        $section = $this->section();

        if (! $section) {
            return;
        }

        try {
            $arrete = app(ServiceArreteCaisse::class)->arreter(
                section: $section,
                dateArrete: Carbon::parse($data['date_arrete']),
                soldePhysique: (int) $data['solde_physique'],
                commentaireEcart: $data['commentaire_ecart'] ?? null,
            );

            JournalAudit::enregistrer(
                'Arrêté de caisse',
                'TRESORERIE',
                'ArreteCaisse',
                $arrete->id,
                ['date_arrete' => $arrete->date_arrete->toDateString(), 'ecart' => $arrete->ecart]
            );

            Notification::make()
                ->title('Arrêté enregistré')
                ->body($arrete->estEquilibre()
                    ? 'Caisse équilibrée : aucun écart constaté.'
                    : 'Écart constaté : ' . number_format($arrete->ecart, 0, ',', ' ') . ' FCFA.')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Erreur lors de l\'arrêté')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ArreteCaisse::query()->where('caisse_id', $this->section()?->caisse_id ?? 0)
            )
            ->columns([
                Tables\Columns\TextColumn::make('date_arrete')
                    ->label('Jour arrêté')
                    ->date('d/m/Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('solde_theorique')
                    ->label('Solde théorique')
                    ->money('XAF')
                    ->sortable(),
                Tables\Columns\TextColumn::make('solde_physique')
                    ->label('Solde compté')
                    ->money('XAF')
                    ->sortable(),
                Tables\Columns\TextColumn::make('ecart')
                    ->label('Écart')
                    ->money('XAF')
                    ->color(fn (ArreteCaisse $record) => $record->estEquilibre() ? 'success' : 'danger')
                    ->sortable(),
                Tables\Columns\TextColumn::make('commentaire_ecart')
                    ->label('Justification')
                    ->limit(50)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('arretePar.name')
                    ->label('Arrêté par')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('date_validation')
                    ->label('Horodatage')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('date_arrete', 'desc')
            ->headerActions([
                Actions\Action::make('creer_arrete')
                    ->label('Nouvel arrêté')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->button()
                    ->color('primary')
                    ->visible(fn () => $this->isSectionOpen() && auth()->user()->can('arreter_caisse'))
                    ->modalHeading('Arrêté de caisse du jour')
                    ->modalWidth('2xl')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->form([
                        Forms\Components\DatePicker::make('date_arrete')
                            ->label('Jour à arrêter')
                            ->default(today())
                            ->maxDate(today())
                            ->required()
                            ->live(),
                        Forms\Components\Placeholder::make('solde_theorique_affiche')
                            ->label('Solde théorique (calculé depuis le brouillard)')
                            ->content(fn (Get $get) => $this->soldeTheoriqueAffiche($get('date_arrete'))),
                        Forms\Components\TextInput::make('solde_physique')
                            ->label('Solde physique compté')
                            ->placeholder('0')
                            ->integer()
                            ->required()
                            ->live(),
                        Forms\Components\Placeholder::make('ecart_affiche')
                            ->label('Écart')
                            ->content(function (Get $get) {
                                $ecart = $this->ecart($get('date_arrete'), $get('solde_physique'));

                                return $ecart === null ? '—' : number_format($ecart, 0, ',', ' ') . ' FCFA';
                            }),
                        Forms\Components\Textarea::make('commentaire_ecart')
                            ->label('Commentaire de justification')
                            ->placeholder('Obligatoire si un écart est constaté')
                            ->required(fn (Get $get) => $this->ecart($get('date_arrete'), $get('solde_physique')) !== 0)
                            ->rows(3),
                    ])
                    ->action(fn (array $data) => $this->creerArrete($data)),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Aucun arrêté enregistré')
            ->emptyStateDescription('Utilisez le bouton « Nouvel arrêté » pour contrôler la caisse du jour.');
    }
}
