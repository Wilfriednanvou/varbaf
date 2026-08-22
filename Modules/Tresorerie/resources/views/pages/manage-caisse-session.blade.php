<x-filament-panels::page>
    <div class="space-y-6">

        {{-- En-tête : informations de la caisse et sélecteur de section --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="fi-section-content p-6">
                <div class="flex flex-col gap-6">

                    {{-- Ligne 1 : nom de la caisse + sélecteur --}}
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-bold text-gray-950 dark:text-white">
                                {{ $this->caisse?->libelle ?? '—' }}
                            </h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Code : {{ $this->caisse?->code ?? '—' }}
                            </p>
                        </div>

                        <div class="flex items-center gap-3">
                            <label for="sectionSelect" class="text-sm font-medium text-gray-700 dark:text-gray-300 whitespace-nowrap">
                                Section de caisse :
                            </label>
                            <select
                                id="sectionSelect"
                                wire:model.live="selectedSectionId"
                                class="fi-select-input rounded-lg border-gray-300 text-sm shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-inset focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white min-w-[240px]"
                            >
                                <option value="">— Sélectionner —</option>
                                @foreach ($this->sections as $sec)
                                    <option value="{{ $sec['id'] }}">{{ $sec['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    {{-- Ligne 2 : détails de la section sélectionnée --}}
                    @if ($this->selectedSection)
                        <div class="grid grid-cols-1 gap-4 border-t border-gray-200 pt-6 dark:border-gray-700 sm:grid-cols-2 lg:grid-cols-5">
                            <div>
                                <span class="block text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                    Caissier responsable
                                </span>
                                <span class="mt-1 block text-sm font-medium text-gray-950 dark:text-white">
                                    {{ $this->caisse?->caissierResponsable?->nom_complet ?? 'Non assigné' }}
                                </span>
                            </div>
                            <div>
                                <span class="block text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                    Exercice
                                </span>
                                <span class="mt-1 block text-sm font-medium text-gray-950 dark:text-white">
                                    {{ $this->exercice?->libelle ?? '—' }}
                                </span>
                            </div>
                            <div>
                                <span class="block text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                    Solde d'ouverture
                                </span>
                                <span class="mt-1 block text-sm font-semibold text-gray-950 dark:text-white">
                                    {{ number_format($this->selectedSection->solde_ouverture, 0, ',', ' ') }} FCFA
                                </span>
                            </div>
                            <div>
                                <span class="block text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                    Solde actuel
                                </span>
                                <span class="mt-1 block text-sm font-semibold text-gray-950 dark:text-white">
                                    {{ number_format($this->selectedSection->soldeCourant(), 0, ',', ' ') }} FCFA
                                </span>
                            </div>
                            @if ($this->selectedSection->estCloturee())
                                <div>
                                    <span class="block text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                        Solde de clôture
                                    </span>
                                    <span class="mt-1 block text-sm font-semibold text-gray-950 dark:text-white">
                                        {{ number_format((int) $this->selectedSection->solde_cloture, 0, ',', ' ') }} FCFA
                                    </span>
                                </div>
                            @endif
                        </div>

                        {{-- Badge état --}}
                        <div class="flex items-center gap-4 border-t border-gray-200 pt-4 dark:border-gray-700">
                            @if ($this->selectedSection->estOuverte())
                                <span class="inline-flex items-center gap-1 rounded-md bg-success-50 px-2 py-1 text-xs font-medium text-success-700 ring-1 ring-inset ring-success-600/20 dark:bg-success-400/10 dark:text-success-400 dark:ring-success-400/30">
                                    <x-heroicon-s-check-circle class="h-3.5 w-3.5" />
                                    Section ouverte
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/10 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/20">
                                    <x-heroicon-s-lock-closed class="h-3.5 w-3.5" />
                                    Section clôturée — consultation seule
                                </span>
                            @endif
                        </div>
                    @endif

                    {{-- Aucune section ouverte : proposer l'ouverture plutôt qu'un écran vide --}}
                    @if (! $this->hasUneOuverte())
                        <div class="rounded-lg border border-dashed border-gray-300 p-6 text-center dark:border-gray-600">
                            <x-heroicon-o-lock-open class="mx-auto h-8 w-8 text-gray-400" />
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                                @if (empty($this->sections))
                                    Aucune section de caisse n'a été créée pour cette caisse.
                                @else
                                    Aucune section n'est actuellement ouverte sur cette caisse.
                                @endif
                            </p>
                            @can('ouvrir_section_caisse')
                                <button
                                    type="button"
                                    wire:click="mountAction('ouvrir_section')"
                                    class="fi-btn fi-color-success mt-3 inline-flex items-center gap-1 rounded-lg bg-success-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-success-500"
                                >
                                    <x-heroicon-o-plus-circle class="h-4 w-4" />
                                    Ouvrir une section
                                </button>
                            @endcan
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Onglets : Ventes, Mouvements de caisse, Brouillard --}}
        @if ($this->selectedSection)
            <div x-data="{ activeTab: 'ventes' }" class="space-y-4">

                {{-- Navigation par onglets --}}
                <div class="border-b border-gray-200 dark:border-gray-700">
                    <nav class="-mb-px flex space-x-8" aria-label="Onglets">
                        <button
                            @click="activeTab = 'ventes'"
                            :class="activeTab === 'ventes'
                                ? 'border-primary-500 text-primary-600 dark:border-primary-400 dark:text-primary-400'
                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'"
                            class="flex items-center gap-2 whitespace-nowrap border-b-2 px-1 py-4 text-sm font-medium transition"
                        >
                            <x-heroicon-o-shopping-cart class="h-5 w-5" />
                            Ventes
                        </button>

                        <button
                            @click="activeTab = 'mouvements'"
                            :class="activeTab === 'mouvements'
                                ? 'border-primary-500 text-primary-600 dark:border-primary-400 dark:text-primary-400'
                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'"
                            class="flex items-center gap-2 whitespace-nowrap border-b-2 px-1 py-4 text-sm font-medium transition"
                        >
                            <x-heroicon-o-arrow-path-rounded-square class="h-5 w-5" />
                            Mouvements de caisse
                        </button>

                        <button
                            @click="activeTab = 'brouillard'"
                            :class="activeTab === 'brouillard'
                                ? 'border-primary-500 text-primary-600 dark:border-primary-400 dark:text-primary-400'
                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'"
                            class="flex items-center gap-2 whitespace-nowrap border-b-2 px-1 py-4 text-sm font-medium transition"
                        >
                            <x-heroicon-o-document-text class="h-5 w-5" />
                            Brouillard
                        </button>
                    </nav>
                </div>

                {{-- Panneau Ventes --}}
                <div x-show="activeTab === 'ventes'" x-cloak>
                    <livewire:tresorerie::ventes-caisse-table
                        :section-id="$selectedSectionId"
                        :key="'ventes-' . $selectedSectionId"
                    />
                </div>

                {{-- Panneau Mouvements de caisse --}}
                <div x-show="activeTab === 'mouvements'" x-cloak>
                    <livewire:tresorerie::mouvements-caisse-table
                        :section-id="$selectedSectionId"
                        :key="'mvt-' . $selectedSectionId"
                    />
                </div>

                {{-- Panneau Brouillard --}}
                <div x-show="activeTab === 'brouillard'" x-cloak>
                    <livewire:tresorerie::brouillard-caisse-table
                        :section-id="$selectedSectionId"
                        :key="'brouillard-' . $selectedSectionId"
                    />
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
