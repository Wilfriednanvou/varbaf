<x-filament::section
    heading="Provenance des clients"
    description="La saisie est facultative au comptoir : les ventes sans provenance forment une ligne à part"
>
    @if (empty($lignes))
        <p>Aucune vente sur cette période.</p>
    @else
        <table>
            <thead>
            <tr>
                <th>Provenance</th>
                <th>Ventes</th>
                <th>Part</th>
                <th>Montant</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($lignes as $ligne)
                <tr>
                    <td>{{ $ligne['libelle'] }}</td>
                    <td>{{ $ligne['nombre'] }}</td>
                    <td>
                        {{ $totalVentes > 0 ? round($ligne['nombre'] * 100 / $totalVentes, 1) : 0 }} %
                    </td>
                    <td>{{ number_format($ligne['total'], 0, ',', ' ') }} FCFA</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</x-filament::section>
