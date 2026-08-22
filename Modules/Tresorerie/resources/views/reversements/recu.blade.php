{{-- Reçu de reversement — gabarit destiné à dompdf, hors du panneau
     Filament. Même exception nommée à la règle CSS que le reçu de vente
     et la décharge de dépôt, consignée dans docs/dette-technique.md
     (DT-11).

     RG-18 : « chaque décaissement donne lieu à un reçu signé par
     l'artisan ». D'où le bloc de signature en pied de page. --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Reçu de reversement — {{ $campagne->libellePeriode() }}</title>
    <style>
        @page { margin: 16mm 14mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; }
        h1 { font-size: 14px; margin: 0 0 2px; text-transform: uppercase; letter-spacing: .5px; }
        .entete { border-bottom: 2px solid #1a1a1a; padding-bottom: 8px; margin-bottom: 12px; }
        .structure { font-size: 10px; color: #555; }
        .periode { float: right; text-align: right; font-size: 12px; font-weight: bold; }
        .reporte { border: 2px solid #b00; color: #b00; padding: 6px; text-align: center;
                   font-weight: bold; text-transform: uppercase; margin-bottom: 12px; letter-spacing: 1px; }
        .infos { width: 100%; margin-bottom: 12px; }
        .infos td { vertical-align: top; width: 50%; padding-right: 12px; }
        .intitule { font-size: 9px; text-transform: uppercase; color: #666; letter-spacing: .5px; }
        table.lignes { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.lignes th { background: #f0f0f0; border: 1px solid #999; padding: 5px; text-align: left; font-size: 10px; }
        table.lignes td { border: 1px solid #999; padding: 5px; }
        .nombre { text-align: right; }
        .negatif { color: #b00; }
        .recapitulatif { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .recapitulatif td { padding: 4px 5px; border-bottom: 1px solid #ddd; }
        .recapitulatif tr.net td { font-weight: bold; background: #f7f7f7; font-size: 12px; border-bottom: none; }
        .mention { font-size: 9px; color: #555; line-height: 1.5; border-top: 1px solid #ccc; padding-top: 8px; }
        .signature { margin-top: 24px; width: 100%; }
        .signature td { width: 50%; vertical-align: top; font-size: 10px; }
        .trait { margin-top: 34px; border-top: 1px solid #666; width: 80%; }
        .pied { margin-top: 14px; font-size: 9px; color: #777; text-align: center; }
    </style>
</head>
<body>

<div class="entete">
    <div class="periode">
        {{ $campagne->libellePeriode() }}<br>
        <span class="structure">Arrêtée au {{ $campagne->date_arrete?->format('d/m/Y') }}</span>
    </div>
    <h1>Reçu de reversement</h1>
    <div class="structure">
        {{ $village?->nom ?? 'Village Artisanal' }}<br>
        {{ $village?->adresse }}{{ $village?->telephone ? ' — Tél. '.$village->telephone : '' }}
    </div>
</div>

@if ($reversement->estReporte())
    <div class="reporte">
        Aucun décaissement — solde reporté sur la campagne suivante
    </div>
@endif

<table class="infos">
    <tr>
        <td>
            <div class="intitule">Artisan</div>
            <strong>{{ $reversement->artisan?->nom_complet ?? 'Artisan #'.$reversement->artisan_id }}</strong><br>
            Matricule : {{ $reversement->artisan?->matricule ?? '—' }}
        </td>
        <td>
            <div class="intitule">Décaissement</div>
            Statut : {{ $reversement->statut?->getLabel() }}<br>
            Date : {{ $reversement->date_paiement?->format('d/m/Y à H:i') ?? '—' }}<br>
            Pièce de caisse : {{ $reversement->mouvementCaisse?->numero_ordre
                ? 'mvt n° '.$reversement->mouvementCaisse->numero_ordre
                : '—' }}
        </td>
    </tr>
</table>

<div class="intitule">Détail des ventes retenues</div>
<table class="lignes">
    <thead>
    <tr>
        <th style="width: 16%">Date d'origine</th>
        <th style="width: 22%">Ticket</th>
        <th>Nature</th>
        <th style="width: 20%" class="nombre">Part artisan</th>
    </tr>
    </thead>
    <tbody>
    @forelse ($reversement->lignes as $ligne)
        <tr>
            <td>{{ $ligne->date_origine?->format('d/m/Y') }}</td>
            <td>{{ $ligne->vente?->numero ?? '—' }}</td>
            <td>{{ $ligne->type?->getLabel() }}</td>
            <td class="nombre {{ $ligne->montant < 0 ? 'negatif' : '' }}">
                {{ number_format((float) $ligne->montant, 0, ',', ' ') }}
            </td>
        </tr>
    @empty
        <tr>
            <td colspan="4">Aucune vente retenue — cette ligne ne porte qu'un report de solde.</td>
        </tr>
    @endforelse
    </tbody>
</table>

<table class="recapitulatif">
    <tr>
        <td>Part des ventes de la période</td>
        <td class="nombre">{{ number_format((float) $reversement->montant_periode, 0, ',', ' ') }} FCFA</td>
    </tr>
    <tr>
        <td>Régularisations, reprises et report antérieur</td>
        <td class="nombre {{ $reversement->montant_regularisation < 0 ? 'negatif' : '' }}">
            {{ number_format((float) $reversement->montant_regularisation, 0, ',', ' ') }} FCFA
        </td>
    </tr>
    <tr class="net">
        <td>Montant versé</td>
        <td class="nombre">{{ number_format((float) $reversement->montant_paye, 0, ',', ' ') }} FCFA</td>
    </tr>
    @if ($reversement->solde_reporte < 0)
        <tr>
            <td>Solde reporté sur la campagne suivante</td>
            <td class="nombre negatif">{{ number_format((float) $reversement->solde_reporte, 0, ',', ' ') }} FCFA</td>
        </tr>
    @endif
</table>

<div class="mention">
    Le montant versé ne peut être négatif : lorsque les reprises dépassent les parts dues, aucun
    décaissement n'est effectué et le solde est reporté sur la campagne suivante jusqu'à absorption.
    Les parts figurant sur ce reçu sont celles figées sur chaque vente au moment de son enregistrement.
</div>

<table class="signature">
    <tr>
        <td>
            Pour le Village Artisanal<br>
            <div class="trait"></div>
        </td>
        <td>
            Reçu la somme ci-dessus — l'artisan<br>
            <div class="trait"></div>
        </td>
    </tr>
</table>

<div class="pied">
    Reçu édité le {{ $genereLe }} — {{ $campagne->libellePeriode() }} — document sans valeur fiscale.
</div>

</body>
</html>
