{{-- Reçu de vente — gabarit destiné à dompdf, hors du panneau Filament.
     Même exception nommée à la règle CSS que la décharge de dépôt :
     dompdf ne charge pas la feuille du thème, et un reçu doit rester
     lisible tel quel sur papier. Consigné dans docs/dette-technique.md. --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Reçu de vente {{ $vente->numero }}</title>
    <style>
        @page { margin: 16mm 14mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; }
        h1 { font-size: 14px; margin: 0 0 2px; text-transform: uppercase; letter-spacing: .5px; }
        .entete { border-bottom: 2px solid #1a1a1a; padding-bottom: 8px; margin-bottom: 12px; }
        .structure { font-size: 10px; color: #555; }
        .numero { float: right; text-align: right; font-size: 12px; font-weight: bold; }
        .annulee { border: 2px solid #b00; color: #b00; padding: 6px; text-align: center;
                   font-weight: bold; text-transform: uppercase; margin-bottom: 12px; letter-spacing: 1px; }
        .infos { width: 100%; margin-bottom: 12px; }
        .infos td { vertical-align: top; width: 50%; padding-right: 12px; }
        .intitule { font-size: 9px; text-transform: uppercase; color: #666; letter-spacing: .5px; }
        table.articles { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.articles th { background: #f0f0f0; border: 1px solid #999; padding: 5px; text-align: left; font-size: 10px; }
        table.articles td { border: 1px solid #999; padding: 5px; }
        .nombre { text-align: right; }
        .total td { font-weight: bold; background: #f7f7f7; font-size: 12px; }
        .repartition { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .repartition td { padding: 4px 5px; border-bottom: 1px solid #ddd; }
        .mention { font-size: 9px; color: #555; line-height: 1.5; border-top: 1px solid #ccc; padding-top: 8px; }
        .pied { margin-top: 12px; font-size: 9px; color: #777; text-align: center; }
    </style>
</head>
<body>

<div class="entete">
    <div class="numero">
        Reçu n° {{ $vente->numero }}<br>
        <span class="structure">{{ $vente->date_vente?->format('d/m/Y à H:i') }}</span>
    </div>
    <h1>Reçu de vente</h1>
    <div class="structure">
        {{ $village?->nom ?? 'Village Artisanal' }}<br>
        {{ $village?->adresse }}{{ $village?->telephone ? ' — Tél. '.$village->telephone : '' }}
    </div>
</div>

@if ($vente->estAnnulee())
    <div class="annulee">
        Vente annulée le {{ $vente->date_annulation?->format('d/m/Y') }}
        @if (filled($vente->motif_annulation)) — {{ $vente->motif_annulation }} @endif
    </div>
@endif

<table class="infos">
    <tr>
        <td>
            <div class="intitule">Artisan</div>
            <strong>{{ $vente->artisan?->nom_complet }}</strong><br>
            Matricule : {{ $vente->artisan?->matricule }}<br>
            Boutique : {{ $vente->boutique?->numero }}
        </td>
        <td>
            <div class="intitule">Vente</div>
            Client : {{ $vente->libelleClient() }}<br>
            Règlement : {{ $vente->mode_reglement?->getLabel() }}<br>
            Vendeur : {{ $vente->vendeur?->nom_complet ?? '—' }}
        </td>
    </tr>
</table>

<table class="articles">
    <thead>
    <tr>
        <th style="width: 20%">Référence</th>
        <th>Désignation</th>
        <th style="width: 10%" class="nombre">Qté</th>
        <th style="width: 17%" class="nombre">Prix unitaire</th>
        <th style="width: 17%" class="nombre">Montant</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($vente->lignes as $ligne)
        <tr>
            <td>{{ $ligne->reference_produit }}</td>
            <td>{{ $ligne->designation }}</td>
            <td class="nombre">{{ $ligne->quantite }}</td>
            <td class="nombre">{{ number_format((float) $ligne->prix_unitaire, 0, ',', ' ') }}</td>
            <td class="nombre">{{ number_format((float) $ligne->montant_ligne, 0, ',', ' ') }}</td>
        </tr>
    @endforeach
    <tr class="total">
        <td colspan="4">Total encaissé (FCFA)</td>
        <td class="nombre">{{ number_format((float) $vente->montant_total, 0, ',', ' ') }}</td>
    </tr>
    </tbody>
</table>

<div class="intitule">Répartition</div>
<table class="repartition">
    <tr>
        <td>Commission du village ({{ rtrim(rtrim(number_format((float) $vente->taux_commission, 2, ',', ' '), '0'), ',') }} %)</td>
        <td class="nombre">{{ number_format((float) $vente->montant_commission, 0, ',', ' ') }} FCFA</td>
    </tr>
    <tr>
        <td>Part revenant à l'artisan</td>
        <td class="nombre">{{ number_format((float) $vente->part_artisan, 0, ',', ' ') }} FCFA</td>
    </tr>
</table>

<div class="mention">
    Le taux de commission appliqué est celui en vigueur à la date de la vente, figé sur celle-ci.
    La part revenant à l'artisan constitue une dette du Village Artisanal jusqu'à son reversement,
    effectué mensuellement selon le calendrier arrêté par la coordination.
</div>

<div class="pied">
    Reçu édité le {{ $genereLe }} — {{ $vente->numero }} — document sans valeur fiscale.
</div>

</body>
</html>
