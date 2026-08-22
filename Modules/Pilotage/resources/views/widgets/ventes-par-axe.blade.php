<x-filament::section heading="D'où vient le chiffre d'affaires" :description="'Ventes validées '.$intervalle">
    @foreach ($axes as $axe)
        <h3>{{ $axe['titre'] }}</h3>

        @if (empty($axe['lignes']))
            <p>Aucune vente sur cette période.</p>
        @else
            <table>
                <thead>
                <tr>
                    <th>Libellé</th>
                    <th>Ventes</th>
                    <th>Montant</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($axe['lignes'] as $ligne)
                    <tr>
                        <td>
                            {{ $ligne['libelle'] }}
                            @if ($ligne['detail'])
                                <small>{{ $ligne['detail'] }}</small>
                            @endif
                        </td>
                        <td>{{ $ligne['nombre'] }}</td>
                        <td>{{ number_format($ligne['total'], 0, ',', ' ') }} FCFA</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    @endforeach
</x-filament::section>
