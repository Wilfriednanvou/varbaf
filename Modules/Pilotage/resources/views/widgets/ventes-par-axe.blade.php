<x-filament::section
    heading="D'où vient le chiffre d'affaires"
    :description="'Ventes validées '.$intervalle"
    icon="heroicon-o-chart-bar"
>
    {{-- Trois axes côte à côte à partir du grand écran, empilés en
         dessous. Ils répondent à la même question sous trois angles :
         les lire l'un à côté de l'autre est le propos de ce bloc. --}}
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        @foreach ($axes as $axe)
            <section
                class="rounded-xl bg-gray-50 p-5 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10"
            >
                <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ $axe['titre'] }}
                </h3>

                @if (empty($axe['lignes']))
                    <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                        Aucune vente sur cette période.
                    </p>
                @else
                    {{-- Une liste et non un tableau. À un tiers de la
                         largeur, trois colonnes obligeraient les noms
                         d'artisans à se replier sur deux lignes et
                         désaligneraient les montants — ce que montrait
                         la version précédente. Chaque entrée tient donc
                         sur deux lignes : l'identité et le montant, puis
                         la barre et le nombre de ventes. --}}
                    <ul role="list" class="mt-4 divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($axe['lignes'] as $ligne)
                            <li class="py-3 first:pt-0 last:pb-0">
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="truncate text-sm font-medium text-gray-950 dark:text-white">
                                        {{ $ligne['libelle'] }}
                                    </span>

                                    {{-- Les chiffres alignés à droite et
                                         tabulaires : c'est ce qui rend
                                         une colonne de montants
                                         comparable d'un coup d'œil. --}}
                                    <span class="shrink-0 text-sm font-semibold tabular-nums text-gray-950 dark:text-white">
                                        {{ number_format($ligne['total'], 0, ',', ' ') }}<span
                                            class="ms-1 text-xs font-normal text-gray-500 dark:text-gray-400"
                                        >FCFA</span>
                                    </span>
                                </div>

                                @if ($ligne['detail'])
                                    <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">
                                        {{ $ligne['detail'] }}
                                    </p>
                                @endif

                                <div class="mt-2 flex items-center gap-3">
                                    {{-- La barre est redondante avec le
                                         montant écrit au-dessus : elle
                                         est donc masquée aux lecteurs
                                         d'écran plutôt que doublée d'un
                                         libellé qu'ils entendraient deux
                                         fois. Une seule teinte, parce
                                         qu'elle code une grandeur et non
                                         une identité. --}}
                                    <div
                                        class="h-1.5 flex-1 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10"
                                        aria-hidden="true"
                                    >
                                        <div class="h-full rounded-full bg-primary-500 {{ $ligne['largeur'] }}"></div>
                                    </div>

                                    <span class="shrink-0 text-xs tabular-nums text-gray-500 dark:text-gray-400">
                                        {{ $ligne['nombre'] }} vente{{ $ligne['nombre'] > 1 ? 's' : '' }}
                                    </span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach
    </div>
</x-filament::section>
