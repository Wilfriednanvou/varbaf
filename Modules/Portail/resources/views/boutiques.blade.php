@extends('portail::layouts.portail')

@section('titre', 'Les boutiques — Village Artisanal Régional de Bafoussam')
@section('description', 'Les boutiques du Village Artisanal Régional de Bafoussam, numérotées B01 à B17, abritent les ateliers et les vitrines des artisans de la région de l\'Ouest.')

@section('contenu')
    <section class="border-b border-craie-200 bg-white">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:py-16">
            <nav class="text-sm text-craie-600" aria-label="Fil d'Ariane">
                <a href="{{ route('portail.accueil') }}" class="transition hover:text-terre-700">Accueil</a>
                <span class="mx-1.5 text-craie-400" aria-hidden="true">/</span>
                <span class="text-craie-800">Les boutiques</span>
            </nav>

            <h1 class="mt-4 font-titre text-4xl font-semibold text-nuit-900 sm:text-5xl">Les boutiques</h1>

            <p class="mt-5 max-w-2xl text-craie-700">
                Le bâtiment compte {{ $locaux->count() }} locaux de vente, numérotés
                B01 à B{{ str_pad((string) $locaux->count(), 2, '0', STR_PAD_LEFT) }}.
                Plusieurs artisans cohabitent couramment dans un même local, chacun sur son
                espace. Entrez : la plupart travaillent devant vous.
            </p>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:py-16">
        @if ($locaux->isEmpty())
            <p class="rounded-lg border border-dashed border-craie-300 bg-white p-10 text-center text-craie-600">
                Le relevé des boutiques sera publié prochainement.
            </p>
        @else
            <ul class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($locaux as $local)
                    <li class="overflow-hidden rounded-lg border border-craie-200 bg-white">
                        <x-portail::illustration
                            :source="$visuels[$local->numero] ?? null"
                            :alt="'Intérieur de la boutique '.$local->numero"
                            :motif="$local->numero"
                            ratio="paysage" />

                        <div class="p-5">
                            <h2 class="font-titre text-lg font-semibold text-nuit-900">
                                Boutique {{ $local->numero }}
                            </h2>

                            {{-- L'emplacement vient du plan detenu par la
                                 coordination ; il est nul tant qu'il n'a pas
                                 ete releve, et la ligne disparait plutot que
                                 d'afficher un vide. --}}
                            @if ($local->emplacement)
                                <p class="mt-1 text-sm text-craie-600">{{ $local->emplacement }}</p>
                            @endif

                            @unless (isset($visuels[$local->numero]))
                                <p class="mt-2 text-sm text-craie-500">Photographie à venir.</p>
                            @endunless
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="mt-14 rounded-lg border border-terre-200 bg-terre-50 p-8">
            <h2 class="font-titre text-xl font-semibold text-terre-900">Qui occupe quelle boutique ?</h2>
            <p class="mt-3 max-w-2xl text-sm text-terre-900/80">
                Les attributions changent au fil des saisons et des campagnes : plutôt que d'afficher
                ici une répartition qui serait fausse le mois prochain, le site vous oriente vers
                l'annuaire des artisans, tenu à jour, ou vers l'accueil du village.
            </p>
            <div class="mt-6 flex flex-wrap gap-3">
                <a href="{{ route('portail.artisans') }}"
                   class="rounded-md bg-terre-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-terre-700">
                    Annuaire des artisans
                </a>
                <a href="{{ route('portail.contact') }}"
                   class="rounded-md border border-terre-300 px-4 py-2.5 text-sm font-semibold text-terre-800 transition hover:bg-terre-100">
                    Poser la question à l'accueil
                </a>
            </div>
        </div>
    </section>
@endsection
