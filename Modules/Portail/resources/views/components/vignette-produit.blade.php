{{-- Vignette de produit.
     `$disponibilite` est calculée par le contrôleur et passée ici :
     la vue ne demande jamais l'état du stock, elle affiche ce qu'on lui
     donne. C'est ce qui rend structurellement impossible l'affichage
     d'une quantité. --}}
@props(['publication', 'disponibilite'])

@php($produit = $publication->produit)

<article class="flex flex-col overflow-hidden rounded-lg border border-stone-200 bg-white transition hover:border-amber-300">
    <a href="{{ route('portail.produit', $produit->reference) }}" class="flex flex-1 flex-col">
        <div class="aspect-square w-full bg-stone-100">
            @if ($publication->photoAffichee())
                <img
                    src="{{ asset('storage/'.$publication->photoAffichee()) }}"
                    alt="{{ $produit->designation }}"
                    class="h-full w-full object-cover"
                    loading="lazy"
                >
            @else
                <div class="flex h-full w-full items-center justify-center text-sm text-stone-400">
                    Photo à venir
                </div>
            @endif
        </div>

        <div class="flex flex-1 flex-col gap-2 p-4">
            <h3 class="font-medium text-stone-900">{{ $produit->designation }}</h3>

            <p class="text-sm text-stone-500">
                {{ $produit->artisan?->nom_complet }}
                @if ($produit->artisan?->corpsMetier)
                    <span class="text-stone-400">— {{ $produit->artisan->corpsMetier->libelle }}</span>
                @endif
            </p>

            <div class="mt-auto flex items-center justify-between pt-2">
                <span class="font-semibold text-stone-900">
                    {{ number_format((float) $produit->prix_unitaire, 0, ',', ' ') }} FCFA
                </span>

                <span @class([
                    'rounded-full px-2 py-0.5 text-xs font-medium',
                    'bg-emerald-50 text-emerald-700' => $disponibilite === \Modules\Portail\Enums\DisponibilitePortail::DISPONIBLE,
                    'bg-amber-50 text-amber-700' => $disponibilite !== \Modules\Portail\Enums\DisponibilitePortail::DISPONIBLE,
                ])>
                    {{ $disponibilite->getLabel() }}
                </span>
            </div>
        </div>
    </a>
</article>
