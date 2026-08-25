@extends('portail::layouts.portail')

@section('titre', 'Contact — Village Artisanal de Bafoussam')

@section('contenu')
    <div class="mx-auto max-w-2xl">
        <h1 class="text-2xl font-semibold tracking-tight text-stone-900">
            {{ $introduction?->titre ?? 'Nous écrire' }}
        </h1>

        <p class="mt-2 text-stone-600">
            {{ $introduction?->corps ?? 'Une question sur une création, une visite de groupe, une commande ? Écrivez-nous, la coordination vous répondra.' }}
        </p>

        @if (session('succes'))
            <div class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                {{ session('succes') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mt-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                <p class="font-medium">Le formulaire n'a pas pu être envoyé :</p>
                <ul class="mt-2 list-inside list-disc">
                    @foreach ($errors->all() as $erreur)
                        <li>{{ $erreur }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('portail.contact.envoi') }}"
              class="mt-6 space-y-4 rounded-lg border border-stone-200 bg-white p-6">
            @csrf

            <label class="flex flex-col gap-1 text-sm">
                <span class="font-medium text-stone-700">Votre nom</span>
                <input type="text" name="nom" value="{{ old('nom') }}" required maxlength="120"
                       placeholder="Awa Nguemo"
                       class="rounded-md border-stone-300 text-sm">
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="font-medium text-stone-700">Téléphone ou adresse électronique</span>
                <input type="text" name="contact" value="{{ old('contact') }}" required maxlength="150"
                       placeholder="6 99 00 00 00 ou awa@exemple.cm"
                       class="rounded-md border-stone-300 text-sm">
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="font-medium text-stone-700">Sujet <span class="text-stone-400">(facultatif)</span></span>
                <input type="text" name="sujet" value="{{ old('sujet') }}" maxlength="150"
                       placeholder="Visite de groupe"
                       class="rounded-md border-stone-300 text-sm">
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="font-medium text-stone-700">Votre message</span>
                <textarea name="message" rows="6" required minlength="10" maxlength="2000"
                          placeholder="Décrivez votre demande"
                          class="rounded-md border-stone-300 text-sm">{{ old('message') }}</textarea>
            </label>

            <button type="submit"
                    class="w-full rounded-md bg-amber-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-amber-800 sm:w-auto">
                Envoyer le message
            </button>
        </form>
    </div>
@endsection
