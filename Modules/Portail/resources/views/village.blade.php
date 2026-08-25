@extends('portail::layouts.portail')

@section('titre', 'Le village — Village Artisanal Régional de Bafoussam')

@section('contenu')
    <h1 class="text-2xl font-semibold tracking-tight text-stone-900">Le Village Artisanal</h1>

    @if ($sections->isEmpty())
        {{-- Un contenu absent laisse une section vide, jamais une erreur :
             la page reste servie même si la coordination n'a pas encore
             rédigé ses textes. --}}
        <p class="mt-6 rounded-lg border border-dashed border-stone-300 p-8 text-center text-stone-500">
            La présentation du village sera publiée prochainement.
        </p>
    @else
        <div class="mt-6 space-y-6">
            @foreach ($sections as $section)
                <section class="rounded-lg border border-stone-200 bg-white p-6 sm:p-8">
                    <h2 class="text-lg font-semibold text-stone-900">{{ $section->titre }}</h2>
                    <p class="mt-3 whitespace-pre-line text-stone-700">{{ $section->corps }}</p>
                </section>
            @endforeach
        </div>
    @endif

    <div class="mt-10 rounded-lg border border-amber-200 bg-amber-50 p-6 text-amber-900">
        <h2 class="font-semibold">Venir au village</h2>
        <p class="mt-2 text-sm">
            Les boutiques sont ouvertes sur place. Pour préparer une visite de groupe ou poser une question,
            <a href="{{ route('portail.contact') }}" class="font-medium underline">écrivez-nous</a>.
        </p>
    </div>
@endsection
