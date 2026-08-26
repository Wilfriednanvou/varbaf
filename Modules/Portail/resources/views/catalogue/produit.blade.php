@extends('portail::layouts.portail')

@php($produit = $publication->produit)

@section('titre', $produit->designation.' — Village Artisanal de Bafoussam')
@section('description', Str::limit(strip_tags((string) $publication->descriptionAffichee()), 150))

@section('contenu')
    <nav class="text-sm text-stone-500">
        <a href="{{ route('portail.catalogue') }}" class="hover:text-amber-700">Catalogue</a>
        <span class="mx-1">/</span>
        <span class="text-stone-700">{{ $produit->designation }}</span>
    </nav>

    <div class="mt-6 grid gap-8 lg:grid-cols-2">
        <div class="overflow-hidden rounded-lg border border-stone-200 bg-white">
            <div class="aspect-square w-full bg-stone-100">
                @if ($publication->photoAffichee())
                    <img
                        src="{{ asset('storage/'.$publication->photoAffichee()) }}"
                        alt="{{ $produit->designation }}"
                        class="h-full w-full object-cover"
                    >
                @else
                    <div class="flex h-full w-full items-center justify-center text-stone-400">
                        Photo à venir
                    </div>
                @endif
            </div>
        </div>

        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-stone-900">{{ $produit->designation }}</h1>

            <p class="mt-2 text-stone-600">
                @if ($produit->artisan)
                    Par
                    <a href="{{ route('portail.artisan', $produit->artisan->matricule) }}"
                       class="font-medium text-amber-700 hover:underline">
                        {{ $produit->artisan->nom_complet }}
                    </a>
                    @if ($produit->artisan->corpsMetier)
                        <span class="text-stone-400">— {{ $produit->artisan->corpsMetier->libelle }}</span>
                    @endif
                @endif
            </p>

            <div class="mt-6 flex flex-wrap items-center gap-3">
                <span class="text-2xl font-semibold text-stone-900">
                    {{ number_format((float) $produit->prix_unitaire, 0, ',', ' ') }} FCFA
                </span>

                {{-- Disponible ou sur commande. Jamais un nombre : le
                     stock du village ne se publie pas. --}}
                <span @class([
                    'rounded-full px-3 py-1 text-sm font-medium',
                    'bg-emerald-50 text-emerald-700' => $disponibilite === \Modules\Portail\Enums\DisponibilitePortail::DISPONIBLE,
                    'bg-amber-50 text-amber-700' => $disponibilite !== \Modules\Portail\Enums\DisponibilitePortail::DISPONIBLE,
                ])>
                    {{ $disponibilite->getLabel() }}
                </span>
            </div>

            @if ($publication->descriptionAffichee())
                <div class="mt-6 text-stone-700">
                    <p class="whitespace-pre-line">{{ $publication->descriptionAffichee() }}</p>
                </div>
            @endif

            <dl class="mt-6 grid grid-cols-2 gap-4 border-t border-stone-200 pt-6 text-sm">
                <div>
                    <dt class="text-stone-500">Référence</dt>
                    <dd class="mt-1 font-medium text-stone-900">{{ $produit->reference }}</dd>
                </div>
                @if ($produit->categorie)
                    <div>
                        <dt class="text-stone-500">Catégorie</dt>
                        <dd class="mt-1 font-medium text-stone-900">{{ $produit->categorie->libelle }}</dd>
                    </div>
                @endif
            </dl>

            <div class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                Les achats se font sur place, dans la boutique de l'artisan.
                <a href="{{ route('portail.contact') }}" class="font-medium underline">Contactez le village</a>
                pour préparer votre visite ou commander une pièce.
            </div>
        </div>
    </div>

    @if ($autres->isNotEmpty())
        <section class="mt-12">
            <h2 class="text-lg font-semibold text-stone-900">
                Autres créations de {{ $produit->artisan?->nom_complet }}
            </h2>

            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($autres as $autre)
                    <x-portail::vignette-produit
                        :publication="$autre"
                        :disponibilite="$disponibilites[$autre->getKey()]"
                    />
                @endforeach
            </div>
        </section>
    @endif

    {{-- Produits similaires. Le bloc ne s'affiche que s'il a quelque
         chose à montrer : une section vide dirait au visiteur que le
         village n'a rien d'approchant, ce qui est faux — elle dirait
         seulement que rien n'a franchi le seuil. --}}
    @if (($similaires ?? collect())->isNotEmpty())
        <section class="mt-12">
            <h2 class="text-lg font-semibold text-stone-900">
                Dans le même esprit
            </h2>

            <p class="mt-1 text-sm text-stone-500">
                Rapprochements établis à partir des désignations, catégories et corps de métier du catalogue.
                @if (! empty($moteurSimilarite))
                    <span class="text-stone-400">({{ $moteurSimilarite }})</span>
                @endif
            </p>

            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($similaires as $proche)
                    <x-portail::vignette-produit
                        :publication="$proche"
                        :disponibilite="$disponibilites[$proche->getKey()]"
                    />
                @endforeach
            </div>
        </section>
    @endif
@endsection
