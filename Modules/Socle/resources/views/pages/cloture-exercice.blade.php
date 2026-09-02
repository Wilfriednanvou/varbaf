<x-filament-panels::page>
    @php($exercice = $this->exercice())

    @if (! $exercice)
        <x-filament::section>
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Aucun exercice n'est actuellement en cours pour ce village.
            </p>
        </x-filament::section>
    @else
        <x-filament::section heading="Exercice à clôturer">
            <div class="flex items-center gap-3">
                <x-filament::badge color="success" size="lg">{{ $exercice->libelle }}</x-filament::badge>
                <span class="text-sm text-gray-600 dark:text-gray-400">
                    du {{ $exercice->date_debut->format('d/m/Y') }} au {{ $exercice->date_fin->format('d/m/Y') }}
                </span>
            </div>
        </x-filament::section>

        <x-filament::section heading="Vérification">
            @php($obstacles = $this->obstacles())

            @if ($obstacles === [])
                <p class="text-sm text-success-600 dark:text-success-400">
                    Rien ne s'oppose à la clôture de cet exercice.
                </p>
            @else
                <ul class="space-y-1">
                    @foreach ($obstacles as $obstacle)
                        <li class="text-sm text-danger-600 dark:text-danger-400">
                            — {{ $obstacle }}
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>

        @foreach ($this->reconducteurs() as $cle => $reconducteur)
            @php($elements = $this->elementsPour($cle))

            <x-filament::section :heading="$reconducteur->libelle()">
                <x-slot name="afterHeader">
                    <div class="flex gap-2">
                        <x-filament::button size="xs" color="gray" wire:click="toutSelectionner('{{ $cle }}')">
                            Tout reconduire
                        </x-filament::button>
                        <x-filament::button size="xs" color="gray" wire:click="toutDeselectionner('{{ $cle }}')">
                            Ne rien reconduire
                        </x-filament::button>
                    </div>
                </x-slot>

                @if ($elements->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-gray-400">Aucun élément actif sur cet exercice.</p>
                @else
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($elements as $element)
                            <label class="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    wire:model="selections.{{ $cle }}"
                                    value="{{ $element['id'] }}"
                                    class="rounded border-gray-300 text-primary-600 dark:border-gray-600"
                                />
                                <span>{{ $element['libelle'] }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        {{ count($this->selections[$cle] ?? []) }} sur {{ $elements->count() }} sélectionné(s)
                    </p>
                @endif
            </x-filament::section>
        @endforeach

        <x-filament::section heading="Nouvel exercice">
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">Libellé</label>
                    <x-filament::input.wrapper>
                        <x-filament::input type="text" wire:model="nouveauLibelle" placeholder="2027" />
                    </x-filament::input.wrapper>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">Date de début</label>
                    <x-filament::input.wrapper>
                        <x-filament::input type="date" wire:model="nouveauDateDebut" />
                    </x-filament::input.wrapper>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">Date de fin</label>
                    <x-filament::input.wrapper>
                        <x-filament::input type="date" wire:model="nouveauDateFin" />
                    </x-filament::input.wrapper>
                </div>
            </div>

            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                S'il existe déjà un exercice « en préparation » portant ce libellé, il devient l'exercice actif —
                aucun doublon n'est créé.
            </p>
        </x-filament::section>

        <div class="flex justify-end">
            <x-filament::button
                color="danger"
                icon="heroicon-o-archive-box-arrow-down"
                wire:click="confirmer"
                wire:confirm="Cette action est irréversible. Confirmer la clôture ?"
                :disabled="$this->obstacles() !== []"
            >
                Clôturer {{ $exercice->libelle }} et ouvrir le nouvel exercice
            </x-filament::button>
        </div>
    @endif
</x-filament-panels::page>
