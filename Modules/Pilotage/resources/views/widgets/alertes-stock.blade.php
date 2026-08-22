<x-filament::section
    heading="Produits sous le seuil d'alerte"
    :description="$total.' produit(s) surveillé(s) au niveau de leur seuil'"
>
    @if (empty($produits))
        <p>Aucun produit surveillé n'est retombé à son seuil.</p>
    @else
        <table>
            <thead>
            <tr>
                <th>Référence</th>
                <th>Désignation</th>
                <th>Boutique</th>
                <th>Stock</th>
                <th>Seuil</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($produits as $produit)
                <tr>
                    <td>{{ $produit['reference'] }}</td>
                    <td>{{ $produit['designation'] }}</td>
                    <td>{{ $produit['boutique'] ?? '—' }}</td>
                    <td>{{ $produit['stock'] }}</td>
                    <td>{{ $produit['seuil'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        @if ($total > count($produits))
            <p>{{ $total - count($produits) }} autre(s) produit(s) sous leur seuil — voir le catalogue.</p>
        @endif
    @endif
</x-filament::section>
