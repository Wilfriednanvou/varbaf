<x-filament::section heading="Soldes de caisse" description="Section ouverte de chaque caisse, à l'instant présent">
    @if (empty($soldes))
        <p>Aucune caisse n'a de section ouverte.</p>
    @else
        <table>
            <thead>
            <tr>
                <th>Caisse</th>
                <th>Solde</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($soldes as $ligne)
                <tr>
                    <td>{{ $ligne['code'] }} — {{ $ligne['libelle'] }}</td>
                    <td>{{ number_format($ligne['solde'], 0, ',', ' ') }} FCFA</td>
                </tr>
            @endforeach
            <tr>
                <td><strong>Consolidé</strong></td>
                <td><strong>{{ number_format($consolide, 0, ',', ' ') }} FCFA</strong></td>
            </tr>
            </tbody>
        </table>
    @endif
</x-filament::section>
