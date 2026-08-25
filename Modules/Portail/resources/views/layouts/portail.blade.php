{{-- Gabarit du site public. Hors panneau Filament, mais pas hors des
     conventions : aucune feuille de style propre au portail, aucun style
     inline — uniquement les utilitaires Tailwind du projet, compilés
     depuis resources/css/app.css grâce à la directive @source ajoutée
     pour Modules/**/*.blade.php.

     Mobile d'abord : la mise en page de base vise le téléphone, et les
     variantes sm: / lg: élargissent. C'est l'ordre qui correspond aux
     visiteurs attendus. --}}
<!DOCTYPE html>
<html lang="fr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="@yield('description', 'Le Village Artisanal Régional de Bafoussam : artisans, savoir-faire et créations de l\'Ouest Cameroun.')">
    <title>@yield('titre', 'Village Artisanal Régional de Bafoussam')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-full flex-col bg-stone-50 text-stone-800 antialiased">

<header class="border-b border-stone-200 bg-white">
    <div class="mx-auto flex max-w-6xl flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
        <a href="{{ route('portail.accueil') }}" class="text-lg font-semibold tracking-tight text-stone-900">
            Village Artisanal <span class="text-amber-700">de Bafoussam</span>
        </a>

        <nav class="flex flex-wrap gap-x-5 gap-y-2 text-sm">
            @php($liens = [
                'portail.catalogue' => 'Catalogue',
                'portail.artisans' => 'Artisans',
                'portail.village' => 'Le village',
                'portail.contact' => 'Contact',
            ])
            @foreach ($liens as $route => $libelle)
                <a
                    href="{{ route($route) }}"
                    @class([
                        'transition hover:text-amber-700',
                        'font-semibold text-amber-700' => request()->routeIs($route),
                        'text-stone-600' => ! request()->routeIs($route),
                    ])
                >{{ $libelle }}</a>
            @endforeach
        </nav>
    </div>
</header>

<main class="mx-auto w-full max-w-6xl flex-1 px-4 py-8 sm:py-12">
    @yield('contenu')
</main>

<footer class="border-t border-stone-200 bg-white">
    <div class="mx-auto max-w-6xl px-4 py-6 text-sm text-stone-500">
        <p>Village Artisanal Régional de Bafoussam — Région de l'Ouest, Cameroun.</p>
        <p class="mt-1">
            Ce site présente les créations des artisans du village. Les achats se font sur place, en boutique.
        </p>
    </div>
</footer>

</body>
</html>
