<?php

namespace Modules\Pilotage\Filament\Widgets;

use Filament\Widgets\Widget;
use Modules\Pilotage\Data\FiltreRapport;
use Modules\Pilotage\Services\RapportService;

/**
 * Le chiffre d'affaires ventilé selon les trois axes qui intéressent la
 * coordination : la boutique, l'artisan, le vendeur.
 *
 * Les trois vivent dans un seul widget parce qu'ils répondent à la même
 * question — « d'où vient le chiffre ? » — et qu'ils doivent porter le
 * même filtre. Trois widgets séparés inviteraient à les filtrer
 * différemment, et à comparer des périodes distinctes sans le voir.
 */
class VentesParAxe extends Widget
{
    protected string $view = 'pilotage::widgets.ventes-par-axe';

    protected int | string | array $columnSpan = 'full';

    /** @var array<string, mixed> */
    public array $filtres = [];

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $rapport = app(RapportService::class);
        $filtre = FiltreRapport::depuisTableau($this->filtres);

        return [
            'intervalle' => $filtre->libelleIntervalle(),
            'axes' => array_map($this->avecEchelle(...), [
                ['titre' => 'Par boutique', 'lignes' => $rapport->ventesParBoutique($filtre)],
                ['titre' => 'Par artisan', 'lignes' => $rapport->ventesParArtisan($filtre)],
                ['titre' => 'Par vendeur', 'lignes' => $rapport->ventesParVendeur($filtre)],
            ]),
        ];
    }

    /**
     * Ajoute à chaque ligne sa part du plus haut montant de son axe.
     *
     * **La barre se rapporte au premier de la liste, pas au total.** Ce
     * qu'on lit sur cet écran est un classement — « d'où vient le
     * chiffre » — et la question qu'on se pose en le parcourant est
     * « l'écart entre le premier et le suivant est-il grand ? ». Une
     * barre calculée sur le total répondrait à une autre question et
     * écraserait toutes les lignes contre la gauche dès qu'il y a quinze
     * boutiques : le premier axe en compte treize, dont la dernière pèse
     * moins de 0,4 % du chiffre.
     *
     * Le calcul est ici et non dans la vue : une vue qui calcule est une
     * vue qu'on ne peut pas éprouver.
     *
     * @param  array<string, mixed>  $axe
     * @return array<string, mixed>
     */
    protected function avecEchelle(array $axe): array
    {
        $plafond = 0.0;

        foreach ($axe['lignes'] as $ligne) {
            $plafond = max($plafond, (float) $ligne['total']);
        }

        $axe['lignes'] = array_map(
            function (array $ligne) use ($plafond): array {
                // Un plafond nul n'arrive que si tout est à zéro : la
                // part vaut alors zéro pour tout le monde, ce qui est
                // exact, plutôt qu'une division par zéro.
                $part = $plafond > 0 ? (float) $ligne['total'] / $plafond * 100 : 0.0;

                return $ligne + [
                    'part' => round($part, 1),
                    'largeur' => $this->classeDeLargeur($part),
                ];
            },
            $axe['lignes'],
        );

        return $axe;
    }

    /**
     * La largeur de la barre, en classe utilitaire littérale.
     *
     * **Pourquoi un palier et non la valeur exacte.** La règle CSS de
     * CLAUDE.md interdit le style inline dans les vues, et Tailwind ne
     * peut compiler que des classes qu'il a lues dans un fichier — une
     * largeur interpolée à l'exécution ne produirait aucune règle. Les
     * vingt-et-une valeurs sont donc écrites en toutes lettres ici, dans
     * un fichier que le thème du panneau scanne.
     *
     * La perte de précision est sans effet : la barre situe, elle ne
     * mesure pas. Le montant exact est écrit à côté d'elle, et c'est lui
     * qui fait foi. Le seul palier qui compte vraiment est le premier —
     * une ligne non nulle ne doit jamais paraître vide, sans quoi le
     * lecteur conclurait à l'absence de vente.
     */
    protected function classeDeLargeur(float $part): string
    {
        return match (true) {
            $part <= 0.0 => 'w-0',
            $part < 5 => 'w-[3%]',
            $part < 10 => 'w-[5%]',
            $part < 15 => 'w-[10%]',
            $part < 20 => 'w-[15%]',
            $part < 25 => 'w-[20%]',
            $part < 30 => 'w-[25%]',
            $part < 35 => 'w-[30%]',
            $part < 40 => 'w-[35%]',
            $part < 45 => 'w-[40%]',
            $part < 50 => 'w-[45%]',
            $part < 55 => 'w-[50%]',
            $part < 60 => 'w-[55%]',
            $part < 65 => 'w-[60%]',
            $part < 70 => 'w-[65%]',
            $part < 75 => 'w-[70%]',
            $part < 80 => 'w-[75%]',
            $part < 85 => 'w-[80%]',
            $part < 90 => 'w-[85%]',
            $part < 95 => 'w-[90%]',
            $part < 100 => 'w-[95%]',
            default => 'w-full',
        };
    }
}
