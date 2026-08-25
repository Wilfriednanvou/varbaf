<x-filament::section heading="D'où vient le chiffre d'affaires" :description="'Ventes validées '.$intervalle">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        @foreach ($axes as $axe)
            <div class="flex flex-col gap-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
                    {{ $axe['titre'] }}
                </h3>

                @if (empty($axe['lignes']))
                    <p class="text-sm text-gray-500 dark:text-gray-400">Aucune vente sur cette période.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-gray-200 dark:border-white/10">
                                <tr>
                                    <th class="pb-2 font-medium text-gray-500 dark:text-gray-400">Libellé</th>
                                    <th class="pb-2 font-medium text-center text-gray-500 dark:text-gray-400">Ventes</th>
                                    <th class="pb-2 font-medium text-right text-gray-500 dark:text-gray-400">Montant</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                                @foreach ($axe['lignes'] as $ligne)
                                    <tr>
                                        <td class="py-3">
                                            <div class="font-medium text-gray-950 dark:text-white">{{ $ligne['libelle'] }}</div>
                                            @if ($ligne['detail'])
                                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $ligne['detail'] }}</div>
                                            @endif
                                        </td>
                                        <td class="py-3 text-center">
                                            <x-filament::badge color="primary">{{ $ligne['nombre'] }}</x-filament::badge>
                                        </td>
                                        <td class="py-3 text-right font-semibold text-gray-950 dark:text-white">
                                            {{ number_format($ligne['total'], 0, ',', ' ') }} <span class="text-xs text-gray-500 font-normal">FCFA</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</x-filament::section>
