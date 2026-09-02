<?php

namespace Modules\Artisanat\Console;

use Illuminate\Console\Command;
use Modules\Artisanat\Enums\StatutParticipationArtisan;
use Modules\Artisanat\Models\Artisan;
use Modules\Artisanat\Models\ArtisanExercice;
use Modules\Socle\Models\Exercice;

/**
 * Peuple `artisan_exercices` depuis `Artisan.actif`, pour l'exercice
 * courant, sans toucher aux artisans deja pourvus d'une ligne.
 *
 * **A executer une fois par exercice qui n'a jamais ete peuple.** Le
 * cas normal, apres cette bascule initiale, est la reconduction
 * (assistant de cloture) — cette commande n'est pas ce chemin-la, elle
 * ne fait que rattraper un exercice qui existait avant l'introduction
 * de cette table.
 *
 * Idempotente : `whereDoesntHave` ecarte les artisans deja pourvus
 * d'une ligne sur l'exercice cible, donc un second passage ne cree
 * rien et ne peut pas produire de doublon.
 */
class BootstrapArtisanExercicesCommand extends Command
{
    protected $signature = 'varbaf:bootstrap-artisan-exercices
        {--exercice= : Libelle de l\'exercice cible (defaut : l\'exercice en cours)}';

    protected $description = "Cree la participation de chaque artisan sur un exercice qui n'en a encore aucune.";

    public function handle(): int
    {
        $libelle = $this->option('exercice');

        $exercice = $libelle
            ? Exercice::where('libelle', $libelle)->first()
            : Exercice::courant();

        if (! $exercice) {
            $this->components->error($libelle
                ? "Aucun exercice ne porte le libelle « {$libelle} »."
                : 'Aucun exercice en cours.');

            return self::FAILURE;
        }

        $crees = 0;

        Artisan::query()
            ->whereDoesntHave('participationsExercices', fn ($requete) => $requete->where('exercice_id', $exercice->id))
            ->each(function (Artisan $artisan) use ($exercice, &$crees): void {
                ArtisanExercice::create([
                    'artisan_id' => $artisan->id,
                    'exercice_id' => $exercice->id,
                    // `actif` est le seul signal disponible avant
                    // l'introduction de cette table : un artisan
                    // actif est repute avoir participe depuis le
                    // debut de l'exercice, un artisan inactif est
                    // repute desactive plutot que jamais actif —
                    // on ne sait pas lequel des deux est vrai pour
                    // un exercice deja entame, DESACTIVE est le
                    // moins fort des deux a affirmer.
                    'statut' => $artisan->actif
                        ? StatutParticipationArtisan::ACTIF
                        : StatutParticipationArtisan::DESACTIVE,
                    'date_activation' => $exercice->date_debut,
                ]);

                $crees++;
            });

        $this->components->info("{$crees} participation(s) creee(s) pour l'exercice {$exercice->libelle}.");

        return self::SUCCESS;
    }
}
