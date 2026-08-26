<?php

namespace Modules\Pilotage\Recommandation;

use Modules\Pilotage\Contracts\MoteurDeRecherche;
use Modules\Pilotage\Contracts\MoteurSemantique;
use RuntimeException;

/**
 * Choisit le moteur qui répondra, dans l'ordre configuré.
 *
 * **C'est ici que vit le repli automatique.** `config('pilotage.moteur.ordre')`
 * énumère les moteurs par préférence décroissante ; le premier qui se
 * déclare disponible l'emporte. Le jour où une branche dense arrivera,
 * l'ordre devient `['dense', 'lexical']` et rien d'autre ne change :
 * ni les services, ni les écrans, ni les tests. Si le service
 * d'embeddings est injoignable ce jour-là, `estDisponible()` répond non
 * et la branche lexicale reprend la main sans que personne ne s'en
 * aperçoive — sauf l'affichage, qui nomme le moteur qui a répondu.
 *
 * Un ordre qui ne mènerait à aucun moteur disponible lève une exception
 * plutôt que de rendre un moteur muet : une suggestion absente parce
 * qu'il n'y a rien à suggérer et une suggestion absente parce que le
 * moteur est en panne ne se ressemblent que de l'extérieur.
 */
class ResolveurDeMoteur
{
    /**
     * @param  array<string, MoteurDeRecherche>  $moteurs  clé de configuration => moteur
     */
    public function __construct(protected array $moteurs) {}

    public function resoudre(): MoteurSemantique
    {
        foreach ($this->ordre() as $cle) {
            $moteur = $this->moteurs[$cle] ?? null;

            if ($moteur instanceof MoteurSemantique && $moteur->estDisponible()) {
                return $moteur;
            }
        }

        throw new RuntimeException(
            'Aucun moteur sémantique disponible. Ordre configuré : '
            .implode(', ', $this->ordre())
            .'. Si l\'index est vide, lancez « php artisan varbaf:indexer ».',
        );
    }

    /**
     * Le moteur qui répondrait, sans lever d'exception.
     *
     * Pour les surfaces qui préfèrent n'afficher aucune suggestion
     * plutôt qu'une erreur — une fiche produit du portail public doit
     * rester lisible même si l'index n'a jamais été construit.
     */
    public function resoudreOuNul(): ?MoteurSemantique
    {
        try {
            return $this->resoudre();
        } catch (RuntimeException) {
            return null;
        }
    }


    /**
     * Un moteur désigné par sa clé, disponible ou non.
     *
     * Sert la commande d'évaluation, qui doit pouvoir imposer le moteur
     * témoin par mots-clés sans toucher à `pilotage.moteur.ordre` : une
     * mesure comparative qui exigerait de modifier la configuration de
     * production ne serait reproductible par personne.
     *
     * Le témoin n'est volontairement pas dans l'ordre de résolution :
     * ce n'est pas un moteur de repli, c'est un instrument de mesure.
     */
    public function moteurNomme(string $cle): ?MoteurDeRecherche
    {
        $moteur = $this->moteurs[$cle] ?? null;

        return $moteur instanceof MoteurDeRecherche ? $moteur : null;
    }

    /**
     * @return array<int, string>
     */
    public function clesDisponibles(): array
    {
        return array_keys($this->moteurs);
    }

    /**
     * @return array<int, string>
     */
    public function ordre(): array
    {
        $ordre = config('pilotage.moteur.ordre', ['lexical']);

        return is_array($ordre) && $ordre !== [] ? array_values($ordre) : ['lexical'];
    }
}
