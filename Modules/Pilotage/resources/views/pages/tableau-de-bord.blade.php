<x-filament-panels::page>
    {{-- Les filtres vivent sur la page, pas sur chaque widget : trois
         sélecteurs d'exercice côte à côte finiraient par afficher trois
         périodes différentes sans que le lecteur s'en aperçoive. --}}
    {{-- L'assistant est une autre façon d'interroger les mêmes
         indicateurs : il vit donc à côté du tableau de bord, pas
         ailleurs dans la navigation. --}}
    <x-filament::section heading="Interroger le tableau de bord">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <p class="text-sm">
                Posez une question en français : les questions chiffrées sont résolues par le calcul,
                les questions descriptives par la recherche dans le corpus indexé.
            </p>

            <x-filament::button
                tag="a"
                :href="route('filament.admin.pages.assistant')"
                icon="heroicon-o-chat-bubble-left-right"
            >
                Ouvrir l'assistant
            </x-filament::button>
        </div>
    </x-filament::section>

    <x-filament::section heading="Portée des indicateurs">
        <div style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; align-items: end; margin-bottom: 1rem;">
            <div>
                <label style="display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.25rem;">Exercice</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="exerciceId">
                        <option value="">Tous les exercices</option>
                        @foreach ($this->exercices as $exercice)
                            <option value="{{ $exercice['id'] }}">{{ $exercice['libelle'] }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>

            <div>
                <label style="display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.25rem;">Du</label>
                <x-filament::input.wrapper>
                    <x-filament::input type="date" wire:model.live="du" />
                </x-filament::input.wrapper>
            </div>

            <div>
                <label style="display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.25rem;">Au</label>
                <x-filament::input.wrapper>
                    <x-filament::input type="date" wire:model.live="au" />
                </x-filament::input.wrapper>
            </div>

            <div>
                @if ($du || $au)
                    <x-filament::button
                        color="gray"
                        icon="heroicon-o-x-mark"
                        wire:click="reinitialiserIntervalle"
                    >
                        Retirer l'intervalle
                    </x-filament::button>
                @endif
            </div>
        </div>

        <p>Indicateurs de vente {{ $this->filtre->libelleIntervalle() }}.</p>
    </x-filament::section>

    {{-- La clé porte l'empreinte des filtres : elle change quand ils
         changent, et Livewire remonte les widgets au lieu de réutiliser
         les composants existants avec leurs anciens chiffres. --}}
    @php($empreinte = $this->empreinteFiltre())
    @php($filtres = $this->filtresTableau())

    <livewire:pilotage::indicateurs-cles
        :filtres="$filtres"
        :key="'indicateurs-'.$empreinte"
    />

    @can('consulter_indicateurs_financiers')
        <livewire:pilotage::soldes-de-caisse
            :filtres="$filtres"
            :key="'caisses-'.$empreinte"
        />
    @endcan

    @can('consulter_indicateurs_commerciaux')
        <livewire:pilotage::ventes-par-axe
            :filtres="$filtres"
            :key="'axes-'.$empreinte"
        />

        <livewire:pilotage::provenance-des-clients
            :filtres="$filtres"
            :key="'provenance-'.$empreinte"
        />
    @endcan

    @can('consulter_alertes_stock')
        <livewire:pilotage::alertes-stock
            :filtres="$filtres"
            :key="'alertes-'.$empreinte"
        />
    @endcan

    {{-- Lecture du catalogue par la similarité. Ces deux blocs ne
         dépendent pas des filtres de période : ils décrivent la forme du
         catalogue à l'instant présent, pas ce qui s'est vendu. Leur clé
         ne porte donc pas l'empreinte du filtre — la remonter à chaque
         changement de date relancerait un calcul coûteux pour un
         résultat identique. --}}
    @can('consulter_tableau_bord')
        <livewire:pilotage::produits-isoles key="produits-isoles" />

        <livewire:pilotage::segments-satures key="segments-satures" />
    @endcan
</x-filament-panels::page>
