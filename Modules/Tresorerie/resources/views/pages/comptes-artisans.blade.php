<x-filament-panels::page>
    @php($totaux = $this->totaux())
    @php($taux = $this->tauxEnVigueur())

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-filament::section>
            <div class="text-sm text-gray-500">Chiffre d'affaires</div>
            <div class="text-2xl font-semibold">{{ number_format($totaux['vendu'], 0, ',', ' ') }} F</div>
            <div class="text-xs text-gray-500">ventes validées, tous artisans</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500">Commission du village</div>
            <div class="text-2xl font-semibold">{{ number_format($totaux['commission'], 0, ',', ' ') }} F</div>
            <div class="text-xs text-gray-500">
                @if ($taux)
                    taux en vigueur : {{ $taux->libelle() }}
                @else
                    aucun taux en vigueur
                @endif
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500">Déjà reversé</div>
            <div class="text-2xl font-semibold">{{ number_format($totaux['reverse'], 0, ',', ' ') }} F</div>
            <div class="text-xs text-gray-500">campagnes validées uniquement</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500">Dû aux artisans</div>
            <div class="text-2xl font-semibold {{ $totaux['du'] > 0 ? 'text-danger-600' : 'text-success-600' }}">
                {{ number_format($totaux['du'], 0, ',', ' ') }} F
            </div>
            <div class="text-xs text-gray-500">
                {{ $totaux['crediteurs'] }} artisan(s) à payer sur {{ $totaux['artisans'] }}
            </div>
        </x-filament::section>
    </div>

    <x-filament::section heading="Situation par artisan">
        {{ $this->table }}
    </x-filament::section>

    <x-filament::section heading="Ce que ce tableau engage" collapsible collapsed>
        <div class="space-y-2 text-sm text-gray-600">
            <p>
                <strong>Il informe, il ne paie pas.</strong> Le décaissement a lieu à la
                validation d'une campagne de reversement, et cette validation revient à un
                profil distinct de l'agent de saisie (RG-23). Consulter ce tableau n'engage
                aucun franc.
            </p>
            <p>
                <strong>Le solde dû n'est jamais stocké</strong> (RG-15) : il se recalcule à
                chaque affichage, somme des parts artisan moins somme des reversements des
                campagnes validées. Une campagne en préparation n'a rien décaissé et ne fait
                donc pas baisser le solde — afficher une dette éteinte alors que l'argent
                n'est pas sorti serait la pire des erreurs sur cet écran.
            </p>
            <p>
                <strong>Le taux affiché est celui d'aujourd'hui</strong>, avec sa date d'effet.
                Il n'explique pas les cumuls : chaque vente porte le taux figé à sa date
                (règle 10), et c'est celui-là qui a produit la part de l'artisan. Un
                changement de taux ne réécrit rien du passé.
            </p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
