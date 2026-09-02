@extends('portail::layouts.portail')

@section('titre', 'Les artisans — Village Artisanal Régional de Bafoussam')
@section('description', 'L\'annuaire des artisans du Village Artisanal Régional de Bafoussam qui ont donné leur accord pour être présentés : leur métier, leurs créations, et où les rencontrer.')

@section('contenu')
    <x-portail::entete
        titre="Les artisans"
        courant="Artisans"
        chapo="Seuls figurent ici les artisans qui ont donné leur accord pour être présentés sur ce site. Chacun travaille dans l'une des boutiques du village." />

    <div class="mx-auto max-w-6xl px-4 py-12 sm:py-14">
        <form method="GET" action="{{ route('portail.artisans') }}"
              class="flex flex-wrap items-end gap-4 rounded-lg border border-craie-200 bg-white p-5">
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

            <button type="submit"
                    class="rounded-md bg-terre-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-terre-700">
                Filtrer
            </button>

            @if ($corpsMetierChoisi)
                <a href="{{ route('portail.artisans') }}"
                   class="rounded-md border border-craie-300 px-4 py-2.5 text-sm font-medium text-craie-700 transition hover:border-terre-300 hover:text-terre-700">
                    Tout voir
                </a>
            @endif
        </form>

        @if ($artisans->isEmpty())
            <p class="mt-10 rounded-lg border border-dashed border-craie-300 bg-white p-12 text-center text-craie-600">
                Aucun artisan à présenter pour le moment.
            </p>
        @else
            <ul class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($artisans as $artisan)
                    <li>
                        <a href="{{ route('portail.artisan', $artisan->matricule) }}"
                           class="group flex h-full flex-col overflow-hidden rounded-lg border border-craie-200 bg-white transition hover:border-terre-300 hover:shadow-md">
                            <x-portail::illustration
                                :photo="$artisan->photo"
                                :source="$artisan->corpsMetier ? config('portail.visuels.metiers.'.$artisan->corpsMetier->code) : null"
                                :alt="'Portrait de '.$artisan->nom_complet"
                                :motif="$artisan->nom_complet"
                                ratio="paysage" />

                            <div class="flex flex-1 flex-col p-5">
                                <h2 class="font-titre text-lg font-semibold text-nuit-900 group-hover:text-terre-700">
                                    {{ $artisan->nom_complet }}
                                </h2>
                                <p class="mt-1 text-sm text-terre-700">{{ $artisan->corpsMetier?->libelle }}</p>
                                <span class="mt-auto pt-4 text-sm font-semibold text-craie-600 group-hover:text-terre-700">
                                    Voir sa fiche &rarr;
                                </span>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="mt-10">
                {{ $artisans->links() }}
            </div>
        @endif
    </div>
@endsection
