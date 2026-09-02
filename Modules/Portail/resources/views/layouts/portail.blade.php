{{-- Gabarit du site public.

     Hors panneau Filament, mais pas hors des conventions : aucune
     feuille de style propre au portail, aucun style inline — uniquement
     les utilitaires Tailwind du projet, compilés depuis
     resources/css/app.css, ou les couleurs et polices declarees dans son
     bloc @theme.

     Mobile d'abord : la mise en page de base vise le telephone, et les
     variantes sm: / lg: elargissent. C'est l'ordre qui correspond aux
     visiteurs attendus — la connexion la plus courante a Bafoussam est
     celle d'un telephone.

     Le menu mobile est un <details>, pas un composant JavaScript : il
     s'ouvre et se ferme sans une ligne de script, donc aussi bien sur un
     appareil ancien que derriere un bloqueur. Le portail ne charge
     aucune bibliotheque cliente.

     <main> n'impose aucune largeur : chaque page cadre ses propres
     sections. C'est ce qui permet a une banniere de couvrir toute la
     largeur de l'ecran pendant que le texte qui la suit reste lisible
     sur une colonne etroite. --}}
<!DOCTYPE html>
<html lang="fr" class="h-full scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>@yield('titre', 'Village Artisanal Régional de Bafoussam')</title>
    <meta name="description" content="@yield('description', 'Le Village Artisanal Régional de Bafoussam rassemble les artisans des huit départements de l\'Ouest Cameroun : sculpture, vannerie, tissage, perlerie, agroalimentaire. Découvrez leurs créations et venez les rencontrer.')">

    {{-- Partage sur les reseaux et les messageries. La vignette est un
         JPEG et non un WebP : plusieurs previsualiseurs, WhatsApp en
         tete, ne lisent pas le WebP et n'afficheraient aucune image. --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Village Artisanal Régional de Bafoussam">
    <meta property="og:locale" content="fr_FR">
    <meta property="og:title" content="@yield('titre', 'Village Artisanal Régional de Bafoussam')">
    <meta property="og:description" content="@yield('description', 'Les artisans de l\'Ouest Cameroun, leurs savoir-faire et leurs créations.')">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="@yield('partage', asset('images/portail/partage.jpg'))">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="icon" href="{{ asset('images/portail/identite/logo-varbaf-96.png') }}" sizes="any">
    <link rel="canonical" href="{{ url()->current() }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('tete')
</head>
<body class="flex min-h-full flex-col bg-craie-50 font-sans text-craie-900 antialiased">

{{-- Un visiteur au clavier ou au lecteur d'ecran arrive sur la
     navigation a chaque page ; ce lien lui donne le contenu en une
     touche. Invisible tant qu'il n'a pas le focus. --}}
<a href="#contenu"
   class="sr-only focus:not-sr-only focus:absolute focus:top-3 focus:left-3 focus:z-50 focus:rounded-md focus:bg-nuit-800 focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-white">
    Aller au contenu
</a>

@php($liens = [
    'portail.catalogue' => 'Catalogue',
    'portail.artisans' => 'Artisans',
    'portail.boutiques' => 'Les boutiques',
    'portail.village' => 'Le village',
    'portail.contact' => 'Contact',
])

<header class="sticky top-0 z-40 border-b border-craie-200 bg-craie-50/95 backdrop-blur">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3">
        <a href="{{ route('portail.accueil') }}" class="flex items-center gap-3">
            <img src="{{ asset('images/portail/identite/logo-varbaf-192.png') }}"
                 alt=""
                 width="192" height="204"
                 class="h-11 w-auto">
            <span class="leading-tight">
                <span class="block font-titre text-lg font-semibold tracking-tight text-nuit-900 sm:text-xl">
                    Village Artisanal
                </span>
                <span class="block text-xs tracking-[0.18em] text-terre-700 uppercase">
                    Régional de Bafoussam
                </span>
            </span>
        </a>

        {{-- Navigation de bureau. --}}
        <nav class="hidden items-center gap-6 text-sm lg:flex" aria-label="Navigation principale">
            @foreach ($liens as $route => $libelle)
                <a href="{{ route($route) }}"
                   @class([
                       'border-b-2 py-1 transition',
                       'border-terre-500 font-semibold text-nuit-900' => request()->routeIs($route),
                       'border-transparent text-craie-700 hover:border-craie-300 hover:text-nuit-900' => ! request()->routeIs($route),
                   ])
                   @if (request()->routeIs($route)) aria-current="page" @endif
                >{{ $libelle }}</a>
            @endforeach
        </nav>

        {{-- Navigation mobile, sans JavaScript. --}}
        <details class="relative lg:hidden">
            <summary class="flex cursor-pointer list-none items-center gap-2 rounded-md border border-craie-300 px-3 py-2 text-sm font-medium text-nuit-900">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M3 6h14M3 10h14M3 14h14" stroke-linecap="round"/>
                </svg>
                Menu
            </summary>
            <nav class="absolute right-0 mt-2 w-52 rounded-lg border border-craie-200 bg-white py-2 shadow-lg"
                 aria-label="Navigation principale">
                @foreach ($liens as $route => $libelle)
                    <a href="{{ route($route) }}"
                       @class([
                           'block px-4 py-2 text-sm',
                           'font-semibold text-terre-700' => request()->routeIs($route),
                           'text-craie-700 hover:bg-craie-100' => ! request()->routeIs($route),
                       ])
                    >{{ $libelle }}</a>
                @endforeach
            </nav>
        </details>
    </div>
</header>

<main id="contenu" class="flex-1">
    @yield('contenu')
</main>

<footer class="mt-16 border-t border-nuit-800 bg-nuit-900 text-nuit-200">
    <div class="mx-auto grid max-w-6xl gap-10 px-4 py-12 sm:grid-cols-2 lg:grid-cols-4">
        <div class="sm:col-span-2 lg:col-span-1">
            <img src="{{ asset('images/portail/identite/logo-varbaf-192.png') }}"
                 alt="Blason du Village Artisanal Régional de Bafoussam"
                 width="192" height="204"
                 class="h-14 w-auto rounded-sm bg-white p-1">
            <p class="mt-4 font-titre text-lg text-white">
                {{ $village?->nom ?? 'Village Artisanal Régional de Bafoussam' }}
            </p>
            <p class="mt-2 text-sm text-nuit-300">
                Le savoir-faire des artisans de l'Ouest Cameroun, réuni en un seul lieu.
            </p>
        </div>

        <div>
            <h2 class="text-sm font-semibold tracking-wide text-white uppercase">Découvrir</h2>
            <ul class="mt-4 space-y-2 text-sm">
                @foreach ($liens as $route => $libelle)
                    <li>
                        <a href="{{ route($route) }}" class="text-nuit-300 transition hover:text-white">{{ $libelle }}</a>
                    </li>
                @endforeach
            </ul>
        </div>

        <div>
            <h2 class="text-sm font-semibold tracking-wide text-white uppercase">Nous trouver</h2>
            <address class="mt-4 space-y-2 text-sm not-italic text-nuit-300">
                @if ($village?->adresse)
                    <p>{{ $village->adresse }}</p>
                @endif
                <p>{{ $village?->region ? $village->region.', Cameroun' : 'Région de l\'Ouest, Cameroun' }}</p>
                @if ($village?->telephone)
                    <p>
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $village->telephone) }}"
                           class="transition hover:text-white">{{ $village->telephone }}</a>
                    </p>
                @endif
                @if ($village?->email)
                    <p>
                        <a href="mailto:{{ $village->email }}" class="transition hover:text-white">{{ $village->email }}</a>
                    </p>
                @endif
            </address>
        </div>

        <div>
            <h2 class="text-sm font-semibold tracking-wide text-white uppercase">Acheter</h2>
            {{-- Le portail ne vend pas, ne commande pas, n'encaisse pas.
                 Le dire ici, une fois, evite au visiteur de chercher un
                 panier qui n'existera jamais. --}}
            <p class="mt-4 text-sm text-nuit-300">
                Les créations présentées ici s'achètent sur place, dans les boutiques du village.
                Le site ne prend ni commande ni paiement.
            </p>
            <a href="{{ route('portail.contact') }}"
               class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-terre-300 transition hover:text-terre-200">
                Préparer une visite
                <span aria-hidden="true">&rarr;</span>
            </a>
        </div>
    </div>

    <div class="border-t border-nuit-800">
        <div class="mx-auto max-w-6xl px-4 py-5 text-xs text-nuit-400">
            <p>
                &copy; {{ now()->year }} {{ $village?->nom ?? 'Village Artisanal Régional de Bafoussam' }}.
                Photographies prises au village.
            </p>
        </div>
    </div>
</footer>

</body>
</html>
