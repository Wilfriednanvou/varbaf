<x-filament::section
    heading="Produits sans équivalent au catalogue"
    :description="$total.' produit(s) dont aucun voisin n\'atteint une similarité de '.number_format($seuil, 2, ',', ' ')"
>
    @if ($produits->isEmpty())
        <p>Aucun produit isolé : chaque article du catalogue a au moins un proche.</p>
    @else
        <p>
            Candidats naturels à une mise en avant sur le portail : ils ne se noient dans aucun rayon.
        </p>

        <table>
            <thead>
            <tr>
                <th>Référence</th>
                <th>Désignation</th>
                <th>Artisan</th>
                <th>Meilleure similarité</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($produits as $produit)
                <tr>
                    <td>{{ $produit['reference'] }}</td>
                    <td>{{ $produit['designation'] }}</td>
                    <td>{{ $produit['artisan'] ?: '—' }}</td>
                    <td>{{ number_format($produit['meilleure'], 3, ',', ' ') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        @if ($total > $produits->count())
            <p>{{ $total - $produits->count() }} autre(s) produit(s) isolé(s) — voir le catalogue.</p>
        @endif
    @endif
</x-filament::section>
