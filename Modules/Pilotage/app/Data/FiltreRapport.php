<?php

namespace Modules\Pilotage\Data;

use Illuminate\Support\Carbon;
use Modules\Socle\Models\Exercice;

/**
 * Portée d'un indicateur : un exercice, un intervalle de dates, ou les
 * deux.
 *
 * Un objet plutôt que trois arguments répétés sur chaque méthode du
 * service. Les trois champs sont facultatifs : un filtre vide signifie
 * « tout », ce qui est le comportement attendu d'un indicateur de
 * stock — le solde de caisse ou le taux d'occupation ne se datent pas.
 */
readonly class FiltreRapport
{
    public function __construct(
        public ?int $exerciceId = null,
        public ?Carbon $du = null,
        public ?Carbon $au = null,
    ) {}

    /**
     * Le filtre par défaut du tableau de bord : l'exercice en cours,
     * sans borne de date. `Exercice::courant()` est le point d'entrée
     * que le Socle expose — le Pilotage ne requête pas sa table.
     */
    public static function parDefaut(): self
    {
        return new self(exerciceId: Exercice::courant()?->getKey());
    }

    /**
     * Construit le filtre depuis l'état du formulaire de la page.
     *
     * @param  array<string, mixed>  $valeurs
     */
    public static function depuisTableau(array $valeurs): self
    {
        return new self(
            exerciceId: filled($valeurs['exercice_id'] ?? null) ? (int) $valeurs['exercice_id'] : null,
            du: filled($valeurs['du'] ?? null) ? Carbon::parse($valeurs['du']) : null,
            au: filled($valeurs['au'] ?? null) ? Carbon::parse($valeurs['au']) : null,
        );
    }

    public function estVide(): bool
    {
        return $this->exerciceId === null && $this->du === null && $this->au === null;
    }

    /**
     * Libellé lisible de l'intervalle, pour les en-têtes de widget.
     */
    public function libelleIntervalle(): string
    {
        return match (true) {
            $this->du && $this->au => 'du '.$this->du->format('d/m/Y').' au '.$this->au->format('d/m/Y'),
            (bool) $this->du => 'depuis le '.$this->du->format('d/m/Y'),
            (bool) $this->au => "jusqu'au ".$this->au->format('d/m/Y'),
            default => 'sur tout l\'exercice',
        };
    }

    /**
     * Clé stable, utilisée pour remonter les widgets quand les filtres
     * changent.
     */
    public function empreinte(): string
    {
        return md5(implode('|', [
            $this->exerciceId ?? '',
            $this->du?->toDateString() ?? '',
            $this->au?->toDateString() ?? '',
        ]));
    }
}
