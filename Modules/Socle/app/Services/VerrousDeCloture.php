<?php

namespace Modules\Socle\Services;

use Modules\Socle\Contracts\VerrouDeCloture;
use Modules\Socle\Models\Exercice;

/**
 * Registre des verrous de clôture.
 *
 * **Pourquoi un registre et non une seule liaison.** `JournalDeCaisse`
 * n'a qu'une implémentation : il n'existe qu'un brouillard de caisse.
 * Ici, plusieurs modules peuvent avoir de bonnes raisons de s'opposer à
 * une clôture, et rien ne dit qu'ils seront toujours deux. Une liaison
 * unique au conteneur obligerait le dernier module chargé à écraser le
 * précédent, en silence.
 *
 * Le registre est lié en singleton par le fournisseur du Socle, et
 * chaque module vient y ajouter son verrou depuis son propre `boot()` —
 * après que tous les `register()` sont passés, donc sur la même
 * instance.
 *
 * **Vide, il n'empêche rien.** C'est ce qui permet au Socle de tourner
 * seul : un jeu de tests qui n'amorce que le module 1 clôture ses
 * exercices sans obstacle, et c'est le comportement juste — il n'y a
 * alors ni caisse ni campagne à protéger.
 */
class VerrousDeCloture
{
    /** @var array<int, VerrouDeCloture> */
    protected array $verrous = [];

    public function ajouter(VerrouDeCloture $verrou): void
    {
        $this->verrous[] = $verrou;
    }

    /**
     * Tout ce qui s'oppose à la clôture, tous modules confondus.
     *
     * Les obstacles ne s'arrêtent pas au premier trouvé : la
     * coordination doit voir d'un coup tout ce qu'il lui reste à faire,
     * plutôt que de buter dessus un par un.
     *
     * @return array<int, string>
     */
    public function obstacles(Exercice $exercice): array
    {
        $obstacles = [];

        foreach ($this->verrous as $verrou) {
            foreach ($verrou->obstacles($exercice) as $obstacle) {
                $obstacles[] = $obstacle;
            }
        }

        return $obstacles;
    }

    /**
     * Nombre de verrous déclarés.
     *
     * Sert au diagnostic : un registre vide en exploitation signifierait
     * que le fournisseur d'un module n'a pas été chargé, et que la
     * clôture ne protège plus rien.
     */
    public function compte(): int
    {
        return count($this->verrous);
    }
}
