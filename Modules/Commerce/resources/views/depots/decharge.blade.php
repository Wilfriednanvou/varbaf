{{-- Décharge de dépôt — gabarit destiné à dompdf, hors du panneau Filament.

     Les styles sont portés par le document lui-même : dompdf ne charge
     pas la feuille de style du thème, et une décharge doit rester
     lisible telle quelle sur papier, des années après son impression.
     C'est l'exception nommée à la règle CSS de CLAUDE.md, consignée
     dans docs/dette-technique.md. --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Décharge de dépôt {{ $depot->numero }}</title>
    <style>
        @page { margin: 20mm 18mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; }
        h1 { font-size: 15px; margin: 0 0 2px; text-transform: uppercase; letter-spacing: .5px; }
        .entete { border-bottom: 2px solid #1a1a1a; padding-bottom: 8px; margin-bottom: 14px; }
        .structure { font-size: 10px; color: #555; }
        .numero { float: right; text-align: right; font-size: 12px; font-weight: bold; }
        .parties { width: 100%; margin-bottom: 14px; }
        .parties td { vertical-align: top; width: 50%; padding-right: 12px; }
        .intitule { font-size: 9px; text-transform: uppercase; color: #666; letter-spacing: .5px; }
        table.articles { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.articles th { background: #f0f0f0; border: 1px solid #999; padding: 5px; text-align: left; font-size: 10px; }
        table.articles td { border: 1px solid #999; padding: 5px; }
        .nombre { text-align: right; }
        .total td { font-weight: bold; background: #f7f7f7; }
        .engagement { border: 1px solid #999; padding: 8px; margin-bottom: 18px; font-size: 10px; line-height: 1.5; }
        .signatures { width: 100%; margin-top: 6px; }
        .signatures td { width: 50%; padding-top: 4px; }
        .cadre-signature { border: 1px solid #999; height: 70px; margin-top: 4px; }
        .pied { margin-top: 16px; font-size: 9px; color: #777; text-align: center; }
    </style>
</head>
<body>

<div class="entete">
    <div class="numero">
        Décharge n° {{ $depot->numero }}<br>
        <span class="structure">{{ $depot->date_depot?->format('d/m/Y') }}</span>
    </div>
    <h1>Décharge de dépôt d'articles</h1>
    <div class="structure">
        {{ $village?->nom ?? 'Village Artisanal' }}<br>
        {{ $village?->adresse }}{{ $village?->telephone ? ' — Tél. '.$village->telephone : '' }}
    </div>
</div>

<table class="parties">
    <tr>
        <td>
            <div class="intitule">Déposant</div>
            <strong>{{ $depot->artisan?->nom_complet }}</strong><br>
            Matricule : {{ $depot->artisan?->matricule }}<br>
            Corps de métier : {{ $depot->artisan?->corpsMetier?->libelle ?? '—' }}<br>
            Téléphone : {{ $depot->artisan?->telephone ?? '—' }}
        </td>
        <td>
            <div class="intitule">Dépositaire</div>
            <strong>{{ $village?->nom ?? 'Village Artisanal' }}</strong><br>
            Boutique : {{ $depot->boutique?->numero }}<br>
            Exercice : {{ $depot->exercice?->libelle }}<br>
            Reçu par : {{ $depot->validePar?->name ?? '—' }}
        </td>
    </tr>
</table>

<table class="articles">
    <thead>
    <tr>
        <th style="width: 22%">Référence</th>
        <th>Désignation</th>
        <th style="width: 14%" class="nombre">Quantité</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($depot->lignes as $ligne)
        <tr>
            <td>{{ $ligne->reference_produit }}</td>
            <td>{{ $ligne->designation }}</td>
            <td class="nombre">{{ $ligne->quantite }}</td>
        </tr>
    @endforeach
    <tr class="total">
        <td colspan="2">Total des articles confiés</td>
        <td class="nombre">{{ $depot->nombreArticles() }}</td>
    </tr>
    </tbody>
</table>

@if (filled($depot->observations))
    <div class="intitule">Observations</div>
    <p>{{ $depot->observations }}</p>
@endif

<div class="engagement">
    Le Village Artisanal reconnaît avoir reçu en dépôt les articles listés ci-dessus, qui demeurent
    la propriété de l'artisan déposant jusqu'à leur vente ou leur restitution. Les articles sont
    confiés en vue de leur exposition et de leur commercialisation par le Village, moyennant la
    commission en vigueur à la date de chaque vente. L'artisan peut reprendre à tout moment les
    articles non vendus, dans la limite des quantités restant en dépôt.
</div>

<table class="signatures">
    <tr>
        <td>
            <div class="intitule">L'artisan déposant</div>
            <div>Lu et approuvé, le ....................................</div>
            <div class="cadre-signature"></div>
        </td>
        <td>
            <div class="intitule">Pour le Village Artisanal</div>
            <div>{{ $depot->validePar?->name ?? '' }}, le {{ $depot->date_validation?->format('d/m/Y') ?? '....................................' }}</div>
            <div class="cadre-signature"></div>
        </td>
    </tr>
</table>

<div class="pied">
    Document établi le {{ $genereLe }} — décharge n° {{ $depot->numero }} — exemplaire à conserver par chacune des parties.
</div>

</body>
</html>
