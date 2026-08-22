{{-- État récapitulatif d'une campagne de reversement — gabarit destiné
     à dompdf, hors du panneau Filament. Même exception nommée à la
     règle CSS que le reçu de vente et la décharge de dépôt : dompdf ne
     charge pas la feuille du thème. Consigné dans
     docs/dette-technique.md (DT-11). --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>État de reversement — {{ $campagne->libellePeriode() }}</title>
    <style>
        @page { margin: 14mm 12mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a1a; }
        h1 { font-size: 14px; margin: 0 0 2px; text-transform: uppercase; letter-spacing: .5px; }
        .entete { border-bottom: 2px solid #1a1a1a; padding-bottom: 8px; margin-bottom: 12px; }
        .structure { font-size: 10px; color: #555; }
        .periode { float: right; text-align: right; font-size: 12px; font-weight: bold; }
        .statut { display: inline-block; border: 1px solid #666; padding: 2px 6px;
                  font-size: 9px; text-transform: uppercase; letter-spacing: .5px; }
        .infos { width: 100%; margin-bottom: 12px; }
        .infos td { vertical-align: top; width: 33%; padding-right: 10px; }
        .intitule { font-size: 9px; text-transform: uppercase; color: #666; letter-spacing: .5px; }
        table.lignes { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.lignes th { background: #f0f0f0; border: 1px solid #999; padding: 4px; text-align: left; font-size: 9px; }
        table.lignes td { border: 1px solid #999; padding: 4px; }
        .nombre { text-align: right; }
        .negatif { color: #b00; }
        .total td { font-weight: bold; background: #f7f7f7; font-size: 11px; }
        .mention { font-size: 9px; color: #555; line-height: 1.5; border-top: 1px solid #ccc; padding-top: 8px; }
        .pied { margin-top: 10px; font-size: 9px; color: #777; text-align: center; }
    </style>
</head>
<body>

<div class="entete">
    <div class="periode">
        {{ $campagne->libellePeriode() }}<br>
        <span class="structure">Arrêtée au {{ $campagne->date_arrete?->format('d/m/Y') }}</span>
    </div>
    <h1>État récapitulatif de reversement</h1>
    <div class="structure">
        {{ $village?->nom ?? 'Village Artisanal' }}<br>
        {{ $village?->adresse }}{{ $village?->telephone ? ' — Tél. '.$village->telephone : '' }}
    </div>
</div>

<table class="infos">
    <tr>
        <td>
            <div class="intitule">Statut</div>
            <span class="statut">{{ $campagne->statut?->getLabel() }}</span>
        </td>
        <td>
            <div class="intitule">Préparée</div>
            {{ $campagne->date_generation?->format('d/m/Y à H:i') ?? '—' }}<br>
            {{ $campagne->genereePar?->name ?? '—' }}
        </td>
        <td>
            <div class="intitule">Validée</div>
            {{ $campagne->date_validation?->format('d/m/Y à H:i') ?? '—' }}<br>
            {{ $campagne->valideePar?->name ?? '—' }}
        </td>
    </tr>
</table>

<table class="lignes">
    <thead>
    <tr>
        <th style="width: 8%">Matricule</th>
        <th>Artisan</th>
        <th style="width: 14%" class="nombre">Période</th>
        <th style="width: 15%" class="nombre">Régularisation</th>
        <th style="width: 14%" class="nombre">À payer</th>
        <th style="width: 14%" class="nombre">Reporté</th>
        <th style="width: 11%">Statut</th>
    </tr>
    </thead>
    <tbody>
    @php
        $totalPeriode = 0;
        $totalRegularisation = 0;
        $totalPaye = 0;
        $totalReporte = 0;
    @endphp
    @forelse ($campagne->reversements as $reversement)
        @php
            $totalPeriode += $reversement->montant_periode;
            $totalRegularisation += $reversement->montant_regularisation;
            $totalPaye += $reversement->montant_paye;
            $totalReporte += $reversement->solde_reporte;
        @endphp
        <tr>
            <td>{{ $reversement->artisan?->matricule ?? '—' }}</td>
            <td>{{ $reversement->artisan?->nom_complet ?? 'Artisan #'.$reversement->artisan_id }}</td>
            <td class="nombre">{{ number_format((float) $reversement->montant_periode, 0, ',', ' ') }}</td>
            <td class="nombre {{ $reversement->montant_regularisation < 0 ? 'negatif' : '' }}">
                {{ number_format((float) $reversement->montant_regularisation, 0, ',', ' ') }}
            </td>
            <td class="nombre">{{ number_format((float) $reversement->montant_paye, 0, ',', ' ') }}</td>
            <td class="nombre {{ $reversement->solde_reporte < 0 ? 'negatif' : '' }}">
                {{ number_format((float) $reversement->solde_reporte, 0, ',', ' ') }}
            </td>
            <td>{{ $reversement->statut?->getLabel() }}</td>
        </tr>
    @empty
        <tr>
            <td colspan="7">Aucun artisan retenu par cette campagne.</td>
        </tr>
    @endforelse
    <tr class="total">
        <td colspan="2">Totaux (FCFA)</td>
        <td class="nombre">{{ number_format((float) $totalPeriode, 0, ',', ' ') }}</td>
        <td class="nombre">{{ number_format((float) $totalRegularisation, 0, ',', ' ') }}</td>
        <td class="nombre">{{ number_format((float) $totalPaye, 0, ',', ' ') }}</td>
        <td class="nombre">{{ number_format((float) $totalReporte, 0, ',', ' ') }}</td>
        <td>{{ $campagne->nombre_beneficiaires }} payé(s)</td>
    </tr>
    </tbody>
</table>

<div class="mention">
    La colonne « Régularisation » cumule les ventes des périodes antérieures retenues par cette campagne,
    la reprise des ventes annulées après avoir été payées, et le report du solde négatif de la campagne
    précédente. Un solde négatif ne donne lieu à aucun décaissement : il est reporté sur la campagne
    suivante jusqu'à absorption. Le détail par vente figure sur le reçu individuel de chaque artisan.
</div>

<div class="pied">
    État édité le {{ $genereLe }} — {{ $campagne->libellePeriode() }} — document interne.
</div>

</body>
</html>
