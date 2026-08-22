<x-filament-panels::page>
    <x-filament::section heading="Artisan">
        <x-filament::input.wrapper>
            <x-filament::input.select wire:model.live="artisanId">
                <option value="">— Choisir un artisan —</option>
                @foreach ($this->artisans as $id => $identite)
                    <option value="{{ $id }}">{{ $identite }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </x-filament::section>

    @if ($this->artisan)
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-filament::section heading="Total vendu">
                <span class="text-lg font-bold">
                    {{ number_format($this->totalVendu, 0, ',', ' ') }} FCFA
                </span>
            </x-filament::section>
            <x-filament::section heading="Total reversé">
                <span class="text-lg font-bold">
                    {{ number_format($this->totalReverse, 0, ',', ' ') }} FCFA
                </span>
            </x-filament::section>
            <x-filament::section heading="Solde dû">
                <span class="text-lg font-bold">
                    {{ number_format($this->soldeDu, 0, ',', ' ') }} FCFA
                </span>
            </x-filament::section>
        </div>

        <x-filament::section heading="Ventes validées">
            {{ $this->table }}
        </x-filament::section>
    @endif
</x-filament-panels::page>
