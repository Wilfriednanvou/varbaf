<?php

namespace Modules\Socle\Services;

use Modules\Socle\Models\Exercice;

/**
 * L'exercice consulté, distinct de l'exercice actif.
 *
 * **Deux notions que `Exercice::courant()` ne suffit plus à porter
 * seul.** L'exercice actif est celui qui accepte les écritures — un
 * seul par village, à tout instant, garanti par l'index partiel de la
 * migration. L'exercice consulté est ce qu'un utilisateur regarde en ce
 * moment, propre à sa session, et qui peut être n'importe lequel :
 * l'actif lui-même, ou un exercice clôturé qu'il feuillette.
 *
 * **Pourquoi une session et non un paramètre d'URL.** Le sélecteur vit
 * dans la barre supérieure du panneau, présent sur chaque écran ; un
 * paramètre de route obligerait chaque ressource à le relayer d'un lien
 * à l'autre, ce qui se casse au premier lien oublié. La session ne se
 * casse pas — et un onglet fermé perd la sélection, ce qui est le
 * comportement voulu : la prochaine visite reprend sur l'actif.
 *
 * **`estModifiable()` est le seul point de vérité pour la lecture
 * seule.** Un exercice consulté qui n'est pas l'actif est en lecture
 * seule *par construction*, quel que soit son propre statut — y
 * compris `EN_PREPARATION`, qui n'a encore rien à modifier. Ce n'est
 * pas une redite de `Exercice::estModifiable()` : celui-là dit si
 * l'exercice lui-même peut être réécrit (son libellé, ses dates) ;
 * celui-ci dit si de nouvelles opérations métier (vente, mouvement de
 * caisse, attribution) peuvent viser l'exercice qu'on regarde.
 */
class ContexteExercice
{
    protected const CLE_SESSION = 'exercice_consulte_id';

    /**
     * L'exercice consulté. Retombe sur l'exercice actif si rien n'a été
     * choisi, ou si le choix précédent ne désigne plus rien (exercice
     * supprimé — impossible en pratique, règle 12/A-04, mais un identifiant
     * de session périmé ne doit jamais faire planter l'écran).
     */
    public function exerciceConsulte(): ?Exercice
    {
        $id = session(self::CLE_SESSION);

        if ($id !== null) {
            $exercice = Exercice::find($id);

            if ($exercice !== null) {
                return $exercice;
            }
        }

        return Exercice::courant();
    }

    public function definir(Exercice $exercice): void
    {
        session([self::CLE_SESSION => $exercice->getKey()]);
    }

    /**
     * Revient à l'exercice actif.
     */
    public function reinitialiser(): void
    {
        session()->forget(self::CLE_SESSION);
    }

    /**
     * Les nouvelles opérations ne visent jamais un exercice qu'on ne
     * fait que consulter — vrai seulement si l'exercice consulté EST
     * l'exercice actif.
     */
    public function estModifiable(): bool
    {
        $consulte = $this->exerciceConsulte();
        $actif = Exercice::courant();

        return $consulte !== null && $actif !== null && $consulte->is($actif);
    }
}
