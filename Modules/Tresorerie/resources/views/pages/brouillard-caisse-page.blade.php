<x-filament-panels::page>
    {{-- En-tête personnalisé intégré avec Filament Section --}}
    <x-filament::section>
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 1rem;">
                <div style="padding: 0.75rem; background-color: rgba(var(--primary-500), 0.1); border-radius: 0.5rem;">
                    <x-filament::icon
                        icon="heroicon-o-document-text"
                        style="width: 1.5rem; height: 1.5rem; color: rgba(var(--primary-600), 1);"
                    />
                </div>
                <div>
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <h2 style="font-size: 1.25rem; font-weight: bold; margin: 0;">{{ $caisseRecord->code }} - {{ $caisseRecord->libelle }}</h2>
                    </div>
                    <p style="font-size: 0.875rem; color: gray; margin-top: 0.25rem; display: flex; align-items: center; gap: 0.5rem;">
                        <x-filament::icon icon="heroicon-o-building-office-2" style="width: 1rem; height: 1rem;" />
                        {{ $caisseRecord->village->nom }}
                    </p>
                </div>
            </div>
            
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <x-filament::button icon="heroicon-o-printer" color="gray" tag="a" href="#">
                    Imprimer
                </x-filament::button>
                
                <x-filament::button icon="heroicon-o-arrow-left" color="gray" variant="outlined" tag="a" href="{{ \Modules\Tresorerie\Filament\Pages\ManageCaisseSession::getUrl(['caisse' => $caisseRecord->id, 'section' => $sectionRecord->id]) }}">
                    Retour à la session
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>

    {{-- Filtres et Totaux --}}
    <div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1.5rem;">
        <div style="grid-column: span 2 / span 2;">
            <x-filament::section>
                <form wire:submit="filter" style="display: flex; align-items: flex-end; gap: 1rem;">
                    <div style="flex: 1;">
                        <label style="display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.25rem;">Date de début</label>
                        <x-filament::input.wrapper>
                            <x-filament::input type="date" wire:model="date_debut" />
                        </x-filament::input.wrapper>
                    </div>
                    
                    <div style="flex: 1;">
                        <label style="display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.25rem;">Date de fin</label>
                        <x-filament::input.wrapper>
                            <x-filament::input type="date" wire:model="date_fin" />
                        </x-filament::input.wrapper>
                    </div>

                    <x-filament::button type="submit" color="primary">
                        Filtrer
                    </x-filament::button>
                </form>
            </x-filament::section>
        </div>

        <x-filament::section>
            <div style="display: flex; align-items: center; justify-content: space-around; height: 100%;">
                <div style="text-align: center;">
                    <p style="font-size: 0.875rem; font-weight: 500; color: gray;">Total Entrée</p>
                    <p style="font-size: 1.25rem; font-weight: bold; color: rgba(var(--success-600), 1); margin-top: 0.25rem;">
                        {{ number_format($this->total_entrees, 0, ',', ' ') }} FCFA
                    </p>
                </div>
                
                <div style="width: 1px; height: 3rem; background-color: rgba(var(--gray-200), 1);"></div>

                <div style="text-align: center;">
                    <p style="font-size: 0.875rem; font-weight: 500; color: gray;">Total Sortie</p>
                    <p style="font-size: 1.25rem; font-weight: bold; color: rgba(var(--danger-600), 1); margin-top: 0.25rem;">
                        {{ number_format($this->total_sorties, 0, ',', ' ') }} FCFA
                    </p>
                </div>
            </div>
        </x-filament::section>
    </div>

    {{-- Tableau des mouvements --}}
    <div>
        {{ $this->table }}
    </div>
</x-filament-panels::page>
