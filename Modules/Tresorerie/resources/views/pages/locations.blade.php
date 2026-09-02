<x-filament-panels::page>
    @php($totaux = $this->totaux())
    @php($orphelins = $this->orphelins())

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-filament::section>
            <div class="text-sm text-gray-500">Attributions en cours</div>
            <div class="text-2xl font-semibold">{{ number_format($totaux['attributions'], 0, ',', ' ') }}</div>
            <div class="text-xs text-gray-500">
                dont {{ $totaux['a_jour'] }} à jour
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500">Redevance mensuelle</div>
            <div class="text-2xl font-semibold">{{ number_format($totaux['mensuel'], 0, ',', ' ') }} F</div>
            <div class="text-xs text-gray-500">somme des mensualités convenues</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500">Dû à ce jour</div>
            <div class="text-2xl font-semibold">{{ number_format($totaux['du'], 0, ',', ' ') }} F</div>
            <div class="text-xs text-gray-500">mensualités échues, premier mois offert</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500">Reste à percevoir</div>
            <div class="text-2xl font-semibold {{ $totaux['reste'] > 0 ? 'text-danger-600' : 'text-success-600' }}">
                {{ number_format($totaux['reste'], 0, ',', ' ') }} F
            </div>
            <div class="text-xs text-gray-500">
                encaissé {{ number_format($totaux['encaisse'], 0, ',', ' ') }} F
            </div>
        </x-filament::section>
    </div>

    {{-- LE GARDE-FOU DE L'ÉCRAN

         Tant que des encaissements de redevance ne nomment aucune
         attribution, la colonne « encaissé » est incomplète et la
         colonne « reste » surévalue la dette. Le taire donnerait un
         écran qui a l'air juste et qui accuse des artisans à jour.
         C'est la même exigence que le nommage du moteur dans
         l'assistant : un système partiel doit le dire. --}}
    @if ($orphelins['nombre'] > 0)
        <x-filament::section>
            <div class="flex items-start gap-3">
                <x-filament::badge color="warning">Lecture incomplète</x-filament::badge>
                <p class="text-sm text-gray-600">
                    {{ $orphelins['nombre'] }} encaissement(s) de redevance,
                    pour {{ number_format($orphelins['montant'], 0, ',', ' ') }} F,
                    ne désignent aucune attribution : ils ne sont donc imputés à personne.
                    Les colonnes « encaissé » et « reste » ci-dessous les ignorent —
                    le reste affiché est un <strong>majorant</strong> de la dette réelle.
                    Rattachez-les depuis le brouillard pour que le tableau soit exact.
                </p>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section heading="Parc locatif">
        {{ $this->table }}
    </x-filament::section>

    <x-filament::section heading="Comment ces montants sont obtenus" collapsible collapsed>
        <div class="space-y-2 text-sm text-gray-600">
            <p>
                <strong>Le dû</strong> est dérivé, jamais saisi : nombre de mensualités échues
                depuis le début de facturation, multiplié par la redevance convenue et figée
                sur l'attribution (règle 13). Le premier mois suivant l'entrée dans les lieux
                est offert — c'est pourquoi le décompte part de la date de début de facturation
                et non de la date d'entrée.
            </p>
            <p>
                <strong>L'encaissé</strong> est la somme des mouvements de caisse de nature
                « redevance » rattachés à l'attribution. Aucun montant n'est recopié : le
                brouillard fait foi.
            </p>
            <p>
                <strong>Ce que cet écran ne dit pas.</strong> Sans échéancier — écarté du
                périmètre, dette DT-04 — l'écart est connu globalement mais pas par ancienneté :
                il n'y a pas de balance âgée, donc pas de priorisation des relances par
                nombre de mois de retard.
            </p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
