{{-- En-tete de page interieure : fil d'Ariane, titre, chapeau.

     Un composant plutot que le meme bloc recopie sur six pages — c'est
     la difference entre corriger un niveau de titre une fois et le
     corriger six fois, dont cinq qu'on oublie. --}}
@props(['titre', 'chapo' => null, 'courant' => null])

<section class="border-b border-craie-200 bg-white">
    <div class="mx-auto max-w-6xl px-4 py-14 sm:py-16">
        <nav class="text-sm text-craie-600" aria-label="Fil d'Ariane">
            <a href="{{ route('portail.accueil') }}" class="transition hover:text-terre-700">Accueil</a>
            @if ($courant)
                <span class="mx-1.5 text-craie-400" aria-hidden="true">/</span>
                <span class="text-craie-800">{{ $courant }}</span>
            @endif
        </nav>

        <h1 class="mt-4 font-titre text-4xl font-semibold text-nuit-900 sm:text-5xl">{{ $titre }}</h1>

        @if ($chapo)
            <p class="mt-5 max-w-2xl text-craie-700">{{ $chapo }}</p>
        @endif

        {{ $slot }}
    </div>
</section>
