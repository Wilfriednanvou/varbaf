<div class="flex items-center gap-2 px-2">
    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
        Exercice
    </span>

    @can('lister_exercices')
        <div class="w-44">
            <x-filament::input.wrapper inline-prefix>
                <x-filament::input.select wire:model.live="exerciceId">
                    @foreach ($this->exercices() as $exercice)
                        <option value="{{ $exercice['id'] }}">
                            {{ $exercice['libelle'] }} — {{ $exercice['statut'] }}
                        </option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
    @else
        <x-filament::badge color="gray">
            {{ collect($this->exercices())->firstWhere('id', $exerciceId)['libelle'] ?? '—' }}
        </x-filament::badge>
    @endcan
</div>
