@extends('portail::layouts.portail')

@section('titre', 'Contact — Village Artisanal Régional de Bafoussam')
@section('description', 'Écrivez au Village Artisanal Régional de Bafoussam : question sur une création, visite de groupe, commande d\'atelier. La coordination vous répond.')

@section('contenu')
    <x-portail::entete
        :titre="$introduction?->titre ?? 'Nous écrire'"
        courant="Contact"
        :chapo="$introduction?->corps ?? 'Une question sur une création, une visite de groupe, une commande d\'atelier ? Écrivez-nous, la coordination vous répondra.'" />

    <div class="mx-auto max-w-6xl px-4 py-12 sm:py-16">
        <div class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_20rem] lg:gap-14">

            <div>
                @if (session('succes'))
                    <div class="mb-6 rounded-lg border border-feuille-500/30 bg-feuille-50 p-4 text-sm text-feuille-700"
                         role="status">
                        {{ session('succes') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800"
                         role="alert">
                        <p class="font-semibold">Le formulaire n'a pas pu être envoyé :</p>
                        <ul class="mt-2 list-inside list-disc">
                            @foreach ($errors->all() as $erreur)
                                <li>{{ $erreur }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('portail.contact.envoi') }}"
                      class="space-y-5 rounded-lg border border-craie-200 bg-white p-6 sm:p-8">
                    @csrf

                    <label class="flex flex-col gap-1.5 text-sm">
                        <span class="font-medium text-craie-800">Votre nom</span>
                        <input type="text" name="nom" value="{{ old('nom') }}" required maxlength="120"
                               placeholder="Awa Nguemo"
                               class="rounded-md border border-craie-300 px-3 py-2.5 text-sm text-nuit-900 placeholder:text-craie-400 focus:border-terre-400 focus:ring-1 focus:ring-terre-400 focus:outline-none">
                    </label>

                    <label class="flex flex-col gap-1.5 text-sm">
                        <span class="font-medium text-craie-800">Téléphone ou adresse électronique</span>
                        <input type="text" name="contact" value="{{ old('contact') }}" required maxlength="150"
                               placeholder="6 99 00 00 00 ou awa@exemple.cm"
                               class="rounded-md border border-craie-300 px-3 py-2.5 text-sm text-nuit-900 placeholder:text-craie-400 focus:border-terre-400 focus:ring-1 focus:ring-terre-400 focus:outline-none">
                    </label>

                    <label class="flex flex-col gap-1.5 text-sm">
                        <span class="font-medium text-craie-800">
                            Sujet <span class="font-normal text-craie-500">(facultatif)</span>
                        </span>
                        <input type="text" name="sujet" value="{{ old('sujet') }}" maxlength="150"
                               placeholder="Visite de groupe"
                               class="rounded-md border border-craie-300 px-3 py-2.5 text-sm text-nuit-900 placeholder:text-craie-400 focus:border-terre-400 focus:ring-1 focus:ring-terre-400 focus:outline-none">
                    </label>

                    <label class="flex flex-col gap-1.5 text-sm">
                        <span class="font-medium text-craie-800">Votre message</span>
                        <textarea name="message" rows="7" required minlength="10" maxlength="2000"
                                  placeholder="Décrivez votre demande"
                                  class="rounded-md border border-craie-300 px-3 py-2.5 text-sm text-nuit-900 placeholder:text-craie-400 focus:border-terre-400 focus:ring-1 focus:ring-terre-400 focus:outline-none">{{ old('message') }}</textarea>
                    </label>

                    <button type="submit"
                            class="w-full rounded-md bg-terre-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-terre-700 sm:w-auto">
                        Envoyer le message
                    </button>
                </form>
            </div>

            {{-- Les coordonnees viennent de la fiche du village, comme au
                 pied de page : une seule source, saisie une fois dans le
                 panneau. --}}
            <aside class="space-y-6">
                <div class="rounded-lg border border-craie-200 bg-white p-6">
                    <h2 class="font-titre text-lg font-semibold text-nuit-900">Venir au village</h2>
                    <address class="mt-4 space-y-2 text-sm not-italic text-craie-700">
                        @if ($village?->adresse)
                            <p>{{ $village->adresse }}</p>
                        @endif
                        <p>{{ $village?->region ? $village->region.', Cameroun' : 'Région de l\'Ouest, Cameroun' }}</p>
                        @if ($village?->telephone)
                            <p>
                                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $village->telephone) }}"
                                   class="font-medium text-terre-700 hover:underline">{{ $village->telephone }}</a>
                            </p>
                        @endif
                        @if ($village?->email)
                            <p>
                                <a href="mailto:{{ $village->email }}"
                                   class="font-medium text-terre-700 hover:underline">{{ $village->email }}</a>
                            </p>
                        @endif
                    </address>
                </div>

                <x-portail::illustration
                    source="{{ config('portail.visuels.village.facade') }}"
                    alt="La façade du Village Artisanal Régional de Bafoussam"
                    ratio="paysage"
                    sizes="20rem"
                    class="rounded-lg border border-craie-200" />

                <p class="text-sm text-craie-600">
                    Le site ne prend ni commande ni paiement : les créations s'achètent
                    sur place, dans les boutiques du village.
                </p>
            </aside>
        </div>
    </div>
@endsection
