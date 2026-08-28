<x-filament-panels::page>
    {{-- Toute la mise en forme de cette page était écrite en
         `style="..."` — trente-six déclarations, dont plusieurs
         inopérantes : `rgba(var(--primary-500), 0.1)` est une syntaxe
         Filament 3, où les variables de couleur portaient un triplet
         RVB. Sous Filament 5 elles portent une couleur complète, et ces
         fonds ne s'affichaient tout simplement pas. Un style inline ne
         se plaint jamais : il ne s'applique pas, et c'est tout. --}}
    <x-filament::section>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="rounded-lg bg-primary-50 p-3 dark:bg-primary-500/10">
                    <x-filament::icon
                        icon="heroicon-o-document-text"
                        class="h-6 w-6 text-primary-600 dark:text-primary-400"
                    />
                </div>

                <div>
                    <h2 class="text-xl font-bold text-gray-950 dark:text-white">
                        {{ $caisseRecord->code }} — {{ $caisseRecord->libelle }}
                    </h2>

                    <p class="mt-1 flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                        <x-filament::icon icon="heroicon-o-building-office-2" class="h-4 w-4" />
                        {{ $caisseRecord->village->nom }}
                    </p>
                </div>
            </div>

            {{-- Le bouton « Imprimer » a été retiré : il pointait sur
                 `href="#"` et ne faisait rien. Aucune route d'impression
                 du brouillard n'existe, et en inventer une sortirait du
                 périmètre arrêté. Un bouton mort est pire que pas de
                 bouton : il promet une fonction, et c'est devant un jury
                 qu'on découvre qu'elle n'existe pas. À reprendre en v2,
                 sur le modèle des états de reversement, qui eux ont
                 leur gabarit PDF. --}}
            <x-filament::button
                icon="heroicon-o-arrow-left"
                color="gray"
                variant="outlined"
                tag="a"
                :href="\Modules\Tresorerie\Filament\Pages\ManageCaisseSession::getUrl(['caisse' => $caisseRecord->id, 'section' => $sectionRecord->id])"
            >
                Retour à la session
            </x-filament::button>
        </div>
    </x-filament::section>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="xl:col-span-2">
            <x-filament::section>
                <form wire:submit="filter" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                    <div class="flex-1">
                        <label for="brouillard-date-debut" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">
                            Date de début
                        </label>
                        <x-filament::input.wrapper>
                            <x-filament::input id="brouillard-date-debut" type="date" wire:model="date_debut" />
                        </x-filament::input.wrapper>
                    </div>

                    <div class="flex-1">
                        <label for="brouillard-date-fin" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">
                            Date de fin
                        </label>
                        <x-filament::input.wrapper>
                            <x-filament::input id="brouillard-date-fin" type="date" wire:model="date_fin" />
                        </x-filament::input.wrapper>
                    </div>

                    <x-filament::button type="submit" icon="heroicon-o-funnel">
                        Filtrer
                    </x-filament::button>
                </form>
            </x-filament::section>
        </div>

        <x-filament::section>
            {{-- Entrées et sorties se lisent l'une contre l'autre : le
                 vert et le rouge sont ici des états, pas une palette de
                 séries — ils gardent donc les couleurs réservées de
                 Filament, et le libellé porte le sens sans la couleur. --}}
            <div class="flex h-full items-center justify-around gap-4">
                <div class="text-center">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total entrées</p>
                    <p class="mt-1 text-xl font-bold tabular-nums text-success-600 dark:text-success-400">
                        {{ number_format($this->total_entrees, 0, ',', ' ') }} FCFA
                    </p>
                </div>

                <div class="h-12 w-px bg-gray-200 dark:bg-white/10"></div>

                <div class="text-center">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total sorties</p>
                    <p class="mt-1 text-xl font-bold tabular-nums text-danger-600 dark:text-danger-400">
                        {{ number_format($this->total_sorties, 0, ',', ' ') }} FCFA
                    </p>
                </div>
            </div>
        </x-filament::section>
    </div>

    <div>
        {{ $this->table }}
    </div>
</x-filament-panels::page>
