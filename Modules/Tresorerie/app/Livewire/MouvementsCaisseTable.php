<?php

namespace Modules\Tresorerie\Livewire;

use Filament\Actions;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Support\Enums\Alignment;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Socle\Models\JournalAudit;
use Modules\Tresorerie\Enums\NatureMouvementCaisse;
use Modules\Tresorerie\Enums\SensMouvementCaisse;
use Modules\Tresorerie\Livewire\Concerns\VerifieSectionOuverte;
use Modules\Tresorerie\Models\LibelleMouvement;
use Modules\Tresorerie\Models\MouvementCaisse;
use Modules\Tresorerie\Services\ServiceTresorerie;

/**
 * Onglet « Mouvements de caisse » : saisie des entrées et sorties
 * manuelles (redevance, location, formation, dépense, reversement).
 *
 * Le libellé provient du référentiel `LibelleMouvement` — jamais d'un
 * texte libre — et porte son sens par défaut : le champ Sens se
 * verrouille dès que le libellé n'est pas « mixte ». Les ventes ont
 * leur propre onglet et ne sont pas saisies ici ; le brouillard complet
 * et chronologique de tous les mouvements (y compris les ventes) vit
 * dans l'onglet Brouillard, en lecture seule.
 *
 * **Défense côté serveur.** Le `->visible()` masque les boutons sur une
 * section clôturée, mais ce n'est pas la seule garde : `creerMouvement()`
 * et `contrepasserMouvement()` revérifient l'état de la section et
 * renvoient une notification lisible plutôt que de laisser une
 * exception remonter, y compris si elles sont appelées directement sur
 * le composant, hors de toute action Filament.
 */
class MouvementsCaisseTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use VerifieSectionOuverte;

    /**
     * `#[Locked]` : une propriété publique Livewire non verrouillée est
     * réinscriptible depuis le navigateur. Sans elle, un compte habilité
     * à saisir pourrait viser la section ouverte d'une autre caisse —
     * `refuserSiSectionFermee()` vérifie que la section est ouverte, pas
     * que c'est celle que l'écran affiche.
     */
    #[Locked]
    public int $sectionId;

    public function render()
    {
        return view('tresorerie::livewire.mouvements-caisse-table');
    }

    /**
     * Libellés proposables à la saisie manuelle — la règle de filtrage
     * (actifs, hors vente et contre-passation) vit sur le référentiel
     * lui-même (`LibelleMouvement::scopeSaisissables()`), pas ici.
     *
     * @return array<int, string>
     */
    protected function libellesSaisissables()
    {
        return LibelleMouvement::query()
            ->saisissables()
            ->orderBy('libelle')
            ->pluck('libelle', 'id')
            ->all();
    }

    /**
     * Enregistre un mouvement manuel — point d'entrée unique appelé par
     * l'action Filament, mais aussi directement testable : c'est la
     * garde qui compte, pas le bouton.
     */
    public function creerMouvement(array $data): void
    {
        if ($this->refuserSiSectionFermee('Cette section de caisse est clôturée : consultation seule, aucune saisie n\'est possible.')) {
            return;
        }

        $section = $this->section();
        $libelleMouvement = LibelleMouvement::find($data['libelle_mouvement_id'] ?? null);
        $nature = $libelleMouvement ? NatureMouvementCaisse::tryFrom($libelleMouvement->code) : null;

        if (! $section || ! $libelleMouvement || ! $nature) {
            Notification::make()
                ->title('Libellé invalide')
                ->body('Le libellé sélectionné ne correspond à aucune nature de mouvement connue.')
                ->danger()
                ->send();

            return;
        }

        try {
            $mouvement = app(ServiceTresorerie::class)->enregistrer(
                section: $section,
                nature: $nature,
                sens: SensMouvementCaisse::from($data['sens']),
                montant: (float) $data['montant'],
                libelle: $libelleMouvement->libelle,
                pieceJustificative: $data['piece_justificative'] ?? null,
                libelleMouvement: $libelleMouvement,
            );

            JournalAudit::enregistrer(
                'Création mouvement de caisse (session)',
                'TRESORERIE',
                'MouvementCaisse',
                $mouvement->id,
                ['libelle' => $mouvement->libelle, 'montant' => $mouvement->montant]
            );

            Notification::make()
                ->title('Mouvement enregistré')
                ->body("Mouvement n° {$mouvement->numero_ordre} — " . number_format((float) $mouvement->montant, 0, ',', ' ') . ' FCFA')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Erreur lors de l\'enregistrement')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Contre-passe un mouvement — même principe de garde que
     * `creerMouvement()`.
     */
    public function contrepasserMouvement(int $mouvementId, string $motif): void
    {
        if ($this->refuserSiSectionFermee('Cette section de caisse est clôturée : consultation seule, aucune correction n\'est possible.')) {
            return;
        }

        // Le mouvement est cherché **dans la section affichée**, jamais
        // par son seul identifiant : sans ce filtre, l'identifiant reçu
        // suffirait à contre-passer un mouvement d'une autre caisse.
        $record = MouvementCaisse::query()
            ->where('section_id', $this->sectionId)
            ->find($mouvementId);

        if (! $record) {
            Notification::make()
                ->title('Mouvement introuvable')
                ->body("Ce mouvement n'appartient pas à la section de caisse affichée.")
                ->danger()
                ->send();

            return;
        }

        try {
            $contrepassation = app(ServiceTresorerie::class)->contrepasser($record, $motif);

            JournalAudit::enregistrer(
                'Contre-passation mouvement (session)',
                'TRESORERIE',
                'MouvementCaisse',
                $contrepassation->id,
                ['mouvement_origine' => $record->numero_ordre, 'motif' => $motif]
            );

            Notification::make()
                ->title('Mouvement contre-passé')
                ->body("Mouvement n° {$record->numero_ordre} annulé par le mouvement n° {$contrepassation->numero_ordre}.")
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Erreur lors de la contre-passation')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                MouvementCaisse::query()
                    ->where('section_id', $this->sectionId)
                    ->where('nature', '!=', NatureMouvementCaisse::VENTE->value)
            )
            ->columns([
                Tables\Columns\TextColumn::make('numero_ordre')
                    ->label('N°')
                    ->sortable(),
                Tables\Columns\TextColumn::make('date_operation')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('libelleMouvement.libelle')
                    ->label('Libellé')
                    ->description(fn (MouvementCaisse $record) => $record->libelle)
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
            ->defaultSort('numero_ordre', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('sens')
                    ->label('Sens')
                    ->options(SensMouvementCaisse::options()),
            ])
            ->headerActions([
                Actions\Action::make('creer_mouvement')
                    ->label('Nouveau')
                    ->icon('heroicon-o-plus')
                    ->button()
                    ->color('primary')
                    ->visible(fn () => $this->isSectionOpen() && auth()->user()->can('saisir_mouvement_caisse'))
                    ->modalHeading('Enregistrer un mouvement de caisse')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->form([
                        \Filament\Schemas\Components\Grid::make(2)->schema([
                            Forms\Components\Select::make('libelle_mouvement_id')
                                ->label('Libellé')
                                ->options(fn () => $this->libellesSaisissables())
                                ->required()
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(function (mixed $state, \Filament\Schemas\Components\Utilities\Set $set): void {
                                    $libelle = $state ? LibelleMouvement::find($state) : null;

                                    if ($libelle && in_array($libelle->sens, ['ENTREE', 'SORTIE'], true)) {
                                        $set('sens', $libelle->sens);
                                    }
                                }),
                            Forms\Components\Select::make('sens')
                                ->label('Sens')
                                ->options(SensMouvementCaisse::options())
                                ->required()
                                ->disabled(function (\Filament\Schemas\Components\Utilities\Get $get): bool {
                                    $libelle = $get('libelle_mouvement_id') ? LibelleMouvement::find($get('libelle_mouvement_id')) : null;

                                    return (bool) ($libelle && in_array($libelle->sens, ['ENTREE', 'SORTIE'], true));
                                })
                                ->dehydrated(true),
                        ]),
                        \Filament\Schemas\Components\Grid::make(2)->schema([
                            Forms\Components\TextInput::make('montant')
                                ->label('Montant')
                                ->placeholder('0')
                                ->integer()
                                ->required()
                                ->minValue(1),
                            Forms\Components\TextInput::make('piece_justificative')
                                ->label('Pièce justificative')
                                ->placeholder('N° de pièce'),
                        ]),
                    ])
                    ->action(fn (array $data) => $this->creerMouvement($data)),
            ])
            ->recordActions([
                Actions\Action::make('contrepasser')
                    ->label('Contre-passer')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->iconButton()
                    ->tooltip('Contre-passer ce mouvement')
                    ->color('warning')
                    ->visible(fn (MouvementCaisse $record) => $this->isSectionOpen()
                        && ! $record->estUneContrepassation()
                        && ! $record->estContrepasse()
                        && auth()->user()->can('contrepasser_mouvement_caisse'))
                    ->form([
                        Forms\Components\Textarea::make('motif')
                            ->label('Motif de la contre-passation')
                            ->required()
                            ->placeholder('Décrivez la raison de la correction'),
                    ])
                    ->modalHeading('Contre-passer le mouvement')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Confirmer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->action(fn (MouvementCaisse $record, array $data) => $this->contrepasserMouvement($record->getKey(), $data['motif'])),
            ])
            ->emptyStateHeading('Aucun mouvement manuel enregistré')
            ->emptyStateDescription('Utilisez le bouton « Nouveau » pour saisir une redevance, une dépense ou tout autre mouvement de caisse.');
    }
}
