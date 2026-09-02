@extends('portail::layouts.portail')

@section('titre', 'Catalogue — Village Artisanal Régional de Bafoussam')
@section('description', 'Toutes les créations actuellement exposées par les artisans du Village Artisanal Régional de Bafoussam, filtrables par catégorie et par corps de métier.')

@section('contenu')
    <x-portail::entete
        titre="Catalogue"
        courant="Catalogue"
        chapo="Les créations actuellement présentées par les artisans du village. Chaque pièce est visible en boutique ; l'achat se fait sur place." />

    <div class="mx-auto max-w-6xl px-4 py-12 sm:py-14">

        {{-- Filtres en GET : une recherche filtree reste partageable par
             son adresse, et le formulaire fonctionne sans JavaScript. --}}
        <form method="GET" action="{{ route('portail.catalogue') }}"
              class="grid gap-4 rounded-lg border border-craie-200 bg-white p-5 sm:grid-cols-[1fr_1fr_auto]">
            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium text-craie-800">Catégorie</span>
                <select name="categorie"
                        class="rounded-md border border-craie-300 bg-white px-3 py-2 text-sm text-nuit-900 focus:border-terre-400 focus:ring-1 focus:ring-terre-400 focus:outline-none">
                    <option value="">Toutes les catégories</option>
                    @foreach ($categories as $categorie)
                        <option value="{{ $categorie->id }}" @selected($categorieChoisie === $categorie->id)>
                            {{ $categorie->libelle }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label class="flex flex-col gap-1.5 text-sm">
                <span class="font-medium text-craie-800">Corps de métier</span>
                <select name="metier"
                        class="rounded-md border border-craie-300 bg-white px-3 py-2 text-sm text-nuit-900 focus:border-terre-400 focus:ring-1 focus:ring-terre-400 focus:outline-none">
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
                        class="rounded-md bg-terre-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-terre-700">
                    Filtrer
                </button>

                @if ($categorieChoisie || $corpsMetierChoisi)
                    <a href="{{ route('portail.catalogue') }}"
                       class="rounded-md border border-craie-300 px-4 py-2.5 text-sm font-medium text-craie-700 transition hover:border-terre-300 hover:text-terre-700">
                        Tout voir
                    </a>
                @endif
            </div>
        </form>

        @if ($catalogue->isEmpty())
            <p class="mt-10 rounded-lg border border-dashed border-craie-300 bg-white p-12 text-center text-craie-600">
                Aucune création ne correspond à cette recherche.
            </p>
        @else
            <p class="mt-8 text-sm text-craie-600">
                {{ $catalogue->total() }}
                {{ $catalogue->total() > 1 ? 'créations présentées' : 'création présentée' }}
            </p>

            <div class="mt-4 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($catalogue as $publication)
                    <x-portail::vignette-produit
                        :publication="$publication"
                        :disponibilite="$disponibilites[$publication->getKey()]"
                    />
                @endforeach
            </div>

            <div class="mt-10">
                {{ $catalogue->links() }}
            </div>
        @endif
    </div>
@endsection
