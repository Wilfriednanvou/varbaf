@extends('portail::layouts.portail')

@section('titre', 'Catalogue — Village Artisanal de Bafoussam')

@section('contenu')
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Catalogue</h1>
    <p class="mt-2 text-stone-600">
        Les créations actuellement présentées par les artisans du village.
    </p>

    {{-- Filtres en GET : une recherche filtrée reste partageable par son
         adresse, et le formulaire fonctionne sans JavaScript. --}}
    <form method="GET" action="{{ route('portail.catalogue') }}"
          class="mt-6 grid gap-3 rounded-lg border border-stone-200 bg-white p-4 sm:grid-cols-3">
        <label class="flex flex-col gap-1 text-sm">
            <span class="font-medium text-stone-700">Catégorie</span>
            <select name="categorie" class="rounded-md border-stone-300 text-sm">
                <option value="">Toutes les catégories</option>
                @foreach ($categories as $categorie)
                    <option value="{{ $categorie->id }}" @selected($categorieChoisie === $categorie->id)>
                        {{ $categorie->libelle }}
                    </option>
                @endforeach
            </select>
        </label>

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

        <div class="flex items-end gap-2">
            <button type="submit"
                    class="rounded-md bg-amber-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-amber-800">
                Filtrer
            </button>

            @if ($categorieChoisie || $corpsMetierChoisi)
                <a href="{{ route('portail.catalogue') }}"
                   class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-600 transition hover:border-amber-400">
                    Tout voir
                </a>
            @endif
        </div>
    </form>

    @if ($catalogue->isEmpty())
        <p class="mt-8 rounded-lg border border-dashed border-stone-300 p-8 text-center text-stone-500">
            Aucune création ne correspond à cette recherche.
        </p>
    @else
        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($catalogue as $publication)
                <x-portail::vignette-produit
                    :publication="$publication"
                    :disponibilite="$disponibilites[$publication->getKey()]"
                />
            @endforeach
        </div>

        <div class="mt-8">
            {{ $catalogue->links() }}
        </div>
    @endif
@endsection
