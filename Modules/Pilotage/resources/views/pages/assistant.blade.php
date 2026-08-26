<x-filament-panels::page>
    @php($reponse = $this->reponse())

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
        </div>
    </x-filament::section>

    @if ($reponse)
        <x-filament::section heading="Réponse">
            <div class="space-y-4">
                {{-- Les badges vivent dans le corps de la section, pas
                     dans un slot d'en-tête : `x-filament::section`
                     n'expose pas de slot de ce nom, et ce qui n'a pas de
                     slot ne rend rien — silencieusement. Ils ne sont pas
                     décoratifs : ils disent quelle branche a répondu et
                     quel moteur a été mobilisé, ce qui est précisément
                     ce qu'on regarde changer, réseau coupé, pour
                     démontrer le repli. --}}
                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::badge :color="$reponse->branche->getColor()">
                        {{ $reponse->branche->getLabel() }}
                    </x-filament::badge>

                    <x-filament::badge color="gray">
                        {{ $reponse->categorie->getLabel() }}
                    </x-filament::badge>

                    @if ($reponse->moteur)
                        <x-filament::badge color="primary">
                            {{ $reponse->moteur }}
                        </x-filament::badge>
                    @endif
                </div>

                <p class="whitespace-pre-line">{{ $reponse->texte }}</p>

                @if ($reponse->parametres !== [])
                    <dl class="grid gap-2 text-sm sm:grid-cols-2">
                        @foreach ($reponse->parametres as $cle => $valeur)
                            <div>
                                <dt class="text-gray-500">{{ str_replace('_', ' ', $cle) }}</dt>
                                <dd class="font-medium">{{ $valeur }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif

                @if ($reponse->lignes !== [])
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
                            @foreach ($reponse->lignes as $ligne)
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

        {{-- Garde-fou 3 : une réponse descriptive s'accompagne toujours
             de ses sources, titre et extrait, pour que le lecteur
             remonte à la fiche et vérifie. --}}
        @if ($reponse->sources->isNotEmpty())
            <x-filament::section
                heading="Sources mobilisées"
                :description="$reponse->sources->count().' extrait(s) du corpus indexé'"
                collapsible
            >
                <ul class="space-y-3 text-sm">
                    @foreach ($reponse->sources as $source)
                        <li>
                            <div class="flex flex-wrap items-baseline gap-2">
                                <span class="font-medium">{{ $source->titre }}</span>
                                <x-filament::badge color="gray" size="xs">
                                    {{ $source->type->getLabel() }}
                                </x-filament::badge>
                                <span class="text-gray-500">similarité {{ $source->pourcentage() }} %</span>
                            </div>
                            <p class="text-gray-600">{{ $source->extrait }}</p>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        @if ($reponse->intentionLibelle)
            <x-filament::section heading="Comment la question a été traitée" collapsible collapsed>
                <dl class="grid gap-2 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-gray-500">Intention reconnue</dt>
                        <dd class="font-medium">{{ $reponse->intentionLibelle }} ({{ $reponse->intention }})</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Source du résultat</dt>
                        <dd class="font-medium">Méthode nommée de RapportService — aucune requête générée</dd>
                    </div>
                </dl>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
