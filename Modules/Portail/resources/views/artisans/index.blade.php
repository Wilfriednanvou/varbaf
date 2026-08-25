@extends('portail::layouts.portail')

@section('titre', 'Les artisans — Village Artisanal de Bafoussam')

@section('contenu')
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Les artisans</h1>
    <p class="mt-2 max-w-2xl text-stone-600">
        Seuls figurent ici les artisans qui ont donné leur accord pour être présentés sur ce site.
    </p>

    <form method="GET" action="{{ route('portail.artisans') }}"
          class="mt-6 flex flex-wrap items-end gap-3 rounded-lg border border-stone-200 bg-white p-4">
        <label class="flex flex-col gap-1 text-sm">
            <span class="font-medium text-stone-700">Corps de métier</span>
            <select name="metier" class="rounded-md border-stone-300 text-sm">
                <option value="">Tous les corps de métier</option>
                @foreach ($corpsMetiers as $metier)
                    <option value="{{ $metier->id }}" @selected($corpsMetierChoisi === $metier->id)>
                        {{ $metier->libelle }}
                    </option>
                @endforeach
            </select>
        </label>

        <button type="submit"
                class="rounded-md bg-amber-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-amber-800">
            Filtrer
        </button>
    </form>

    @if ($artisans->isEmpty())
        <p class="mt-8 rounded-lg border border-dashed border-stone-300 p-8 text-center text-stone-500">
            Aucun artisan à présenter pour le moment.
        </p>
    @else
        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($artisans as $artisan)
                <a href="{{ route('portail.artisan', $artisan->matricule) }}"
                   class="rounded-lg border border-stone-200 bg-white p-5 transition hover:border-amber-300">
                    <h2 class="font-medium text-stone-900">{{ $artisan->nom_complet }}</h2>
                    <p class="mt-1 text-sm text-stone-500">{{ $artisan->corpsMetier?->libelle }}</p>
                    <span class="mt-3 inline-block text-sm font-medium text-amber-700">Voir sa fiche</span>
                </a>
            @endforeach
        </div>

        <div class="mt-8">
            {{ $artisans->links() }}
        </div>
    @endif
@endsection
