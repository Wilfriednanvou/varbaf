{{-- Vignette de produit.

     `$disponibilite` est calculee par le controleur et passee ici : la
     vue ne demande jamais l'etat du stock, elle affiche ce qu'on lui
     donne. C'est ce qui rend structurellement impossible l'affichage
     d'une quantite.

     L'illustration passe par le composant commun. Quand la publication
     n'a pas de photo — le cas de tout le catalogue aujourd'hui —, le
     repli prend le libelle du corps de metier et non la designation du
     produit : deux pieces du meme artisan se rangent ainsi sous le meme
     aplat, et la grille se lit par metier au lieu de clignoter. --}}
@props(['publication', 'disponibilite'])

@php($produit = $publication->produit)
@php($metier = $produit->artisan?->corpsMetier)

<article class="group flex flex-col overflow-hidden rounded-lg border border-craie-200 bg-white transition hover:border-terre-300 hover:shadow-md">
    <a href="{{ route('portail.produit', $produit->reference) }}" class="flex flex-1 flex-col">
        <x-portail::illustration
            :photo="$publication->photoAffichee()"
            :source="$metier ? config('portail.visuels.metiers.'.$metier->code) : null"
            :alt="$produit->designation"
            :motif="$metier?->libelle ?? $produit->designation"
            ratio="carre" />

        <div class="flex flex-1 flex-col gap-2 p-4">
            <h3 class="font-titre text-lg leading-snug font-semibold text-nuit-900 group-hover:text-terre-700">
                {{ $produit->designation }}
            </h3>

            <p class="text-sm text-craie-600">
                {{ $produit->artisan?->nom_complet }}
                @if ($metier)
                    <span class="text-craie-500">&middot; {{ $metier->libelle }}</span>
                @endif
            </p>

            <div class="mt-auto flex flex-wrap items-center justify-between gap-2 pt-3">
                <span class="font-semibold text-nuit-900">
                    {{ number_format((float) $produit->prix_unitaire, 0, ',', ' ') }} FCFA
                </span>

                <span @class([
                    'rounded-full px-2.5 py-0.5 text-xs font-medium',
                    'bg-feuille-50 text-feuille-700' => $disponibilite === \Modules\Portail\Enums\DisponibilitePortail::DISPONIBLE,
                    'bg-terre-50 text-terre-700' => $disponibilite !== \Modules\Portail\Enums\DisponibilitePortail::DISPONIBLE,
                ])>
                    {{ $disponibilite->getLabel() }}
                </span>
            </div>
        </div>
    </a>
</article>
