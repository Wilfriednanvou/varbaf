<?php

namespace App\Import;

use RuntimeException;

/**
 * L'import ne peut pas commencer, ou ne peut pas continuer.
 *
 * Réservée aux conditions qui rendent toute écriture impossible — pas
 * d'exercice en cours, pas de taux de commission couvrant la période,
 * pas de compte pour porter la trace. Une ligne fautive ne lève jamais
 * cette exception : elle est signalée au rapport et l'import poursuit.
 * La distinction est le cœur du comportement attendu, où « signalée »
 * ne veut pas dire « rejetée ».
 */
class ImportImpossibleException extends RuntimeException
{
    public static function sansCompte(string $identifiant): self
    {
        return new self(
            "Compte « {$identifiant} » introuvable, désactivé, ou non rattaché à un agent du village. "
            .'La reprise inscrit un vendeur sur chaque vente et un auteur sur chaque validation : '
            .'elle a besoin d\'un compte réel, jamais d\'un nom saisi.'
        );
    }

    public static function sansExercice(): self
    {
        return new self(
            'Aucun exercice en cours. Activez l\'exercice depuis l\'écran « Exercices », '
            .'ou relancez « php artisan migrate:fresh --seed ».'
        );
    }

    public static function sansTaux(string $date): self
    {
        return new self(
            "Aucun taux de commission en vigueur au {$date}, plus ancienne pièce du registre. "
            .'Une vente qu\'on ne sait pas commissionner ne s\'enregistre pas (règle 10), et l\'import '
            .'n\'invente pas d\'acte : saisissez le taux réel et sa date d\'effet depuis l\'écran '
            .'« Taux de commission » avant de relancer.'
        );
    }

    public static function sansVillage(): self
    {
        return new self('Aucun village artisanal enregistré : le seeder du Socle doit passer avant l\'import.');
    }

    public static function environnementInexploitable(int $tentatives, string $message): self
    {
        return new self(
            "Les {$tentatives} premières lignes ont toutes échoué pour la même raison ; "
            ."l'import s'arrête plutôt que de signaler mille lignes pour un seul défaut de configuration. "
            ."Dernière erreur rencontrée : {$message}"
        );
    }
}
