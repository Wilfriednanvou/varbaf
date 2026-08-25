@extends('portail::layouts.portail')

@section('titre', 'Village Artisanal Régional de Bafoussam')

@section('contenu')
    <section class="rounded-lg border border-stone-200 bg-white p-6 sm:p-10">
        <h1 class="text-2xl font-semibold tracking-tight text-stone-900 sm:text-3xl">
            {{ $introduction?->titre ?? 'Le savoir-faire artisanal de l\'Ouest Cameroun' }}
        </h1>

        <div class="mt-4 max-w-2xl text-stone-600">
            @if ($introduction)
                <p class="whitespace-pre-line">{{ $introduction->corps }}</p>
            @else
                <p>
                    Vingt-quatre boutiques, des dizaines d'artisans, et des pièces façonnées à la main.
                    Parcourez le catalogue, découvrez les artisans, et venez les rencontrer sur place.
                </p>
            @endif
        </div>

        <div class="mt-6 flex flex-wrap gap-3">
            <a href="{{ route('portail.catalogue') }}"
               class="rounded-md bg-amber-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-amber-800">
                Parcourir le catalogue
            </a>
            <a href="{{ route('portail.artisans') }}"
               class="rounded-md border border-stone-300 px-4 py-2 text-sm font-medium text-stone-700 transition hover:border-amber-400">
                Rencontrer les artisans
            </a>
        </div>
    </section>

    @if ($vedettes->isNotEmpty())
        <section class="mt-10">
            <h2 class="text-lg font-semibold text-stone-900">Artisans à l'honneur</h2>

            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($vedettes as $vedette)
                    <article class="rounded-lg border border-stone-200 bg-white p-5">
                        <h3 class="font-medium text-stone-900">{{ $vedette->artisan?->nom_complet }}</h3>
                        <p class="text-sm text-stone-500">{{ $vedette->artisan?->corpsMetier?->libelle }}</p>
                        <p class="mt-3 text-sm text-stone-600">{{ $vedette->texte }}</p>

                        @if ($vedette->artisan)
                            <a href="{{ route('portail.artisan', $vedette->artisan->matricule) }}"
                               class="mt-4 inline-block text-sm font-medium text-amber-700 hover:underline">
                                Voir sa fiche
                            </a>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <section class="mt-10">
        <div class="flex items-baseline justify-between">
            <h2 class="text-lg font-semibold text-stone-900">Dernières créations</h2>
            <a href="{{ route('portail.catalogue') }}" class="text-sm font-medium text-amber-700 hover:underline">
                Tout le catalogue
            </a>
        </div>

        @if ($nouveautes->isEmpty())
            <p class="mt-4 rounded-lg border border-dashed border-stone-300 p-6 text-center text-stone-500">
                Le catalogue sera bientôt en ligne.
            </p>
        @else
            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($nouveautes as $publication)
                    <x-portail::vignette-produit
                        :publication="$publication"
                        :disponibilite="$disponibilites[$publication->getKey()]"
                    />
                @endforeach
            </div>
        @endif
    </section>
@endsection
