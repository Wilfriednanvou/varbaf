<?php

namespace Modules\Portail\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Portail\Models\ContenuPage;

/**
 * Textes éditoriaux du site public.
 *
 * **Pourquoi ce seeder existe.** La table `contenus_page` n'était
 * alimentée par rien. Chaque page tombait donc sur son texte de repli,
 * et « Le village » affichait « La présentation du village sera publiée
 * prochainement » — un site complet dans son code et vide à l'écran.
 * Le défaut ne levait aucune erreur : les vues gèrent l'absence de
 * contenu, ce qui est correct, et c'est précisément ce qui l'a rendu
 * invisible.
 *
 * **Ce que ces textes affirment, et ce qu'ils n'affirment pas.** Ils ne
 * disent que ce que le dépôt et les photographies du 28/08 établissent :
 * dix-sept locaux de vente numérotés, une vocation d'encadrement, de
 * production et d'exposition, des achats sur place. Aucune date de
 * création, aucun effectif, aucun horaire, aucune tutelle nommée — ces
 * faits appartiennent à la coordination, et une phrase inventée dans un
 * seeder se retrouverait publiée telle quelle sur le site du village.
 * C'est la question 8 de `docs/questions-coordination.md`.
 *
 * **Une base rédactionnelle, pas un contenu définitif.** Chaque texte
 * est modifiable depuis l'écran « Contenus de page » du panneau, sans
 * développeur. `updateOrCreate` sur la clé rend le seeder rejouable ;
 * en revanche il **écrase** une réécriture faite dans le panneau, donc
 * il n'a vocation à tourner qu'à l'installation.
 */
class ContenuPageSeeder extends Seeder
{
    /**
     * @return array<int, array{cle: string, titre: string, corps: string, ordre: int}>
     */
    protected function textes(): array
    {
        return [
            [
                'cle' => 'accueil.introduction',
                'ordre' => 10,
                'titre' => 'Un village, et non un marché',
                'corps' => "Le Village Artisanal Régional de Bafoussam n'est pas une galerie marchande : "
                    ."c'est un lieu d'encadrement, de production et d'exposition, où les artisans de la "
                    ."région de l'Ouest travaillent leurs matières et présentent leurs pièces.\n\n"
                    .'Chaque boutique du bâtiment abrite un ou plusieurs artisans, et chaque pièce exposée '
                    ."a été façonnée ici ou dans un atelier de la région. Le site les présente ; la rencontre, elle, "
                    .'se fait sur place.',
            ],
            [
                'cle' => 'village.presentation',
                'ordre' => 10,
                'titre' => 'Ce que fait le village',
                'corps' => 'Le village réunit en un seul lieu ce qui est habituellement dispersé : des ateliers '
                    ."où l'on produit, des boutiques où l'on expose, et un encadrement qui accompagne les "
                    ."artisans dans la qualité de leurs pièces et la conduite de leur activité.\n\n"
                    ."Sculpture, vannerie, tissage, travail du cuir, perlerie, transformation des produits du "
                    ."terroir : les corps de métier représentés couvrent l'essentiel de l'artisanat de la région.",
            ],
            [
                'cle' => 'village.batiment',
                'ordre' => 20,
                'titre' => 'Dix-sept boutiques sous un même toit',
                'corps' => "Le bâtiment compte dix-sept locaux de vente, numérotés B01 à B17, ouverts sur un "
                    ."préau à colonnes.\n\n"
                    ."Ce qui se loue n'est pas la boutique mais l'espace qu'elle abrite : plusieurs artisans "
                    .'cohabitent couramment dans un même local, chacun sur son espace, avec ses propres pièces '
                    .'et son propre établi.',
            ],
            [
                'cle' => 'village.visite',
                'ordre' => 30,
                'titre' => 'Venir, regarder, acheter',
                'corps' => "La visite est libre et gratuite. La plupart des artisans travaillent devant vous : "
                    ."on peut regarder une pièce se faire, poser des questions, commander sur mesure.\n\n"
                    ."Les achats se règlent sur place, en boutique. Le site ne prend ni commande ni paiement — "
                    ."il montre ce qui existe, et vous indique où le trouver.",
            ],
            [
                'cle' => 'contact.introduction',
                'ordre' => 10,
                'titre' => 'Nous écrire',
                'corps' => "Une question sur une création, une visite de groupe, une commande d'atelier ? "
                    .'Écrivez-nous : la coordination vous répondra et vous orientera vers le bon artisan.',
            ],
        ];
    }

    public function run(): void
    {
        foreach ($this->textes() as $texte) {
            ContenuPage::updateOrCreate(
                ['cle' => $texte['cle']],
                [
                    'titre' => $texte['titre'],
                    'corps' => $texte['corps'],
                    'actif' => true,
                    'ordre_affichage' => $texte['ordre'],
                    // Nul à dessein : personne n'a rédigé ce texte depuis
                    // le panneau. Y inscrire un utilisateur ferait dire au
                    // journal qu'un agent en est l'auteur.
                    'modifie_par' => null,
                ],
            );
        }

        $this->command?->info(count($this->textes()).' contenus de page en place pour le portail public.');
        $this->command?->comment('  — Textes de base, à relire et à réécrire depuis l\'écran « Contenus de page ».');
    }
}
