@extends('portail::layouts.portail')

@section('titre', $artisan->nom_complet.' — Village Artisanal Régional de Bafoussam')
@section('description', $artisan->nom_complet.($artisan->corpsMetier ? ', '.mb_strtolower($artisan->corpsMetier->libelle) : '').' au Village Artisanal Régional de Bafoussam. Découvrez ses créations et venez le rencontrer sur place.')

@section('contenu')
    <section class="border-b border-craie-200 bg-white">
        <div class="mx-auto max-w-6xl px-4 py-12 sm:py-16">
            <nav class="text-sm text-craie-600" aria-label="Fil d'Ariane">
                <a href="{{ route('portail.accueil') }}" class="transition hover:text-terre-700">Accueil</a>
                <span class="mx-1.5 text-craie-400" aria-hidden="true">/</span>
                <a href="{{ route('portail.artisans') }}" class="transition hover:text-terre-700">Artisans</a>
                <span class="mx-1.5 text-craie-400" aria-hidden="true">/</span>
                <span class="text-craie-800">{{ $artisan->nom_complet }}</span>
            </nav>

            <div class="mt-8 flex flex-col gap-7 sm:flex-row sm:items-center">
                <x-portail::illustration
                    :photo="$artisan->photo"
                    :source="$artisan->corpsMetier ? config('portail.visuels.metiers.'.$artisan->corpsMetier->code) : null"
                    :alt="'Portrait de '.$artisan->nom_complet"
                    :motif="$artisan->nom_complet"
                    ratio="carre"
                    sizes="8rem"
                    class="w-32 shrink-0 rounded-lg" />

                <div>
                    @if ($artisan->corpsMetier)
                        <p class="text-xs font-semibold tracking-[0.18em] text-terre-700 uppercase">
                            {{ $artisan->corpsMetier->libelle }}
                        </p>
                    @endif

                    <h1 class="mt-2 font-titre text-4xl font-semibold text-nuit-900 sm:text-5xl">
                        {{ $artisan->nom_complet }}
                    </h1>

                    <p class="mt-4 max-w-xl text-craie-700">
                        Retrouvez ses créations au Village Artisanal Régional de Bafoussam.
                        L'achat se fait sur place, dans sa boutique.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-12 sm:py-16">
        <h2 class="font-titre text-2xl font-semibold text-nuit-900">Ses créations</h2>

        @if ($produits->isEmpty())
            <p class="mt-6 rounded-lg border border-dashed border-craie-300 bg-white p-12 text-center text-craie-600">
                Aucune création présentée en ligne pour le moment.
            </p>
        @else
            <div class="mt-7 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($produits as $publication)
                    <x-portail::vignette-produit
                        :publication="$publication"
                        :disponibilite="$disponibilites[$publication->getKey()]"
                    />
                @endforeach
            </div>
        @endif

        <div class="mt-12 rounded-lg border border-terre-200 bg-terre-50 p-6 text-sm text-terre-900">
            Pour rencontrer {{ $artisan->nom_complet }} ou commander une pièce,
            <a href="{{ route('portail.contact') }}" class="font-semibold underline">écrivez au village</a> :
            l'accueil vous indiquera sa boutique et ses jours de présence.
        </div>
    </section>
@endsection
