<x-filament-panels::page>

    {{-- LE FIL

         Chaque tour porte ses badges : la branche qui a répondu, la
         catégorie, le moteur mobilisé, et le rédacteur quand un modèle
         a tourné la phrase. Ils ne sont pas décoratifs — c'est ce qu'on
         regarde changer, réseau coupé, pour démontrer le repli. --}}
    @foreach ($echanges as $tour)
        <div class="space-y-3">

            {{-- La saisie, telle qu'elle a été tapée. --}}
            <div class="flex justify-end">
                <div class="max-w-2xl rounded-lg bg-gray-100 px-4 py-2 text-sm dark:bg-gray-800">
                    {{ $tour['saisie'] }}
                </div>
            </div>

            <x-filament::section>
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-filament::badge :color="$tour['brancheColor']">
                            {{ $tour['brancheLabel'] }}
                        </x-filament::badge>

                        <x-filament::badge color="gray">
                            {{ $tour['categorie'] }}
                        </x-filament::badge>

                        @if ($tour['moteur'])
                            <x-filament::badge color="primary">
                                {{ $tour['moteur'] }}
                            </x-filament::badge>
                        @endif

                        @if ($tour['redacteur'])
                            <x-filament::badge color="warning">
                                {{ $tour['redacteur'] }}
                            </x-filament::badge>
                        @endif
                    </div>

                    {{-- Une question reconstruite s'affiche.

                         Le modèle transforme « et en juillet ? » en une
                         question autonome, qui repart ensuite dans le
                         routeur déterministe. C'est le seul endroit de
                         la chaîne où le sens d'une demande peut se
                         déplacer : il doit donc être visible. Le même
                         principe que le nommage du moteur et le champ
                         « rédacteur » — ce qui est audité à l'écran ne
                         peut pas dériver en silence. --}}
                    @if ($tour['reformulation'])
                        <p class="text-sm text-gray-500">
                            Question comprise : <span class="font-medium">{{ $tour['reformulation'] }}</span>
                        </p>
                    @endif

                    <p class="whitespace-pre-line">{{ $tour['texte'] }}</p>

                    @if ($tour['parametres'] !== [])
                        <dl class="grid gap-2 text-sm sm:grid-cols-2">
                            @foreach ($tour['parametres'] as $cle => $valeur)
                                <div>
                                    <dt class="text-gray-500">{{ str_replace('_', ' ', $cle) }}</dt>
                                    <dd class="font-medium">{{ $valeur }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif

                    @if ($tour['lignes'] !== [])
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                <tr>
                                    <th class="text-left">Libellé</th>
                                    <th class="text-left">Détail</th>
                                    <th class="text-right">Nombre</th>
                                    <th class="text-right">Montant</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($tour['lignes'] as $ligne)
                                    <tr>
                                        <td>{{ $ligne['libelle'] ?? '—' }}</td>
                                        <td>{{ $ligne['detail'] ?? '—' }}</td>
                                        <td class="text-right">{{ number_format($ligne['nombre'] ?? 0, 0, ',', ' ') }}</td>
                                        <td class="text-right">{{ number_format($ligne['total'] ?? 0, 0, ',', ' ') }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </x-filament::section>

            {{-- Garde-fou 3 : une réponse descriptive s'accompagne
                 toujours de ses sources, titre et extrait, pour que le
                 lecteur remonte à la fiche et vérifie. --}}
            @if ($tour['sources'] !== [])
                <x-filament::section
                    heading="Sources mobilisées"
                    :description="count($tour['sources']).' extrait(s) du corpus indexé'"
                    collapsible
                    collapsed
                >
                    <ul class="space-y-3 text-sm">
                        @foreach ($tour['sources'] as $source)
                            <li>
                                <div class="flex flex-wrap items-baseline gap-2">
                                    <span class="font-medium">{{ $source['titre'] }}</span>
                                    <x-filament::badge color="gray" size="xs">
                                        {{ $source['type'] }}
                                    </x-filament::badge>
                                    <span class="text-gray-500">similarité {{ $source['pourcentage'] }} %</span>
                                </div>
                                <p class="text-gray-600">{{ $source['extrait'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                </x-filament::section>
            @endif

            @if ($tour['intentionLibelle'])
                <x-filament::section heading="Comment la question a été traitée" collapsible collapsed>
                    <dl class="grid gap-2 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-gray-500">Intention reconnue</dt>
                            <dd class="font-medium">{{ $tour['intentionLibelle'] }} ({{ $tour['intention'] }})</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Source du résultat</dt>
                            <dd class="font-medium">Méthode nommée de RapportService — aucune requête générée</dd>
                        </div>
                    </dl>
                </x-filament::section>
            @endif
        </div>
    @endforeach

    {{-- LA SAISIE, sous le fil : c'est là que le regard revient. --}}
    <x-filament::section heading="Poser une question">
        <div class="space-y-4">
            <x-filament::input.wrapper>
                <x-filament::input
                    type="text"
                    wire:model="question"
                    wire:keydown.enter="interroger"
                    placeholder="Quel est le chiffre d'affaires en juillet ?"
                />
            </x-filament::input.wrapper>

            <div class="flex flex-wrap gap-2">
                <x-filament::button wire:click="interroger" icon="heroicon-o-magnifying-glass">
                    Interroger
                </x-filament::button>

                <x-filament::button wire:click="reinitialiser" color="gray" outlined>
                    Effacer
                </x-filament::button>
            </div>

            {{-- Deux précautions, chacune pour un piège distinct.

                 Le `wire:click` est porté par un `<button>` ordinaire et
                 non par le composant badge : Blade ne compile pas les
                 valeurs d'attributs d'un composant, il les passe telles
                 quelles. Un `@js()` ou une interpolation écrite là en sortait
                 littéralement dans le HTML, et les cinq boutons étaient
                 inertes sans qu'aucune assertion de contenu ne le voie.

                 Et c'est le rang qui circule, jamais le libellé : une
                 apostrophe dans « chiffre d'affaires » traverserait
                 trois échappements successifs — HTML, JavaScript,
                 expression Livewire — et casserait l'appel. --}}
            <div class="flex flex-wrap gap-2">
                @foreach ($this->exemples() as $rang => $exemple)
                    <button type="button" wire:click="poser({{ $rang }})" class="cursor-pointer">
                        <x-filament::badge color="gray">
                            {{ $exemple }}
                        </x-filament::badge>
                    </button>
                @endforeach
            </div>

            @if ($echanges !== [])
                <p class="text-sm text-gray-500">
                    L'assistant tient compte des questions précédentes de cet échange.
                    « Effacer » repart de zéro.
                </p>
            @endif
        </div>
    </x-filament::section>
</x-filament-panels::page>
