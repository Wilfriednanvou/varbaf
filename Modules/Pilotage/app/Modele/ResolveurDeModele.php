<?php

namespace Modules\Pilotage\Modele;

use Modules\Pilotage\Contracts\ModeleDeLangage;

/**
 * Choisit le modèle qui rédigera, dans l'ordre configuré.
 *
 * Même idiome que `ResolveurDeMoteur`, pour la même raison : l'ordre est
 * une donnée de configuration, la disponibilité une propriété du modèle,
 * et le choix n'appartient à aucun appelant.
 *
 * **La différence tient à ce qu'il rend quand rien n'est disponible.** Le
 * résolveur de moteurs lève une exception : une recherche sans moteur ne
 * peut rien produire, et se taire ferait passer une panne pour une
 * absence de résultat. Ici, un modèle absent ne prive de rien —
 * l'assistant sait composer sa réponse sans lui depuis le premier jour.
 * `ModeleIndisponible` est donc rendu comme un modèle ordinaire, et
 * l'appelant n'a aucun cas particulier à écrire.
 *
 * **L'ordre met le local devant, et le motif n'est pas la qualité.** Un
 * modèle en ligne écrit mieux qu'un petit modèle sur la machine ; c'est
 * entendu. Mais le local ne coûte rien, ne demande aucune clé et ne
 * dépend d'aucune connexion — il fonctionne donc dans une salle de
 * soutenance sans réseau, ce qu'aucun service en ligne ne garantit. La
 * règle 4 du rétroplanning dit la même chose autrement : une
 * démonstration qui échoue faute de connexion coûte plus cher que
 * l'ambition n'en rapporte.
 *
 * Le distant n'est donc pas le choix principal dégradé en secours, c'est
 * l'inverse : un rattrapage, pour la machine où rien ne tourne en local.
 */
class ResolveurDeModele
{
    /**
     * @param  array<string, ModeleDeLangage>  $modeles  clé de configuration => modèle
     */
    public function __construct(protected array $modeles) {}

    public function resoudre(): ModeleDeLangage
    {
        foreach ($this->ordre() as $cle) {
            $modele = $this->modeles[$cle] ?? null;

            if ($modele instanceof ModeleDeLangage && $modele->estDisponible()) {
                return $modele;
            }
        }

        return new ModeleIndisponible();
    }

    /**
     * @return array<int, string>
     */
    public function ordre(): array
    {
        $ordre = config('pilotage.redaction.ordre', ['local', 'distant']);

        return is_array($ordre) && $ordre !== [] ? array_values($ordre) : ['local', 'distant'];
    }
}
