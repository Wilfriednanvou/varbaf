<x-filament-panels::page>
    <x-filament::section
        :heading="$this->caisse?->libelle ?? '—'"
        :description="$this->caisse ? ('Code : ' . $this->caisse->code) : null"
    >
        <x-slot name="afterHeader">
            <div style="width: 16rem;">
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="selectedSectionId">
                        <option value="">— Sélectionner —</option>
                        @foreach ($this->sections as $sec)
                            <option value="{{ $sec['id'] }}">{{ $sec['label'] }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
        </x-slot>
        @if ($this->selectedSection)
            <dl style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
                <div>
                    <dt style="font-size: 0.875rem; font-weight: 500; color: gray;">Caissier responsable</dt>
                    <dd style="font-size: 1rem; font-weight: 500; margin-top: 0.25rem;">{{ $this->caisse?->caissierResponsable?->nom_complet ?? 'Non assigné' }}</dd>
                </div>
                <div>
                    <dt style="font-size: 0.875rem; font-weight: 500; color: gray;">Exercice</dt>
                    <dd style="font-size: 1rem; font-weight: 500; margin-top: 0.25rem;">{{ $this->exercice?->libelle ?? '—' }}</dd>
                </div>
                <div>
                    <dt style="font-size: 0.875rem; font-weight: 500; color: gray;">Solde d'ouverture</dt>
                    <dd style="font-size: 1rem; font-weight: 500; margin-top: 0.25rem;">{{ number_format($this->selectedSection->solde_ouverture, 0, ',', ' ') }} FCFA</dd>
                </div>
                <div>
                    <dt style="font-size: 0.875rem; font-weight: 500; color: gray;">Solde actuel</dt>
                    <dd style="font-size: 1rem; font-weight: 700; margin-top: 0.25rem; color: rgba(var(--success-600), 1);">{{ number_format($this->selectedSection->soldeCourant(), 0, ',', ' ') }} FCFA</dd>
                </div>
                @if ($this->selectedSection->estCloturee())
                    <div>
                        <dt style="font-size: 0.875rem; font-weight: 500; color: gray;">Solde de clôture</dt>
                        <dd style="font-size: 1rem; font-weight: 700; margin-top: 0.25rem; color: rgba(var(--danger-600), 1);">{{ number_format((int) $this->selectedSection->solde_cloture, 0, ',', ' ') }} FCFA</dd>
                    </div>
                @endif
            </dl>

            @if ($this->selectedSection->estOuverte())
                <x-filament::badge color="success" icon="heroicon-s-check-circle">
                    Section ouverte
                </x-filament::badge>
            @else
                <x-filament::callout
                    color="gray"
                    icon="heroicon-s-lock-closed"
                    heading="Section clôturée — consultation seule"
                />
            @endif
        @endif

        @if (! $this->hasUneOuverte())
            <x-filament::empty-state
                icon="heroicon-o-lock-open"
                :heading="empty($this->sections)
                    ? 'Aucune section de caisse n\'a été créée pour cette caisse.'
                    : 'Aucune section n\'est actuellement ouverte sur cette caisse.'"
            >
                @can('ouvrir_section_caisse')
                    <x-slot name="footer">
                        <x-filament::button
                            icon="heroicon-o-plus-circle"
                            color="success"
                            wire:click="mountAction('ouvrir_section')"
                        >
                            Ouvrir une section
                        </x-filament::button>
                    </x-slot>
                @endcan
            </x-filament::empty-state>
        @endif
    </x-filament::section>

    @if ($this->selectedSection)
        <x-filament::tabs label="Onglets de la session de caisse">
            <x-filament::tabs.item
                icon="heroicon-o-shopping-cart"
                :active="$activeTab === 'ventes'"
                wire:click="$set('activeTab', 'ventes')"
            >
                Ventes
            </x-filament::tabs.item>

            <x-filament::tabs.item
                icon="heroicon-o-arrow-path-rounded-square"
                :active="$activeTab === 'mouvements'"
                wire:click="$set('activeTab', 'mouvements')"
            >
                Mouvements de caisse
            </x-filament::tabs.item>

            <x-filament::tabs.item
                icon="heroicon-o-clipboard-document-check"
                :active="$activeTab === 'arretes'"
                wire:click="$set('activeTab', 'arretes')"
            >
                Arrêtés
            </x-filament::tabs.item>
        </x-filament::tabs>

        {{-- Seul l'onglet actif monte son composant : les autres
             n'interrogent pas la base tant qu'on ne les regarde pas. --}}
        @if ($activeTab === 'ventes')
            <livewire:tresorerie::ventes-caisse-table
                :section-id="$selectedSectionId"
                :key="'ventes-' . $selectedSectionId"
            />
        @elseif ($activeTab === 'mouvements')
            <livewire:tresorerie::mouvements-caisse-table
                :section-id="$selectedSectionId"
                :key="'mvt-' . $selectedSectionId"
            />
        @elseif ($activeTab === 'arretes')
            <livewire:tresorerie::arretes-caisse-table
                :section-id="$selectedSectionId"
                :key="'arretes-' . $selectedSectionId"
            />
        @endif
    @endif
</x-filament-panels::page>
