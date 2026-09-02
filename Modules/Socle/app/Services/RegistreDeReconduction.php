<?php

namespace Modules\Socle\Services;

use Modules\Socle\Contracts\ReconducteurExercice;

/**
 * Registre des reconducteurs, un par module fournisseur.
 *
 * Même motif que `VerrousDeCloture` — voir son commentaire pour le
 * détail : registre plutôt que liaison unique, parce que rien ne dit
 * que deux modules seulement auront un jour des éléments à reconduire ;
 * lié en singleton par le fournisseur du Socle, peuplé par les
 * `boot()` des autres modules, donc après que tous les `register()`
 * sont passés.
 *
 * **La clé n'est pas décorative.** L'assistant de clôture doit
 * retrouver, à l'étape de confirmation, la sélection propre à chaque
 * reconducteur — un tableau `[clé => ids sélectionnés]` posé par un
 * formulaire construit dynamiquement. Un index numérique se
 * déréglerait si l'ordre d'enregistrement changeait un jour ; une clé
 * choisie par le module qui s'enregistre ne bouge pas.
 */
class RegistreDeReconduction
{
    /** @var array<string, ReconducteurExercice> */
    protected array $reconducteurs = [];

    public function ajouter(string $cle, ReconducteurExercice $reconducteur): void
    {
        $this->reconducteurs[$cle] = $reconducteur;
    }

    /**
     * @return array<string, ReconducteurExercice>
     */
    public function tous(): array
    {
        return $this->reconducteurs;
    }

    public function compte(): int
    {
        return count($this->reconducteurs);
    }
}
