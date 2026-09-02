@extends('portail::layouts.portail')

@section('titre', 'Village Artisanal Régional de Bafoussam')
@section('description', 'Sculpture, vannerie, tissage, perlerie, agroalimentaire : les artisans de l\'Ouest Cameroun exposent leurs créations au Village Artisanal Régional de Bafoussam. Parcourez le catalogue et venez les rencontrer.')

@section('contenu')

    {{-- ================================================================
         Banniere. La photographie porte le sujet du site en une image :
         un mur de masques sculptes, pris dans une boutique du village.
         Le voile bleu-nuit n'est pas decoratif — sans lui, le texte
         blanc passerait par endroits sous le seuil de contraste lisible.
         ================================================================ --}}
    <section class="relative isolate overflow-hidden bg-nuit-900">
        <img src="{{ asset('images/portail/metiers/sculpture-mur-1600.webp') }}"
             alt=""
             width="1600" height="1200"
             fetchpriority="high"
             class="absolute inset-0 -z-10 h-full w-full object-cover opacity-45">
        <div class="absolute inset-0 -z-10 bg-gradient-to-b from-nuit-950/80 via-nuit-900/70 to-nuit-900"></div>

        <div class="mx-auto max-w-6xl px-4 py-20 sm:py-28 lg:py-32">
            <p class="text-xs font-semibold tracking-[0.22em] text-terre-300 uppercase">
                Région de l'Ouest &middot; Cameroun
            </p>

            <h1 class="mt-5 max-w-3xl font-titre text-4xl leading-tight font-semibold text-white sm:text-5xl lg:text-6xl">
                Le savoir-faire des artisans de l'Ouest, réuni en un seul lieu
            </h1>

            <p class="mt-6 max-w-2xl text-lg text-nuit-100">
                Masques sculptés, vannerie, tissage du ndop, perlerie, produits du terroir :
                les artisans du village travaillent, exposent et vendent sur place.
                Parcourez leurs créations, puis venez les rencontrer.
            </p>

            <div class="mt-9 flex flex-wrap gap-3">
                <a href="{{ route('portail.catalogue') }}"
                   class="rounded-md bg-terre-500 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-terre-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-terre-300">
                    Parcourir le catalogue
                </a>
                <a href="{{ route('portail.artisans') }}"
                   class="rounded-md border border-nuit-300/40 px-5 py-3 text-sm font-semibold text-white transition hover:border-nuit-200 hover:bg-white/5">
                    Rencontrer les artisans
                </a>
            </div>
        </div>
    </section>

    {{-- ================================================================
         Reperes. Quatre comptes, aucun montant : le site est une
         vitrine, pas un rapport de gestion.
         ================================================================ --}}
    <section class="border-b border-craie-200 bg-white" aria-label="Le village en chiffres">
        <dl class="mx-auto grid max-w-6xl grid-cols-2 gap-px overflow-hidden bg-craie-200 px-4 sm:grid-cols-4 sm:px-0">
            @foreach ([
                ['valeur' => $reperes['artisans'], 'libelle' => 'artisans en vitrine', 'zero' => 'artisan en vitrine'],
                ['valeur' => $reperes['metiers'], 'libelle' => 'corps de métier représentés', 'zero' => 'corps de métier représenté'],
                ['valeur' => $reperes['creations'], 'libelle' => 'créations publiées', 'zero' => 'création publiée'],
                ['valeur' => $reperes['locaux'], 'libelle' => 'boutiques dans le village', 'zero' => 'boutique dans le village'],
            ] as $repere)
                <div class="bg-white px-4 py-7 text-center">
                    <dt class="sr-only">{{ $repere['libelle'] }}</dt>
                    <dd>
                        <span class="block font-titre text-3xl font-semibold text-nuit-900 sm:text-4xl">
                            {{ number_format($repere['valeur'], 0, ',', ' ') }}
                        </span>
                        <span class="mt-1 block text-xs tracking-wide text-craie-600 uppercase">
                            {{ $repere['valeur'] > 1 ? $repere['libelle'] : $repere['zero'] }}
                        </span>
                    </dd>
                </div>
            @endforeach
        </dl>
    </section>

    {{-- ================================================================
         Introduction editoriale. Le texte vient de la table des contenus
         de page, redigee par la coordination ; le repli garde la page
         complete tant qu'elle ne l'a pas fait.
         ================================================================ --}}
    <section class="mx-auto max-w-6xl px-4 py-16 sm:py-20">
        <div class="grid gap-12 lg:grid-cols-2 lg:items-center">
            <div>
                <h2 class="font-titre text-3xl font-semibold text-nuit-900 sm:text-4xl">
                    {{ $introduction?->titre ?? 'Un village, et non un marché' }}
                </h2>

                <div class="mt-5 space-y-4 text-craie-700">
                    @if ($introduction)
                        <p class="whitespace-pre-line">{{ $introduction->corps }}</p>
                    @else
                        <p>
                            Le Village Artisanal Régional de Bafoussam n'est pas une galerie marchande :
                            c'est un lieu d'encadrement, de production et d'exposition, où les artisans
                            de la région travaillent leurs matières et présentent leurs pièces.
                        </p>
                        <p>
                            Chaque boutique du bâtiment abrite un ou plusieurs artisans, et chaque pièce
                            exposée a été façonnée ici ou dans un atelier de la région.
                        </p>
                    @endif
                </div>

                <a href="{{ route('portail.village') }}"
                   class="mt-7 inline-flex items-center gap-1.5 text-sm font-semibold text-terre-700 transition hover:text-terre-800">
                    Découvrir le village
                    <span aria-hidden="true">&rarr;</span>
                </a>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <x-portail::illustration
                    source="{{ config('portail.visuels.village.preau') }}"
                    alt="Le préau à colonnes du village artisanal, un jour d'exposition"
                    ratio="carre"
                    class="col-span-2 rounded-lg" />
                <x-portail::illustration
                    source="{{ config('portail.visuels.creations.perles-etal') }}"
                    alt="Sacs et bijoux en perles présentés sur une étoffe ndop"
                    ratio="carre"
                    class="rounded-lg" />
                <x-portail::illustration
                    source="{{ config('portail.visuels.metiers.VAN') }}"
                    alt="Étagère en bambou garnie d'objets de vannerie"
                    ratio="carre"
                    class="rounded-lg" />
            </div>
        </div>
    </section>

    {{-- ================================================================
         Les corps de metier reellement representes au catalogue. La
         liste n'est pas ecrite en dur : elle suit ce qui est publie, et
         disparait toute entiere si rien ne l'est.
         ================================================================ --}}
    @if ($corpsMetiers->isNotEmpty())
        <section class="border-y border-craie-200 bg-white py-16 sm:py-20">
            <div class="mx-auto max-w-6xl px-4">
                <h2 class="font-titre text-3xl font-semibold text-nuit-900">Les métiers du village</h2>
                <p class="mt-3 max-w-2xl text-craie-700">
                    Chaque métier a sa matière, ses gestes et ses artisans. Choisissez-en un pour
                    ne voir que ses créations.
                </p>

                <ul class="mt-9 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($corpsMetiers as $metier)
                        <li>
                            <a href="{{ route('portail.catalogue', ['metier' => $metier->id]) }}"
                               class="group block overflow-hidden rounded-lg border border-craie-200 transition hover:border-terre-300 hover:shadow-md">
                                <x-portail::illustration
                                    :source="config('portail.visuels.metiers.'.$metier->code)"
                                    :alt="'Créations du corps de métier : '.$metier->libelle"
                                    :motif="$metier->libelle"
                                    ratio="paysage" />
                                <div class="p-5">
                                    <h3 class="font-titre text-lg font-semibold text-nuit-900 group-hover:text-terre-700">
                                        {{ $metier->libelle }}
                                    </h3>
                                    @if ($metier->description)
                                        <p class="mt-1.5 line-clamp-2 text-sm text-craie-600">{{ $metier->description }}</p>
                                    @endif
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    {{-- ================================================================
         Artisans a l'honneur.
         ================================================================ --}}
    @if ($vedettes->isNotEmpty())
        <section class="mx-auto max-w-6xl px-4 py-16 sm:py-20">
            <h2 class="font-titre text-3xl font-semibold text-nuit-900">Artisans à l'honneur</h2>

            <div class="mt-9 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($vedettes as $vedette)
                    <article class="flex flex-col rounded-lg border border-craie-200 bg-white p-6">
                        <div class="flex items-center gap-4">
                            <x-portail::illustration
                                :photo="$vedette->artisan?->photo"
                                :alt="$vedette->artisan?->nom_complet ?? ''"
                                :motif="$vedette->artisan?->nom_complet ?? ''"
                                ratio="carre"
                                class="w-16 shrink-0 rounded-full" />
                            <div>
                                <h3 class="font-titre text-lg font-semibold text-nuit-900">
                                    {{ $vedette->artisan?->nom_complet }}
                                </h3>
                                <p class="text-sm text-terre-700">{{ $vedette->artisan?->corpsMetier?->libelle }}</p>
                            </div>
                        </div>

                        <p class="mt-4 flex-1 text-sm text-craie-700">{{ $vedette->texte }}</p>

                        @if ($vedette->artisan)
                            <a href="{{ route('portail.artisan', $vedette->artisan->matricule) }}"
                               class="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-terre-700 transition hover:text-terre-800">
                                Voir sa fiche
                                <span aria-hidden="true">&rarr;</span>
                            </a>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ================================================================
         Dernieres creations publiees.
         ================================================================ --}}
    <section class="border-t border-craie-200 bg-white py-16 sm:py-20">
        <div class="mx-auto max-w-6xl px-4">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <h2 class="font-titre text-3xl font-semibold text-nuit-900">Dernières créations</h2>
                <a href="{{ route('portail.catalogue') }}"
                   class="text-sm font-semibold text-terre-700 transition hover:text-terre-800">
                    Tout le catalogue &rarr;
                </a>
            </div>

            @if ($nouveautes->isEmpty())
                <p class="mt-8 rounded-lg border border-dashed border-craie-300 bg-craie-50 p-10 text-center text-craie-600">
                    Le catalogue sera bientôt en ligne.
                </p>
            @else
                <div class="mt-9 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($nouveautes as $publication)
                        <x-portail::vignette-produit
                            :publication="$publication"
                            :disponibilite="$disponibilites[$publication->getKey()]"
                        />
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    {{-- ================================================================
         Appel a la visite. Le portail ne vend pas : le seul geste qu'il
         propose est de venir.
         ================================================================ --}}
    <section class="relative isolate overflow-hidden bg-nuit-900">
        <img src="{{ asset('images/portail/village/exposition-1600.webp') }}"
             alt=""
             width="1600" height="1200"
             loading="lazy" decoding="async"
             class="absolute inset-0 -z-10 h-full w-full object-cover opacity-30">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:py-20">
            <h2 class="max-w-2xl font-titre text-3xl font-semibold text-white sm:text-4xl">
                Les créations s'achètent au village, pas en ligne
            </h2>
            <p class="mt-4 max-w-2xl text-nuit-100">
                Le site présente ce que font les artisans ; l'achat se fait sur place, en boutique.
                Écrivez-nous pour préparer une visite, un groupe ou une commande d'atelier.
            </p>
            <div class="mt-8 flex flex-wrap gap-3">
                <a href="{{ route('portail.contact') }}"
                   class="rounded-md bg-terre-500 px-5 py-3 text-sm font-semibold text-white transition hover:bg-terre-600">
                    Nous écrire
                </a>
                <a href="{{ route('portail.boutiques') }}"
                   class="rounded-md border border-nuit-300/40 px-5 py-3 text-sm font-semibold text-white transition hover:border-nuit-200 hover:bg-white/5">
                    Voir les boutiques
                </a>
            </div>
        </div>
    </section>
@endsection
