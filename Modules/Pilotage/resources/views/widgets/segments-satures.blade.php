<x-filament::section
    heading="Segments où l'offre se concentre"
    :description="'Produits proches à '.number_format($seuil, 2, ',', ' ').' ou plus, portés par au moins '.$minimum.' artisans distincts'"
>
    @if ($segments->isEmpty())
        <p>Aucun segment saturé : aucun groupe de produits très proches n'est porté par plusieurs artisans.</p>
    @else
        <p>
            Matière au conseil aux artisans et à l'orientation des formations. Un segment dense peut être
            la spécialité du village autant qu'une saturation — l'indicateur signale, il ne juge pas.
        </p>

        <table>
            <thead>
            <tr>
                <th>Référence</th>
                <th>Désignation</th>
                <th>Artisan</th>
                <th>Autres artisans</th>
                <th>Similarité moyenne</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($segments as $segment)
                <tr>
                    <td>{{ $segment['reference'] }}</td>
                    <td>{{ $segment['designation'] }}</td>
                    <td>{{ $segment['artisan'] ?: '—' }}</td>
                    <td>{{ $segment['concurrents'] }}</td>
                    <td>{{ number_format($segment['similarite_moyenne'], 3, ',', ' ') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</x-filament::section>
