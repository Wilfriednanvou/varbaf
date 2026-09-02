@extends('portail::layouts.portail')

@section('titre', 'Le village — Village Artisanal Régional de Bafoussam')
@section('description', 'Le Village Artisanal Régional de Bafoussam : un lieu d\'encadrement, de production et d\'exposition pour les artisans de la région de l\'Ouest Cameroun.')

@section('contenu')

    {{-- Banniere de page. La facade du batiment, prise un jour de
         ceremonie : c'est l'image que le visiteur reconnaitra en
         arrivant. --}}
    <section class="relative isolate overflow-hidden bg-nuit-900">
        <img src="{{ asset('images/portail/village/facade-960.webp') }}"
             alt=""
             width="960" height="460"
             class="absolute inset-0 -z-10 h-full w-full object-cover opacity-40">
        <div class="absolute inset-0 -z-10 bg-gradient-to-b from-nuit-950/75 to-nuit-900/85"></div>

        <div class="mx-auto max-w-6xl px-4 py-16 sm:py-24">
            <nav class="text-sm text-nuit-300" aria-label="Fil d'Ariane">
                <a href="{{ route('portail.accueil') }}" class="transition hover:text-white">Accueil</a>
                <span class="mx-1.5 text-nuit-500" aria-hidden="true">/</span>
                <span class="text-nuit-100">Le village</span>
            </nav>

            <h1 class="mt-5 max-w-3xl font-titre text-4xl font-semibold text-white sm:text-5xl">
                Le Village Artisanal Régional de Bafoussam
            </h1>

            <p class="mt-5 max-w-2xl text-lg text-nuit-100">
                Un lieu d'encadrement, de production et d'exposition, au service des artisans
                de la région de l'Ouest.
            </p>
        </div>
    </section>

    <div class="mx-auto max-w-6xl px-4 py-14 sm:py-20">
        @if ($sections->isEmpty())
            {{-- Un contenu absent laisse une section vide, jamais une
                 erreur : la page reste servie meme si la coordination n'a
                 pas encore redige ses textes. --}}
            <p class="rounded-lg border border-dashed border-craie-300 bg-white p-12 text-center text-craie-600">
                La présentation du village sera publiée prochainement.
            </p>
        @else
            <div class="grid gap-12 lg:grid-cols-[minmax(0,1fr)_22rem] lg:gap-16">
                <div class="space-y-12">
                    @foreach ($sections as $section)
                        <section>
                            <h2 class="font-titre text-2xl font-semibold text-nuit-900 sm:text-3xl">
                                {{ $section->titre }}
                            </h2>
                            <p class="mt-4 whitespace-pre-line text-craie-700">{{ $section->corps }}</p>
                        </section>
                    @endforeach
                </div>

                <aside class="space-y-5">
                    <x-portail::illustration
                        source="{{ config('portail.visuels.village.preau') }}"
                        alt="Le préau à colonnes du village, un jour d'exposition"
                        ratio="paysage"
                        sizes="22rem"
                        class="rounded-lg border border-craie-200" />
                    <x-portail::illustration
                        source="{{ config('portail.visuels.creations.sculpture-mur') }}"
                        alt="Un mur de masques sculptés dans une boutique du village"
                        ratio="paysage"
                        sizes="22rem"
                        class="rounded-lg border border-craie-200" />
                    <x-portail::illustration
                        source="{{ config('portail.visuels.creations.perles-etal') }}"
                        alt="Sacs et bijoux en perles présentés sur une étoffe ndop"
                        ratio="paysage"
                        sizes="22rem"
                        class="rounded-lg border border-craie-200" />
                </aside>
            </div>
        @endif
    </div>

    {{-- Le batiment, en une bande d'images. Les photographies ont ete
         prises au village ; aucune n'est une vue d'illustration achetee. --}}
    <section class="border-y border-craie-200 bg-white py-14 sm:py-20">
        <div class="mx-auto max-w-6xl px-4">
            <h2 class="font-titre text-2xl font-semibold text-nuit-900 sm:text-3xl">Le bâtiment</h2>
            <p class="mt-3 max-w-2xl text-craie-700">
                Un préau à colonnes, une enfilade de boutiques numérotées, et un espace
                d'exposition où se tiennent les foires et les remises d'attestations.
            </p>

            <div class="mt-9 grid gap-5 sm:grid-cols-3">
                <x-portail::illustration
                    source="{{ config('portail.visuels.village.facade') }}"
                    alt="La façade du village artisanal"
                    ratio="paysage"
                    class="rounded-lg" />
                <x-portail::illustration
                    source="{{ config('portail.visuels.village.preau') }}"
                    alt="Le préau à colonnes"
                    ratio="paysage"
                    class="rounded-lg" />
                <x-portail::illustration
                    source="{{ config('portail.visuels.village.exposition') }}"
                    alt="Un stand d'exposition sous le préau"
                    ratio="paysage"
                    class="rounded-lg" />
            </div>

            <a href="{{ route('portail.boutiques') }}"
               class="mt-8 inline-flex items-center gap-1.5 text-sm font-semibold text-terre-700 transition hover:text-terre-800">
                Voir les boutiques une à une
                <span aria-hidden="true">&rarr;</span>
            </a>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:py-20">
        <div class="rounded-lg border border-terre-200 bg-terre-50 p-8 sm:p-10">
            <h2 class="font-titre text-2xl font-semibold text-terre-900">Venir au village</h2>
            <p class="mt-3 max-w-2xl text-sm text-terre-900/80">
                Les boutiques sont ouvertes sur place. Pour préparer une visite de groupe,
                une commande d'atelier ou poser une question, écrivez à la coordination.
            </p>
            <a href="{{ route('portail.contact') }}"
               class="mt-6 inline-block rounded-md bg-terre-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-terre-700">
                Nous écrire
            </a>
        </div>
    </section>
@endsection
