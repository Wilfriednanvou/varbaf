{{-- Illustration d'une vignette ou d'une fiche, avec repli dessine.

     **Trois sources, dans cet ordre.**

     1. `photo` — une photo versee dans le panneau, sur le disque public.
        C'est la seule qui represente vraiment l'objet montre.
     2. `source` — une photographie du village declaree dans
        `config('portail.visuels')`, servie en WebP a deux largeurs.
     3. A defaut, un motif dessine ici meme.

     **Le troisieme cas n'est pas une avarie, c'est le cas courant.** Aucun
     des produits du catalogue ne porte de photo, et neuf des quatorze corps
     de metier n'ont pas encore ete photographies. Un rectangle gris a la
     place de chaque vignette donnerait un catalogue en panne ; un aplat
     colore, stable pour un meme libelle, donne une grille qui se tient. Le
     jour ou les photos arriveront, elles prendront la place sans qu'une
     seule vue change.

     Le motif est **deterministe** : le meme libelle rend toujours le meme
     aplat, d'une page a l'autre et d'un rechargement au suivant. Une
     couleur tiree au hasard ferait clignoter le catalogue a chaque visite.

     `alt` est obligatoire et n'a pas de valeur par defaut : une image
     decorative se declare explicitement par `alt=""`. --}}

@props([
    'photo' => null,
    'source' => null,
    'alt' => '',
    'motif' => '',
    'ratio' => 'paysage',
    'sizes' => '(min-width: 1024px) 22rem, (min-width: 640px) 45vw, 92vw',
])

@php
    $proportions = match ($ratio) {
        'carre' => 'aspect-square',
        'portrait' => 'aspect-[3/4]',
        default => 'aspect-[4/3]',
    };

    // Quatre familles, prises dans la palette du site. L'empreinte du
    // libelle choisit la famille : deux corps de metier voisins dans la
    // liste tombent rarement sur la meme, et un meme corps de metier garde
    // la sienne pour toujours.
    $familles = [
        ['fond' => '#1e2749', 'trait' => '#4a5a96'],
        ['fond' => '#8a4623', 'trait' => '#dc8d54'],
        ['fond' => '#5c544a', 'trait' => '#b4aa98'],
        ['fond' => '#0f5126', 'trait' => '#17803d'],
    ];
    $empreinte = crc32(mb_strtolower($motif !== '' ? $motif : $alt));
    $famille = $familles[$empreinte % count($familles)];

    // Les initiales : au plus deux lettres, prises sur les deux premiers
    // mots du libelle. « Peau et cuir » donne PC, pas PEC — les mots de
    // liaison ne portent rien.
    $mots = preg_split('/[\s\-]+/u', trim($motif !== '' ? $motif : $alt), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $mots = array_values(array_filter($mots, fn (string $mot): bool => ! in_array(mb_strtolower($mot), ['et', 'de', 'du', 'des', 'la', 'le', 'les', "d'", 'en'], true)));
    $initiales = mb_strtoupper(implode('', array_map(fn (string $mot): string => mb_substr($mot, 0, 1), array_slice($mots, 0, 2))));

    $identifiant = 'motif-'.substr(md5((string) $empreinte), 0, 8);
@endphp

<div {{ $attributes->class([$proportions, 'overflow-hidden bg-craie-200']) }}>
    @if ($photo)
        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($photo) }}"
             alt="{{ $alt }}"
             loading="lazy" decoding="async"
             class="h-full w-full object-cover">
    @elseif ($source)
        <img src="{{ asset($source.'-800.webp') }}"
             srcset="{{ asset($source.'-400.webp') }} 400w, {{ asset($source.'-800.webp') }} 800w"
             sizes="{{ $sizes }}"
             alt="{{ $alt }}"
             loading="lazy" decoding="async"
             class="h-full w-full object-cover">
    @else
        {{-- Motif de repli. Trace en SVG plutot qu'en fichier : rien a
             telecharger, rien a versionner, et il s'adapte a n'importe
             quelle proportion. --}}
        <svg viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice"
             class="h-full w-full" role="img" aria-label="{{ $alt }}">
            <defs>
                {{-- Losanges inspires du ndop, l'etoffe bleu-nuit sur
                     laquelle les creations sont exposees au village. --}}
                <pattern id="{{ $identifiant }}" width="40" height="40" patternUnits="userSpaceOnUse">
                    <path d="M20 4 L36 20 L20 36 L4 20 Z" fill="none"
                          stroke="{{ $famille['trait'] }}" stroke-width="1.25" opacity="0.55"/>
                    <circle cx="20" cy="20" r="2.5" fill="{{ $famille['trait'] }}" opacity="0.45"/>
                </pattern>
            </defs>
            <rect width="400" height="300" fill="{{ $famille['fond'] }}"/>
            <rect width="400" height="300" fill="url(#{{ $identifiant }})"/>
            @if ($initiales !== '')
                <text x="200" y="150" text-anchor="middle" dominant-baseline="central"
                      font-family="Iowan Old Style, Palatino, Georgia, serif"
                      font-size="76" fill="#ffffff" opacity="0.82"
                      letter-spacing="4">{{ $initiales }}</text>
            @endif
        </svg>
    @endif
</div>
