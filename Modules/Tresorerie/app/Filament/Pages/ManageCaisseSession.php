<?php

namespace Modules\Tresorerie\Filament\Pages;

use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Alignment;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\JournalAudit;
use Modules\Tresorerie\Enums\EtatSectionCaisse;
use Modules\Tresorerie\Filament\Pages\BrouillardCaissePage;
use Modules\Tresorerie\Filament\Resources\CaisseResource;
use Modules\Tresorerie\Models\Caisse;
use Modules\Tresorerie\Models\SectionCaisse;

/**
 * Écran opérationnel de caisse.
 *
 * Accessible depuis l'action de ligne « Session de caisse » de
 * `CaisseResource`, cet écran centralise les opérations sur une
 * section donnée : saisie de ventes, saisie de mouvements manuels et
 * consultation du brouillard, répartis en trois onglets.
 *
 * La route porte la caisse et la section (`/caisses/{caisse}/session/{section}`) :
 * changer de section dans la liste déroulante navigue vers l'URL
 * correspondante plutôt que de se contenter de mettre à jour une
 * propriété locale, pour qu'un rafraîchissement de page ne perde
 * jamais le contexte.
 *
 * Si la section sélectionnée est clôturée, l'écran passe en lecture
 * seule : les boutons de création et d'annulation disparaissent, et
 * chaque composant Livewire embarqué revérifie lui-même l'état de la
 * section avant d'écrire quoi que ce soit — le `->visible()` n'est pas
 * la seule barrière ici, contrairement au reste du panneau (DT-08).
 */
class ManageCaisseSession extends Page
{
    protected string $view = 'tresorerie::pages.manage-caisse-session';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'caisses-session';

    public ?int $caisseId = null;

    public ?int $selectedSectionId = null;

    /**
     * Onglet actif — une propriété de page, pas un état Alpine : seul
     * l'onglet affiché monte son composant Livewire (et n'interroge
     * donc la base que pour ce qu'on regarde réellement).
     */
    #[Url(as: 'tab')]
    public string $activeTab = 'ventes';

    public static function getRoutePath(Panel $panel): string
    {
        return '/caisses/{caisse}/session/{section?}';
    }

    /**
     * `shouldRegisterNavigation` étant à `false`, Filament ne consulte
     * jamais `canAccess()` pour cette page (il ne sert qu'à construire
     * la navigation) : sans ce contrôle explicite dans `mount()`, rien
     * n'empêcherait un compte connecté d'atteindre l'URL directement,
     * quelles que soient ses permissions.
     */
    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_sections_caisse');
    }

    public function mount(int|string $caisse, int|string|null $section = null): void
    {
        abort_unless(auth()->user()->can('lister_sections_caisse'), 403);

        $caisseModel = Caisse::findOrFail((int) $caisse);
        $this->caisseId = $caisseModel->getKey();

        // Pré-sélection si le paramètre section est fourni
        if ($section) {
            $sectionModel = SectionCaisse::query()
                ->where('id', (int) $section)
                ->where('caisse_id', $this->caisseId)
                ->first();

            $this->selectedSectionId = $sectionModel?->getKey();
        }

        // Sinon, sélectionner la section ouverte ou la plus récente
        if (! $this->selectedSectionId) {
            $this->selectedSectionId = $caisseModel->sections()
                ->where('etat', EtatSectionCaisse::OUVERTE->value)
                ->value('id')
                ?? $caisseModel->sections()->orderByDesc('id')->value('id');
        }
    }

    public function getTitle(): string
    {
        return 'Session de caisse : ' . ($this->caisse?->libelle ?? '');
    }

    public function getHeading(): string
    {
        return 'Session de caisse : ' . ($this->caisse?->libelle ?? '');
    }

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            CaisseResource::getUrl() => 'Caisses',
            '' => $this->caisse?->libelle ?? 'Session',
        ];
    }

    #[Computed]
    public function caisse(): ?Caisse
    {
        return $this->caisseId
            ? Caisse::with('caissierResponsable')->find($this->caisseId)
            : null;
    }

    #[Computed]
    public function selectedSection(): ?SectionCaisse
    {
        return $this->selectedSectionId
            ? SectionCaisse::with(['ouvertePar', 'clotureePar', 'exercice'])->find($this->selectedSectionId)
            : null;
    }

    #[Computed]
    public function exercice(): ?Exercice
    {
        return $this->selectedSection?->exercice;
    }

    #[Computed]
    public function sections(): array
    {
        if (! $this->caisseId) {
            return [];
        }

        return SectionCaisse::query()
            ->where('caisse_id', $this->caisseId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (SectionCaisse $s) => [
                'id' => $s->id,
                'label' => $s->libelle . ' (' . $s->etat->getLabel() . ')',
            ])
            ->all();
    }

    public function isSectionOuverte(): bool
    {
        return $this->selectedSection?->estOuverte() ?? false;
    }

    public function hasUneOuverte(): bool
    {
        if (! $this->caisseId) {
            return false;
        }

        return SectionCaisse::query()
            ->where('caisse_id', $this->caisseId)
            ->where('etat', EtatSectionCaisse::OUVERTE->value)
            ->exists();
    }

    /**
     * Changer de section dans la liste déroulante navigue vers l'URL de
     * cette section plutôt que de se contenter de mettre à jour l'état
     * local du composant : c'est ce qui garantit qu'un rafraîchissement
     * (F5) rouvre exactement la même section, l'URL étant la seule
     * source de vérité de la navigation.
     */
    public function updatedSelectedSectionId(): void
    {
        if (! $this->selectedSectionId || ! $this->caisseId) {
            return;
        }

        $this->redirect(
            static::getUrl(['caisse' => $this->caisseId, 'section' => $this->selectedSectionId]),
            navigate: true,
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('brouillard')
                ->label('Brouillard de caisse')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->visible(fn () => auth()->user()->can('lister_mouvements_caisse') && $this->selectedSectionId)
                ->url(fn () => BrouillardCaissePage::getUrl(['caisse' => $this->caisseId, 'section' => $this->selectedSectionId])),
            
            // Ouvrir une nouvelle section (si aucune n'est ouverte sur cette caisse)
            Actions\Action::make('ouvrir_section')
                ->label('Ouvrir une section')
                ->icon('heroicon-o-plus-circle')
                ->color('success')
                ->visible(fn () => auth()->user()->can('ouvrir_section_caisse') && ! $this->hasUneOuverte())
                ->modalHeading('Ouvrir une section de caisse')
                ->modalWidth('3xl')
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->form([
                    Grid::make(2)->schema([
                        Forms\Components\TextInput::make('libelle')
                            ->label('Libellé de la section')
                            ->placeholder('Section exercice 2026')
                            ->required(),
                        // RG-02 : le solde d'ouverture ne se saisit pas.
                        // Il est affiché pour information et calculé par
                        // le modèle à l'enregistrement — un champ, même
                        // pré-rempli, se corrige avant validation.
                        Forms\Components\Placeholder::make('solde_ouverture_affiche')
                            ->label('Solde d\'ouverture (repris de la section précédente)')
                            ->content(fn () => number_format(
                                SectionCaisse::soldeDOuverturePour($this->caisseId),
                                0,
                                ',',
                                ' ',
                            ).' FCFA'),
                    ]),
                ])
                ->action(function (array $data) {
                    $caisse = $this->caisse;

                    if (! $caisse) {
                        return;
                    }

                    // `Exercice::courant()` est le point d'entrée que le
                    // Socle expose : aucun autre module ne requête la
                    // table des exercices. `exercice_id` étant NOT NULL,
                    // l'absence d'exercice en cours doit produire un
                    // message lisible, pas une erreur SQL au moment de
                    // l'insertion.
                    $exercice = Exercice::courant();

                    if (! $exercice) {
                        Notification::make()
                            ->title('Aucun exercice en cours')
                            ->body("Ouvrez un exercice avant d'ouvrir une section de caisse.")
                            ->danger()
                            ->send();

                        return;
                    }

                    $section = SectionCaisse::create([
                        'caisse_id' => $caisse->getKey(),
                        'libelle' => $data['libelle'],
                        'date_ouverture' => now(),
                        // `solde_ouverture` est absent : le crochet
                        // `creating` du modèle le calcule (RG-02).
                        'etat' => EtatSectionCaisse::OUVERTE,
                        'ouverte_par' => auth()->id(),
                        // Le village est celui de la caisse, jamais le
                        // premier village trouvé en base.
                        'village_id' => $caisse->village_id,
                        'exercice_id' => $exercice->getKey(),
                    ]);

                    JournalAudit::enregistrer(
                        'Ouverture section de caisse',
                        'TRESORERIE',
                        'SectionCaisse',
                        $section->id,
                        ['libelle' => $section->libelle, 'solde_ouverture' => $section->solde_ouverture]
                    );

                    $this->selectedSectionId = $section->id;
                    unset($this->sections, $this->selectedSection);

                    Notification::make()
                        ->title('Section ouverte')
                        ->body("La section « {$section->libelle} » est maintenant ouverte.")
                        ->success()
                        ->send();

                    // Faire porter l'URL par la nouvelle section, sans
                    // repasser par updatedSelectedSectionId() (déjà à jour).
                    $this->redirect(
                        static::getUrl(['caisse' => $this->caisseId, 'section' => $section->id]),
                        navigate: true,
                    );
                }),

            // Clôturer la section sélectionnée
            Actions\Action::make('cloturer_section')
                ->label('Clôturer la section')
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->visible(fn () => $this->isSectionOuverte() && auth()->user()->can('cloturer_section_caisse'))
                ->requiresConfirmation()
                ->modalHeading('Clôturer la section')
                ->modalDescription(fn () => "Êtes-vous certain de vouloir clôturer la section « {$this->selectedSection?->libelle} » ? Cette action est irréversible.")
                ->action(function () {
                    $section = $this->selectedSection;

                    if (! $section) {
                        return;
                    }

                    $soldeCourant = $section->cloturer();

                    JournalAudit::enregistrer(
                        'Clôture section de caisse',
                        'TRESORERIE',
                        'SectionCaisse',
                        $section->id,
                        ['libelle' => $section->libelle, 'solde_cloture' => $soldeCourant]
                    );

                    unset($this->sections, $this->selectedSection);

                    Notification::make()
                        ->title('Section clôturée')
                        ->body("La section « {$section->libelle} » est désormais clôturée.")
                        ->success()
                        ->send();
                }),
        ];
    }
}
