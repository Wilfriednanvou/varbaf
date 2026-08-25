<?php

namespace Modules\Tresorerie\Livewire;

use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Artisanat\Models\Boutique;
use Modules\Commerce\Enums\EtatVente;
use Modules\Commerce\Enums\ModeReglement;
use Modules\Commerce\Enums\ProvenanceClient;
use Modules\Commerce\Models\Produit;
use Modules\Commerce\Exceptions\AucunTauxCommissionException;
use Modules\Commerce\Exceptions\ProduitInvalideException;
use Modules\Commerce\Exceptions\StockInsuffisantException;
use Modules\Commerce\Exceptions\VenteInvalideException;
use Modules\Commerce\Models\Vente;
use Modules\Commerce\Services\ServiceVente;
use Modules\Socle\Models\JournalAudit;
use Modules\Tresorerie\Exceptions\MouvementCaisseImmuableException;
use Modules\Tresorerie\Exceptions\SectionCaisseException;
use Modules\Tresorerie\Livewire\Concerns\VerifieSectionOuverte;
use Modules\Tresorerie\Services\ServiceTresorerie;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Table des ventes rattachées à une section de caisse.
 *
 * Composant Livewire autonome embarqué dans l'écran opérationnel
 * de caisse. Affiche les ventes enregistrées sur la section
 * sélectionnée, avec création, consultation, reçu et annulation.
 *
 * **Défense côté serveur.** `creerVente()` et `annulerVente()` sont les
 * points d'entrée réels — appelés par les actions Filament, mais aussi
 * directement testables : la section clôturée y est revérifiée et
 * renvoie une notification lisible, jamais une exception non attrapée.
 */
class VentesCaisseTable extends Component implements HasActions, HasSchemas, HasTable
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
        return view('tresorerie::livewire.ventes-caisse-table');
    }

    /**
     * Enregistre une vente — appelé par l'action Filament « Nouveau »,
     * mais appelable directement (tests, défense en profondeur).
     */
    public function creerVente(array $data): void
    {
        if ($this->refuserSiSectionFermee('Cette section de caisse est clôturée : consultation seule, aucune vente ne peut y être saisie.')) {
            return;
        }

        ServiceTresorerie::ciblerSection($this->section());

        try {
            $vente = app(ServiceVente::class)->enregistrer(
                lignes: $data['lignes'] ?? [],
                modeReglement: ModeReglement::from($data['mode_reglement']),
                client: [
                    'nom_client' => $data['nom_client'] ?? null,
                    'contact_client' => $data['contact_client'] ?? null,
                    'accepte_notifications' => (bool) ($data['accepte_notifications'] ?? false),
                    'provenance_client' => isset($data['provenance_client']) ? $data['provenance_client'] : null,
                ],
            );

            JournalAudit::enregistrer(
                'Création vente (session caisse)',
                'COMMERCE',
                'Vente',
                $vente->id,
                ['numero' => $vente->numero, 'montant' => $vente->montant_total]
            );

            Notification::make()
                ->title('Vente enregistrée')
                ->body("Ticket {$vente->numero} — " . number_format((float) $vente->montant_total, 0, ',', ' ') . ' FCFA')
                ->success()
                ->send();
        } catch (VenteInvalideException|StockInsuffisantException|ProduitInvalideException|AucunTauxCommissionException|SectionCaisseException|MouvementCaisseImmuableException|\InvalidArgumentException $e) {
            // Seules les exceptions métier deviennent un message :
            // leur texte est écrit pour être lu au comptoir. Une
            // panne technique remonte au gestionnaire d'erreurs, où
            // elle est journalisée — afficher une `QueryException`
            // mettrait du SQL sous les yeux d'une vendeuse.
            Notification::make()
                ->title('Erreur lors de l\'enregistrement')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } finally {
            ServiceTresorerie::ciblerSection(null);
        }
    }

    /**
     * Annule une vente — même principe de garde que `creerVente()`.
     */
    public function annulerVente(int $venteId, string $motif): void
    {
        if ($this->refuserSiSectionFermee('Cette section de caisse est clôturée : consultation seule, aucune annulation n\'est possible.')) {
            return;
        }

        // La vente est cherchée **dans la section affichée**, jamais par
        // son seul identifiant : sans ce filtre, l'identifiant reçu
        // suffirait à annuler la vente d'une autre caisse.
        $vente = Vente::query()
            ->where('section_caisse_id', $this->sectionId)
            ->find($venteId);

        if (! $vente) {
            Notification::make()
                ->title('Vente introuvable')
                ->body("Cette vente n'appartient pas à la section de caisse affichée.")
                ->danger()
                ->send();

            return;
        }

        ServiceTresorerie::ciblerSection($this->section());

        try {
            app(ServiceVente::class)->annuler($vente, $motif);

            JournalAudit::enregistrer(
                'Annulation vente (session caisse)',
                'COMMERCE',
                'Vente',
                $vente->id,
                ['numero' => $vente->numero, 'motif' => $motif]
            );

            Notification::make()
                ->title('Vente annulée')
                ->body("Ticket {$vente->numero} annulé et contre-passé.")
                ->success()
                ->send();
        } catch (VenteInvalideException|StockInsuffisantException|ProduitInvalideException|AucunTauxCommissionException|SectionCaisseException|MouvementCaisseImmuableException|\InvalidArgumentException $e) {
            // Seules les exceptions métier deviennent un message :
            // leur texte est écrit pour être lu au comptoir. Une
            // panne technique remonte au gestionnaire d'erreurs, où
            // elle est journalisée — afficher une `QueryException`
            // mettrait du SQL sous les yeux d'une vendeuse.
            Notification::make()
                ->title('Erreur lors de l\'annulation')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } finally {
            ServiceTresorerie::ciblerSection(null);
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Vente::query()->where('section_caisse_id', $this->sectionId)
            )
            ->columns([
                Tables\Columns\TextColumn::make('numero')
                    ->label('N° Reçu')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->copyable(),
                Tables\Columns\TextColumn::make('artisan.nom')
                    ->label('Artisan')
                    ->description(fn (Vente $record) => $record->artisan?->matricule)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('boutique.numero')
                    ->label('Boutique')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('mode_reglement')
                    ->label('Type / Mode')
                    ->badge(),
                Tables\Columns\TextColumn::make('date_vente')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('montant_total')
                    ->label('Montant')
                    ->money('XAF')
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('etat')
                    ->label('Statut')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('vendeur.nom')
                    ->label('Vendeur')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('nom_client')
                    ->label('Client')
                    ->placeholder('De passage')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('date_vente', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('etat')
                    ->label('État')
                    ->options(EtatVente::options()),
                Tables\Filters\SelectFilter::make('boutique_id')
                    ->label('Boutique')
                    ->relationship('boutique', 'numero')
                    ->searchable()
                    ->preload(),
            ])
            ->headerActions([
                Actions\Action::make('creer_vente')
                    ->label('Nouveau')
                    ->icon('heroicon-o-plus')
                    ->button()
                    ->color('primary')
                    ->visible(fn () => $this->isSectionOpen() && auth()->user()->can('ajouter_vente'))
                    ->modalHeading('Enregistrer une vente')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->form([
                        \Filament\Schemas\Components\Section::make('Panier')
                            ->description('Sélectionnez la boutique et les articles.')
                            ->schema([
                                Forms\Components\Select::make('boutique_id')
                                    ->label('Boutique')
                                    ->options(
                                        Boutique::query()
                                            ->orderBy('numero')
                                            ->pluck('numero', 'id')
                                    )
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->live()
                                    ->dehydrated(false),
                                Forms\Components\Repeater::make('lignes')
                                    ->label('Articles vendus')
                                    ->addActionLabel('Ajouter un article')
                                    ->reorderable(false)
                                    ->defaultItems(1)
                                    ->columns(4)
                                    ->schema([
                                        Forms\Components\Select::make('produit_id')
                                            ->label('Produit')
                                            ->options(fn (\Filament\Schemas\Components\Utilities\Get $get) => filled($get('../../boutique_id'))
                                                ? Produit::query()
                                                    ->vendable()
                                                    ->where('boutique_id', $get('../../boutique_id'))
                                                    ->orderBy('designation')
                                                    ->get()
                                                    ->mapWithKeys(fn (Produit $p) => [
                                                        $p->id => "{$p->designation} (stock : {$p->getQuantiteEnStock()})",
                                                    ])
                                                    ->all()
                                                : [])
                                            ->searchable()
                                            ->required()
                                            ->distinct()
                                            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                            ->live()
                                            ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                                if ($state) {
                                                    $produit = Produit::find($state);
                                                    if ($produit) {
                                                        $set('prix_unitaire', $produit->prix_unitaire);
                                                        $set('montant', $produit->prix_unitaire * (int) ($get('quantite') ?: 1));
                                                    }
                                                } else {
                                                    $set('prix_unitaire', null);
                                                    $set('montant', null);
                                                }
                                            }),
                                        Forms\Components\TextInput::make('prix_unitaire')
                                            ->label('Prix unitaire')
                                            ->numeric()
                                            ->required()
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                                $set('montant', (int) $state * (int) ($get('quantite') ?: 1));
                                            }),
                                        Forms\Components\TextInput::make('quantite')
                                            ->label('Quantité')
                                            ->placeholder('1')
                                            ->numeric()
                                            ->minValue(1)
                                            ->default(1)
                                            ->required()
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                                $set('montant', (int) $state * (int) ($get('prix_unitaire') ?: 0));
                                            }),
                                        Forms\Components\TextInput::make('montant')
                                            ->label('Total (FCFA)')
                                            ->disabled()
                                            ->dehydrated(false)
                                            ->numeric(),
                                    ]),
                            ]),
                            
                        \Filament\Schemas\Components\Section::make('Règlement & Client')
                            ->description('Informations sur le paiement et le profil de l\'acheteur.')
                            ->schema([
                                \Filament\Schemas\Components\Grid::make(2)->schema([
                                    Forms\Components\Select::make('mode_reglement')
                                        ->label('Mode de règlement')
                                        ->options(ModeReglement::options())
                                        ->default(ModeReglement::ESPECES->value)
                                        ->native(false)
                                        ->required(),
                                    Forms\Components\Select::make('provenance_client')
                                        ->label('Provenance du client')
                                        ->options(ProvenanceClient::options())
                                        ->native(false)
                                        ->placeholder('Non renseignée'),
                                ]),
                                \Filament\Schemas\Components\Grid::make(2)->schema([
                                    Forms\Components\TextInput::make('nom_client')
                                        ->label('Nom du client')
                                        ->placeholder('Facultatif')
                                        ->maxLength(255),
                                    Forms\Components\TextInput::make('contact_client')
                                        ->label('Téléphone ou e-mail')
                                        ->placeholder('Facultatif')
                                        ->maxLength(255),
                                ]),
                                Forms\Components\Toggle::make('accepte_notifications')
                                    ->label('Le client accepte de recevoir les informations du village')
                                    ->default(false)
                                    ->helperText('Consentement recueilli oralement au comptoir'),
                            ]),
                    ])
                    ->action(fn (array $data) => $this->creerVente($data)),
            ])
            ->recordActions([
                Actions\Action::make('consulter')
                    ->label('Consulter')
                    ->icon('heroicon-o-eye')
                    ->iconButton()
                    ->tooltip('Consulter la vente')
                    ->modalHeading(fn (Vente $record) => "Vente {$record->numero}")
                    ->modalWidth('2xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->schema([
                        \Filament\Schemas\Components\Grid::make(3)->schema([
                            TextEntry::make('date_vente')
                                ->label('Date')
                                ->dateTime('d/m/Y H:i'),
                            TextEntry::make('boutique.numero')
                                ->label('Boutique'),
                            TextEntry::make('etat')
                                ->label('État')
                                ->badge(),
                        ]),
                        \Filament\Schemas\Components\Grid::make(3)->schema([
                            TextEntry::make('artisan.nom')
                                ->label('Artisan'),
                            TextEntry::make('vendeur.nom')
                                ->label('Vendeur'),
                            TextEntry::make('mode_reglement')
                                ->label('Règlement')
                                ->badge(),
                        ]),
                        RepeatableEntry::make('lignes')
                            ->label('Articles')
                            ->schema([
                                \Filament\Schemas\Components\Grid::make(3)->schema([
                                    TextEntry::make('designation')->label('Désignation'),
                                    TextEntry::make('quantite')->label('Quantité'),
                                    TextEntry::make('montant_ligne')->label('Montant')->money('XAF'),
                                ]),
                            ]),
                        \Filament\Schemas\Components\Grid::make(4)->schema([
                            TextEntry::make('montant_total')
                                ->label('Encaissé')
                                ->money('XAF')
                                ->weight('bold'),
                            TextEntry::make('taux_commission')
                                ->label('Taux')
                                ->formatStateUsing(fn (string $state) => "{$state} %"),
                            TextEntry::make('montant_commission')
                                ->label('Commission')
                                ->money('XAF'),
                            TextEntry::make('part_artisan')
                                ->label('Part artisan')
                                ->money('XAF'),
                        ]),
                        TextEntry::make('motif_annulation')
                            ->label('Motif d\'annulation')
                            ->visible(fn (Vente $record) => $record->estAnnulee()),
                    ]),
                Actions\Action::make('recu')
                    ->label('Reçu')
                    ->icon('heroicon-o-document-arrow-down')
                    ->iconButton()
                    ->tooltip('Éditer le reçu de vente')
                    ->visible(fn () => auth()->user()->can('imprimer_recu_vente'))
                    ->action(function (Vente $record) {
                        $record->loadMissing(['lignes', 'artisan', 'boutique.village', 'vendeur']);

                        JournalAudit::enregistrer(
                            'Édition reçu de vente (session caisse)',
                            'COMMERCE',
                            'Vente',
                            $record->id,
                            ['numero' => $record->numero],
                        );

                        return Pdf::loadView('commerce::ventes.recu', [
                            'vente' => $record,
                            'village' => $record->boutique?->village,
                            'genereLe' => now()->format('d/m/Y à H:i'),
                        ])->download("recu-{$record->numero}.pdf");
                    }),
                Actions\Action::make('annuler')
                    ->label('Annuler')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->iconButton()
                    ->tooltip('Annuler la vente')
                    ->visible(fn (Vente $record) => $this->isSectionOpen()
                        && auth()->user()->can('annuler_vente')
                        && $record->estValidee())
                    ->modalHeading('Annuler la vente')
                    ->modalDescription('Les articles reviennent en stock et l\'encaissement est contre-passé en caisse.')
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
                    ->form([
                        Forms\Components\Textarea::make('motif_annulation')
                            ->label('Motif de l\'annulation')
                            ->placeholder('Erreur de saisie, client revenu sur son achat, article défectueux')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(fn (Vente $record, array $data) => $this->annulerVente($record->getKey(), $data['motif_annulation'])),
            ])
            ->emptyStateHeading('Aucune vente enregistrée')
            ->emptyStateDescription('Utilisez le bouton « Nouveau » pour enregistrer une vente.');
    }
}
