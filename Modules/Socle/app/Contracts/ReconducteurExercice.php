<?php

namespace Modules\Socle\Contracts;

use Illuminate\Support\Collection;
use Modules\Socle\Models\Exercice;

/**
 * Ce qu'un module reconduit d'un exercice à l'autre.
 *
 * **Même patron que `VerrouDeCloture`, pour la même raison.** L'assistant
 * de clôture doit afficher et reconduire des artisans et des produits,
 * deux notions qui appartiennent respectivement à Artisanat et Commerce
 * — que le Socle n'a pas le droit de connaître. Le Socle déclare donc ce
 * contrat, chaque module fournisseur l'implémente et vient se déclarer
 * dans `RegistreDeReconduction` depuis son propre `boot()`. La
 * dépendance continue de descendre.
 *
 * **Générique par construction.** `elementsAReconduire()` ne renvoie ni
 * `Artisan` ni `Produit`, mais des lignes anonymes {id, libelle,
 * statut_actuel} : c'est ce qui permet à l'assistant de clôture de les
 * afficher sans importer un seul modèle des modules fournisseurs.
 */
interface ReconducteurExercice
{
    /**
     * Le libellé de la rubrique dans l'assistant de clôture — « Artisans »,
     * « Produits ».
     */
    public function libelle(): string;

    /**
     * Les éléments actifs sur cet exercice, candidats à la reconduction.
     *
     * @return Collection<int, array{id: int, libelle: string, statut_actuel: string}>
     */
    public function elementsAReconduire(Exercice $exercice): Collection;

    /**
     * Reconduit les éléments choisis vers le nouvel exercice.
     *
     * N'écrit que sur `$nouveau` : l'ancien exercice n'est jamais
     * modifié par une reconduction, seule sa clôture le referme.
     *
     * @param  array<int, int>  $idsSelectionnes  identifiants renvoyés par elementsAReconduire()
     */
    public function reconduire(Exercice $ancien, Exercice $nouveau, array $idsSelectionnes): void;
}
