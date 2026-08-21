<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Garde-fou des conventions d'habilitation.
 *
 * Contexte : faute de Policy déclarée, la couche d'autorisation de
 * Filament laisse passer tout compte du panneau (voir DT-08 dans
 * docs/dette-technique.md). Ce n'est acceptable que parce que chaque
 * action porte un `->visible()` qui la ferme réellement — Filament
 * refuse de monter une action masquée.
 *
 * Toute la sécurité tient donc à une discipline : aucune action, nulle
 * part, sans `->visible()`. Une discipline qui n'est pas vérifiée
 * mécaniquement finit toujours par céder sur la ressource écrite un
 * soir de fatigue. Ce test parcourt les fichiers de ressources de tous
 * les modules et échoue sur le premier manquement, en le nommant.
 *
 * Il lit les sources plutôt que d'instancier les composants : il couvre
 * ainsi les ressources qui ne seraient pas encore branchées sur un
 * panneau, et il n'a besoin ni de base de données ni d'utilisateur
 * connecté. Il hérite volontairement du TestCase de PHPUnit et non de
 * celui de Laravel : rien ici ne requiert l'application.
 */
class ConventionsFilamentTest extends TestCase
{
    /**
     * Actions dont la visibilité n'a pas à être conditionnée.
     *
     * Vide, et destiné à le rester : chaque entrée serait une brèche
     * dans la garantie. Si une exception devient inévitable, elle se
     * justifie ici, en toutes lettres, et se relit en soutenance.
     *
     * @var array<string, string>
     */
    protected const EXCEPTIONS = [];

    protected function racineDuProjet(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return array<int, string>
     */
    protected function fichiersDeRessources(): array
    {
        $fichiers = array_merge(
            glob($this->racineDuProjet().'/Modules/*/app/Filament/Resources/*.php') ?: [],
            glob($this->racineDuProjet().'/Modules/*/app/Filament/Resources/*/Pages/*.php') ?: [],
            glob($this->racineDuProjet().'/Modules/*/app/Filament/Pages/*.php') ?: [],
        );

        sort($fichiers);

        return $fichiers;
    }

    /**
     * Découpe un fichier en blocs d'action.
     *
     * @return array<int, array{type: string, nom: string, ligne: int, corps: string}>
     */
    protected function actionsDeclarees(string $source): array
    {
        $motif = '/Actions\\\\(\w+)::make\(\s*(?:\'([\w-]+)\')?/';

        if (! preg_match_all($motif, $source, $occurrences, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $actions = [];
        $nombre = count($occurrences[0]);

        for ($rang = 0; $rang < $nombre; $rang++) {
            $type = $occurrences[1][$rang][0];

            // Un groupe n'est qu'un conteneur : ce sont les actions
            // qu'il contient qui portent les permissions.
            if ($type === 'ActionGroup') {
                continue;
            }

            $debut = $occurrences[0][$rang][1];
            $fin = ($rang + 1 < $nombre) ? $occurrences[0][$rang + 1][1] : strlen($source);

            $actions[] = [
                'type' => $type,
                'nom' => $occurrences[2][$rang][0] ?? '',
                'ligne' => substr_count($source, "\n", 0, $debut) + 1,
                'corps' => substr($source, $debut, $fin - $debut),
            ];
        }

        return $actions;
    }

    public function test_le_depot_contient_bien_des_ressources_a_verifier(): void
    {
        // Sans ce garde-fou, un glob cassé rendrait le test ci-dessous
        // vert en ne parcourant rien du tout — le pire des faux
        // négatifs pour un test de sécurité.
        $this->assertGreaterThan(
            5,
            count($this->fichiersDeRessources()),
            'Aucune ressource Filament trouvée : le chemin de parcours est cassé.'
        );
    }

    public function test_chaque_action_porte_une_permission_nominale(): void
    {
        $manquements = [];

        foreach ($this->fichiersDeRessources() as $fichier) {
            $source = file_get_contents($fichier);
            $chemin = str_replace($this->racineDuProjet().'/', '', $fichier);

            foreach ($this->actionsDeclarees($source) as $action) {
                $designation = $action['type'].($action['nom'] ? "('{$action['nom']}')" : '');
                $cle = "{$chemin}::{$designation}";

                if (array_key_exists($cle, self::EXCEPTIONS)) {
                    continue;
                }

                // `->hidden()` est l'expression inverse de la même
                // décision : ce qui compte est qu'une décision soit
                // prise, pas la forme qu'elle prend.
                if (str_contains($action['corps'], '->visible(') || str_contains($action['corps'], '->hidden(')) {
                    continue;
                }

                $manquements[] = "{$chemin}:{$action['ligne']} — {$designation}";
            }
        }

        $this->assertSame(
            [],
            $manquements,
            "Des actions Filament ne portent aucun ->visible() :\n  ".implode("\n  ", $manquements)
            ."\n\nToute la sécurité du panneau repose sur ces closures, faute de Policy (DT-08). "
            .'Une action sans permission nominale est ouverte à tout compte du panneau.'
        );
    }

    public function test_chaque_visible_interroge_bien_une_permission(): void
    {
        $suspects = [];

        foreach ($this->fichiersDeRessources() as $fichier) {
            $source = file_get_contents($fichier);
            $chemin = str_replace($this->racineDuProjet().'/', '', $fichier);

            foreach ($this->actionsDeclarees($source) as $action) {
                if (! str_contains($action['corps'], '->visible(')) {
                    continue;
                }

                // Un ->visible() qui ne consulte aucune permission
                // masque peut-être selon l'état du dossier, mais il ne
                // protège rien : il resterait ouvert à tout compte.
                if (str_contains($action['corps'], "->can('")) {
                    continue;
                }

                $designation = $action['type'].($action['nom'] ? "('{$action['nom']}')" : '');
                $suspects[] = "{$chemin}:{$action['ligne']} — {$designation}";
            }
        }

        $this->assertSame(
            [],
            $suspects,
            "Des ->visible() ne consultent aucune permission :\n  ".implode("\n  ", $suspects)
            ."\n\nMasquer selon l'état d'un enregistrement est utile, mais ne remplace pas "
            .'un contrôle de droit : les deux conditions doivent coexister.'
        );
    }
}
