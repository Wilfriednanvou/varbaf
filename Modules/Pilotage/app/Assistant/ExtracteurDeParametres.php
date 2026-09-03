<?php

namespace Modules\Pilotage\Assistant;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Pilotage\Data\FiltreRapport;
use Modules\Pilotage\Indexation\Normalisateur;
use Modules\Socle\Services\ContexteExercice;

/**
 * Lit dans la question ce qui doit borner le calcul.
 *
 * **Aucune devinette.** Un paramètre est extrait s'il est écrit, jamais
 * déduit d'un contexte. Une question qui ne nomme pas d'artisan ne se
 * voit pas attribuer « probablement le plus gros vendeur » : elle est
 * renvoyée au demandeur pour précision. C'est plus lent à l'usage et
 * infiniment plus défendable — un indicateur financier attribué au
 * mauvais artisan par inférence serait une faute, pas une imprécision.
 *
 * **Les entités sont reconnues contre la base, pas contre une liste.**
 * Les noms d'artisans, les numéros de boutique et les corps de métier
 * viennent des tables : le catalogue d'intentions n'a donc jamais à
 * être tenu à jour quand le village enregistre un nouvel artisan.
 */
class ExtracteurDeParametres
{
    protected const MOIS = [
        'janvier' => 1, 'fevrier' => 2, 'mars' => 3, 'avril' => 4,
        'mai' => 5, 'juin' => 6, 'juillet' => 7, 'aout' => 8,
        'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'decembre' => 12,
    ];

    public function __construct(protected ?Normalisateur $normalisateur = null) {}

    public function extraire(string $question): ParametresQuestion
    {
        $normalisateur = $this->normalisateur ?? Normalisateur::depuisLaConfiguration();
        $termes = $normalisateur->decouper($question);
        $brut = mb_strtolower($question, 'UTF-8');

        [$filtre, $explicite, $libelle] = $this->periode($question, $termes, $brut);
        [$artisanId, $artisanNom, $artisanMatricule] = $this->artisan($termes);
        [$boutiqueId, $boutiqueNumero] = $this->boutique($question, $termes);
        [$metierId, $metierLibelle] = $this->corpsMetier($termes);

        return new ParametresQuestion(
            filtre: $filtre,
            periodeExplicite: $explicite,
            libellePeriode: $libelle,
            artisanId: $artisanId,
            artisanNom: $artisanNom,
            artisanMatricule: $artisanMatricule,
            boutiqueId: $boutiqueId,
            boutiqueNumero: $boutiqueNumero,
            corpsMetierId: $metierId,
            corpsMetierLibelle: $metierLibelle,
        );
    }

    // =================================================================
    //  PÉRIODE
    // =================================================================

    /**
     * @param  array<int, string>  $termes
     * @return array{0: FiltreRapport, 1: bool, 2: string}
     */
    protected function periode(string $question, array $termes, string $brut): array
    {
        // L'exercice consulté au sélecteur global, pas nécessairement
        // l'actif : une question posée sans année pendant qu'on regarde
        // un exercice clôturé doit rester sur ce qu'on a sous les yeux,
        // pas retomber en silence sur l'exercice en cours.
        $exerciceId = app(ContexteExercice::class)->exerciceConsulte()?->getKey();
        $maintenant = Carbon::now();

        // Une année à quatre chiffres : « en 2024 ». Le tokeniser écarte
        // les nombres nus, c'est donc la chaîne brute qu'on interroge.
        if (preg_match('/\b(20\d{2})\b/', $question, $trouve) === 1) {
            $annee = (int) $trouve[1];

            // Un mois nommé avec l'année resserre l'intervalle.
            foreach (self::MOIS as $nom => $rang) {
                if (in_array($nom, $termes, true)) {
                    $debut = Carbon::create($annee, $rang, 1)->startOfMonth();

                    return [
                        new FiltreRapport(du: $debut, au: $debut->copy()->endOfMonth()),
                        true,
                        "en {$nom} {$annee}",
                    ];
                }
            }

            return [
                new FiltreRapport(
                    du: Carbon::create($annee, 1, 1)->startOfYear(),
                    au: Carbon::create($annee, 12, 31)->endOfYear(),
                ),
                true,
                "en {$annee}",
            ];
        }

        // Un mois nommé sans année : celui de l'année en cours.
        foreach (self::MOIS as $nom => $rang) {
            if (in_array($nom, $termes, true)) {
                $debut = Carbon::create($maintenant->year, $rang, 1)->startOfMonth();

                return [
                    new FiltreRapport(du: $debut, au: $debut->copy()->endOfMonth()),
                    true,
                    "en {$nom} {$maintenant->year}",
                ];
            }
        }

        $relatives = [
            'ce mois' => fn (): array => [$maintenant->copy()->startOfMonth(), $maintenant->copy()->endOfMonth(), 'ce mois-ci'],
            'mois dernier' => fn (): array => [
                $maintenant->copy()->subMonthNoOverflow()->startOfMonth(),
                $maintenant->copy()->subMonthNoOverflow()->endOfMonth(),
                'le mois dernier',
            ],
            'cette annee' => fn (): array => [$maintenant->copy()->startOfYear(), $maintenant->copy()->endOfYear(), 'cette année'],
            'annee derniere' => fn (): array => [
                $maintenant->copy()->subYear()->startOfYear(),
                $maintenant->copy()->subYear()->endOfYear(),
                'l\'année dernière',
            ],
            'cette semaine' => fn (): array => [$maintenant->copy()->startOfWeek(), $maintenant->copy()->endOfWeek(), 'cette semaine'],
            'aujourd hui' => fn (): array => [$maintenant->copy()->startOfDay(), $maintenant->copy()->endOfDay(), "aujourd'hui"],
            'hier' => fn (): array => [
                $maintenant->copy()->subDay()->startOfDay(),
                $maintenant->copy()->subDay()->endOfDay(),
                'hier',
            ],
        ];

        foreach ($relatives as $expression => $calcul) {
            if ($this->contientExpression($brut, $expression)) {
                [$du, $au, $libelle] = $calcul();

                return [new FiltreRapport(du: $du, au: $au), true, $libelle];
            }
        }

        // Rien de dit : l'exercice en cours, et l'assistant le dira.
        return [new FiltreRapport(exerciceId: $exerciceId), false, 'sur l\'exercice en cours'];
    }

    /**
     * Une expression relative se cherche dans la chaîne brute : « ce
     * mois » disparaîtrait de la version tokenisée, « ce » étant un mot
     * vide.
     */
    protected function contientExpression(string $brut, string $expression): bool
    {
        $brut = str_replace(['’', "'", '-'], ' ', $brut);
        $brut = strtr($brut, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'û' => 'u', 'ô' => 'o']);
        $brut = preg_replace('/\s+/', ' ', $brut) ?? $brut;

        return str_contains($brut, $expression);
    }

    // =================================================================
    //  ENTITÉS
    // =================================================================

    /**
     * @param  array<int, string>  $termes
     * @return array{0: ?int, 1: ?string, 2: ?string}
     */
    protected function artisan(array $termes): array
    {
        if ($termes === []) {
            return [null, null, null];
        }

        $normalisateur = $this->normalisateur ?? Normalisateur::depuisLaConfiguration();
        $recherches = array_flip($termes);

        $candidats = DB::table('artisans')
            ->select(['id', 'nom', 'prenom', 'matricule'])
            ->get();

        foreach ($candidats as $artisan) {
            // Le nom de famille suffit à reconnaître : c'est ainsi que
            // le village nomme ses artisans. Exiger le prénom ferait
            // échouer la reconnaissance sur la moitié du fichier, où il
            // n'est pas renseigné.
            foreach ($normalisateur->decouper((string) $artisan->nom) as $terme) {
                if (isset($recherches[$terme])) {
                    return [
                        (int) $artisan->id,
                        trim($artisan->nom.' '.($artisan->prenom ?? '')),
                        (string) $artisan->matricule,
                    ];
                }
            }
        }

        return [null, null, null];
    }

    /**
     * @param  array<int, string>  $termes
     * @return array{0: ?int, 1: ?string}
     */
    protected function boutique(string $question, array $termes): array
    {
        $numeros = DB::table('boutiques')->select(['id', 'numero'])->get();
        $normalisateur = $this->normalisateur ?? Normalisateur::depuisLaConfiguration();
        $recherches = array_flip($termes);

        // « B12 », « B-12 » et « boutique 12 » désignent le même local :
        // on compare sur les chiffres, après avoir retiré la lettre.
        //
        // Le drapeau `u` n'est pas cosmétique. Le signe « ° » occupe deux
        // octets en UTF-8 ; sans ce drapeau, PCRE lit le motif octet par
        // octet et `°?` ne rend optionnel que le second. Le premier
        // devenait obligatoire, et « boutique 4 » — qui ne contient
        // évidemment pas cet octet — ne correspondait plus du tout. Le
        // groupe non capturant met tout l'ordinal hors du passage
        // obligé, drapeau ou non.
        $chiffres = null;

        if (preg_match('/\bb\s*-?\s*0*(\d{1,3})\b/i', $question, $trouve) === 1) {
            $chiffres = (int) $trouve[1];
        } elseif (preg_match('/\bboutique\s+(?:n(?:um[ée]ro|°|o)?\s*)?0*(\d{1,3})\b/iu', $question, $trouve) === 1) {
            $chiffres = (int) $trouve[1];
        }

        foreach ($numeros as $boutique) {
            $numero = (string) $boutique->numero;

            if ($chiffres !== null && preg_match('/(\d+)/', $numero, $trouveNumero) === 1
                && (int) $trouveNumero[1] === $chiffres) {
                return [(int) $boutique->id, $numero];
            }

            foreach ($normalisateur->decouper($numero) as $terme) {
                if (isset($recherches[$terme])) {
                    return [(int) $boutique->id, $numero];
                }
            }
        }

        return [null, null];
    }

    /**
     * @param  array<int, string>  $termes
     * @return array{0: ?int, 1: ?string}
     */
    protected function corpsMetier(array $termes): array
    {
        if ($termes === []) {
            return [null, null];
        }

        $normalisateur = $this->normalisateur ?? Normalisateur::depuisLaConfiguration();
        $recherches = array_flip($termes);

        foreach (DB::table('corps_metiers')->select(['id', 'libelle'])->get() as $metier) {
            foreach ($normalisateur->decouper((string) $metier->libelle) as $terme) {
                if (isset($recherches[$terme])) {
                    return [(int) $metier->id, (string) $metier->libelle];
                }
            }
        }

        return [null, null];
    }
}
