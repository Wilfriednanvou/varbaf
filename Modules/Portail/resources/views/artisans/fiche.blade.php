@extends('portail::layouts.portail')

@section('titre', $artisan->nom_complet.' — Village Artisanal de Bafoussam')

@section('contenu')
    <nav class="text-sm text-stone-500">
        <a href="{{ route('portail.artisans') }}" class="hover:text-amber-700">Artisans</a>
        <span class="mx-1">/</span>
        <span class="text-stone-700">{{ $artisan->nom_complet }}</span>
    </nav>

    <header class="mt-6 rounded-lg border border-stone-200 bg-white p-6 sm:p-8">
        <h1 class="text-2xl font-semibold tracking-tight text-stone-900">{{ $artisan->nom_complet }}</h1>

        @if ($artisan->corpsMetier)
            <p class="mt-1 text-stone-600">{{ $artisan->corpsMetier->libelle }}</p>
        @endif

        <p class="mt-4 text-sm text-stone-500">
            Retrouvez ses créations au Village Artisanal Régional de Bafoussam.
        </p>
    </header>

    <section class="mt-10">
        <h2 class="text-lg font-semibold text-stone-900">Ses créations</h2>

        @if ($produits->isEmpty())
            <p class="mt-4 rounded-lg border border-dashed border-stone-300 p-8 text-center text-stone-500">
                Aucune création présentée en ligne pour le moment.
            </p>
        @else
            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($produits as $publication)
                    <x-portail::vignette-produit
                        :publication="$publication"
                        :disponibilite="$disponibilites[$publication->getKey()]"
                    />
                @endforeach
            </div>
        @endif
    </section>
@endsection
