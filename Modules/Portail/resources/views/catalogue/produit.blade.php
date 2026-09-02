@extends('portail::layouts.portail')

@php($produit = $publication->produit)
@php($metier = $produit->artisan?->corpsMetier)

@section('titre', $produit->designation.' — Village Artisanal Régional de Bafoussam')
@section('description', Str::limit(strip_tags((string) ($publication->descriptionAffichee() ?: $produit->designation.($metier ? ', '.mb_strtolower($metier->libelle) : '').' — une création présentée au Village Artisanal Régional de Bafoussam.')), 150))

@section('contenu')
    <div class="mx-auto max-w-6xl px-4 pt-8 pb-12 sm:pt-10 sm:pb-16">
        <nav class="text-sm text-craie-600" aria-label="Fil d'Ariane">
            <a href="{{ route('portail.accueil') }}" class="transition hover:text-terre-700">Accueil</a>
            <span class="mx-1.5 text-craie-400" aria-hidden="true">/</span>
            <a href="{{ route('portail.catalogue') }}" class="transition hover:text-terre-700">Catalogue</a>
            <span class="mx-1.5 text-craie-400" aria-hidden="true">/</span>
            <span class="text-craie-800">{{ $produit->designation }}</span>
        </nav>

        <div class="mt-8 grid gap-10 lg:grid-cols-2 lg:gap-14">
            <x-portail::illustration
                :photo="$publication->photoAffichee()"
                :source="$metier ? config('portail.visuels.metiers.'.$metier->code) : null"
                :alt="$produit->designation"
                :motif="$metier?->libelle ?? $produit->designation"
                ratio="carre"
                sizes="(min-width: 1024px) 34rem, 92vw"
                class="rounded-lg border border-craie-200" />

            <div>
                @if ($metier)
                    <p class="text-xs font-semibold tracking-[0.18em] text-terre-700 uppercase">
                        {{ $metier->libelle }}
                    </p>
                @endif

                <h1 class="mt-2 font-titre text-3xl font-semibold text-nuit-900 sm:text-4xl">
                    {{ $produit->designation }}
                </h1>

                @if ($produit->artisan)
                    <p class="mt-3 text-craie-700">
                        Par
                        <a href="{{ route('portail.artisan', $produit->artisan->matricule) }}"
                           class="font-semibold text-terre-700 underline-offset-2 hover:underline">
                            {{ $produit->artisan->nom_complet }}
                        </a>
                    </p>
                @endif

                <div class="mt-7 flex flex-wrap items-center gap-3">
                    <span class="font-titre text-3xl font-semibold text-nuit-900">
                        {{ number_format((float) $produit->prix_unitaire, 0, ',', ' ') }} FCFA
                    </span>

                    {{-- Disponible ou sur commande. Jamais un nombre : le
                         stock du village ne se publie pas. --}}
                    <span @class([
                        'rounded-full px-3 py-1 text-sm font-medium',
                        'bg-feuille-50 text-feuille-700' => $disponibilite === \Modules\Portail\Enums\DisponibilitePortail::DISPONIBLE,
                        'bg-terre-50 text-terre-700' => $disponibilite !== \Modules\Portail\Enums\DisponibilitePortail::DISPONIBLE,
                    ])>
                        {{ $disponibilite->getLabel() }}
                    </span>
                </div>

                @if ($publication->descriptionAffichee())
                    <p class="mt-7 whitespace-pre-line text-craie-700">{{ $publication->descriptionAffichee() }}</p>
                @endif

                <dl class="mt-8 grid grid-cols-2 gap-5 border-t border-craie-200 pt-7 text-sm">
                    <div>
                        <dt class="text-craie-500">Référence</dt>
                        <dd class="mt-1 font-medium text-nuit-900">{{ $produit->reference }}</dd>
                    </div>
                    @if ($produit->categorie)
                        <div>
                            <dt class="text-craie-500">Catégorie</dt>
                            <dd class="mt-1 font-medium text-nuit-900">{{ $produit->categorie->libelle }}</dd>
                        </div>
                    @endif
                </dl>

                {{--
                    La fiche technique, telle que l'artisan l'a rédigée.

                    Les rubriques sont affichées dans leur ordre d'origine,
                    et aucune n'est filtrée : c'est le document du village
                    qui décide de ce qu'il dit d'une pièce, pas le portail.
                    Un produit sans fiche n'affiche rien — la majorité des
                    285 produits repris du registre est dans ce cas.
                --}}
                @if (filled($produit->caracteristiques))
                    <div class="mt-9 border-t border-craie-200 pt-7">
                        <h2 class="font-titre text-lg font-semibold text-nuit-900">Fiche technique</h2>
                        <dl class="mt-5 space-y-5">
                            @foreach ($produit->caracteristiques as $rubrique)
                                <div>
                                    <dt class="text-sm font-semibold text-nuit-900">{{ $rubrique['rubrique'] }}</dt>
                                    <dd class="mt-1 whitespace-pre-line text-sm text-craie-700">{{ $rubrique['contenu'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @endif

                <div class="mt-8 rounded-lg border border-terre-200 bg-terre-50 p-5 text-sm text-terre-900">
                    Les achats se font sur place, dans la boutique de l'artisan.
                    <a href="{{ route('portail.contact') }}" class="font-semibold underline">Écrivez au village</a>
                    pour préparer votre visite ou commander une pièce.
                </div>
            </div>
        </div>
    </div>

    @if ($autres->isNotEmpty())
        <section class="border-t border-craie-200 bg-white py-12 sm:py-16">
            <div class="mx-auto max-w-6xl px-4">
                <h2 class="font-titre text-2xl font-semibold text-nuit-900">
                    Autres créations de {{ $produit->artisan?->nom_complet }}
                </h2>

                <div class="mt-7 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($autres as $autre)
                        <x-portail::vignette-produit
                            :publication="$autre"
                            :disponibilite="$disponibilites[$autre->getKey()]"
                        />
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- Produits similaires. Le bloc ne s'affiche que s'il a quelque
         chose a montrer : une section vide dirait au visiteur que le
         village n'a rien d'approchant, ce qui est faux — elle dirait
         seulement que rien n'a franchi le seuil. --}}
    @if (($similaires ?? collect())->isNotEmpty())
        <section class="border-t border-craie-200 py-12 sm:py-16">
            <div class="mx-auto max-w-6xl px-4">
                <h2 class="font-titre text-2xl font-semibold text-nuit-900">Dans le même esprit</h2>

                <p class="mt-2 text-sm text-craie-600">
                    Rapprochements établis à partir des désignations, catégories et corps de métier du catalogue.
                    @if (! empty($moteurSimilarite))
                        <span class="text-craie-500">({{ $moteurSimilarite }})</span>
                    @endif
                </p>

                <div class="mt-7 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($similaires as $proche)
                        <x-portail::vignette-produit
                            :publication="$proche"
                            :disponibilite="$disponibilites[$proche->getKey()]"
                        />
                    @endforeach
                </div>
            </div>
        </section>
    @endif
@endsection
