<x-filament-panels::page>
    {{-- En-tête personnalisé --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-6 flex flex-col gap-4">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="p-3 bg-primary-50 dark:bg-primary-500/10 rounded-lg">
                    <x-filament::icon
                        icon="heroicon-o-document-text"
                        class="w-6 h-6 text-primary-600 dark:text-primary-400"
                    />
                </div>
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-bold text-gray-950 dark:text-white">Brouillard de caisse</h1>
                        <x-filament::badge color="info">
                            {{ $caisse->code }}
                        </x-filament::badge>
                    </div>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 flex items-center gap-2">
                        <x-filament::icon icon="heroicon-o-server-stack" class="w-4 h-4" />
                        {{ $caisse->libelle }}
                        <span class="text-gray-300 dark:text-gray-600">|</span>
                        <x-filament::icon icon="heroicon-o-building-office-2" class="w-4 h-4" />
                        {{ $caisse->village->nom }}
                    </p>
                </div>
            </div>
            
            <div class="flex items-center gap-3">
                <x-filament::button
                    icon="heroicon-o-printer"
                    color="gray"
                    tag="a"
                    href="#"
                >
                    Imprimer
                </x-filament::button>
                
                <x-filament::button
                    icon="heroicon-o-arrow-left"
                    color="gray"
                    variant="outlined"
                    tag="a"
                    href="{{ \Modules\Tresorerie\Filament\Pages\ManageCaisseSession::getUrl(['caisse' => $caisse->id, 'section' => $section->id]) }}"
                >
                    Retour à la session
                </x-filament::button>
            </div>
        </div>
    </div>

    {{-- Filtres et Totaux --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="md:col-span-2 bg-white dark:bg-gray-900 rounded-xl shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-6">
            <form wire:submit="filter" class="flex items-end gap-4">
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Date de début</label>
                    <x-filament::input.wrapper>
                        <x-filament::input
                            type="date"
                            wire:model="date_debut"
                        />
                    </x-filament::input.wrapper>
                </div>
                
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Date de fin</label>
                    <x-filament::input.wrapper>
                        <x-filament::input
                            type="date"
                            wire:model="date_fin"
                        />
                    </x-filament::input.wrapper>
                </div>

                <x-filament::button type="submit" color="primary">
                    Filtrer
                </x-filament::button>
            </form>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-6 flex items-center justify-around">
            <div class="text-center">
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Entrée</p>
                <p class="text-xl font-bold text-success-600 dark:text-success-400 mt-1">
                    {{ number_format($this->total_entrees, 0, ',', ' ') }} FCFA
                </p>
            </div>
            
            <div class="w-px h-12 bg-gray-200 dark:bg-gray-700"></div>

            <div class="text-center">
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Sortie</p>
                <p class="text-xl font-bold text-danger-600 dark:text-danger-400 mt-1">
                    {{ number_format($this->total_sorties, 0, ',', ' ') }} FCFA
                </p>
            </div>
        </div>
    </div>

    {{-- Tableau des mouvements --}}
    <div>
        {{ $this->table }}
    </div>
</x-filament-panels::page>
