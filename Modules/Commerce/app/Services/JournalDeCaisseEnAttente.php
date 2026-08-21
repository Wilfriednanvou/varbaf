<?php

namespace Modules\Commerce\Services;

use Illuminate\Support\Facades\Log;
use Modules\Commerce\Contracts\JournalDeCaisse;
use Modules\Commerce\Models\Vente;

/**
 * Implémentation provisoire du port vers le brouillard de caisse.
 *
 * Elle n'écrit rien — le module Trésorerie n'existe pas encore — mais
 * elle n'est pas muette pour autant. Deux choses la rendent utile :
 *
 * 1. **Elle trace.** Chaque appel part au journal applicatif en
 *    avertissement. En exploitation, on voit donc noir sur blanc que
 *    les ventes ne sont pas encore reprises en caisse, au lieu de le
 *    découvrir au premier rapprochement.
 * 2. **Elle est observable.** Les appels sont conservés en mémoire et
 *    le service est lié en singleton : un test résout la même instance
 *    que le service de vente et vérifie que l'encaissement a bien été
 *    déposé — que le brouillard existe ou non.
 *
 * Le jour où la Trésorerie arrive, elle fournit sa propre
 * implémentation de `JournalDeCaisse` et remplace cette liaison.
 * `ServiceVente` ne bouge pas.
 */
class JournalDeCaisseEnAttente implements JournalDeCaisse
{
    /** @var array<int, array{vente_id: int|null, numero: string|null, montant: string|null}> */
    public array $encaissements = [];

    /** @var array<int, array{vente_id: int|null, numero: string|null, motif: string}> */
    public array $contrepassations = [];

    public function enregistrerEncaissementDeVente(Vente $vente): ?int
    {
        $this->encaissements[] = [
            'vente_id' => $vente->getKey(),
            'numero' => $vente->numero,
            'montant' => $vente->montant_total,
        ];

        Log::warning('Encaissement non repris en caisse : le module Trésorerie n\'est pas encore branché.', [
            'vente' => $vente->numero,
            'montant' => $vente->montant_total,
        ]);

        return null;
    }

    public function contrepasserEncaissementDeVente(Vente $vente, string $motif): ?int
    {
        $this->contrepassations[] = [
            'vente_id' => $vente->getKey(),
            'numero' => $vente->numero,
            'motif' => $motif,
        ];

        Log::warning('Contre-passation non reprise en caisse : le module Trésorerie n\'est pas encore branché.', [
            'vente' => $vente->numero,
            'motif' => $motif,
        ]);

        return null;
    }

    public function estOperationnel(): bool
    {
        return false;
    }

    /**
     * Remet le compteur à zéro — confort de test.
     */
    public function oublier(): void
    {
        $this->encaissements = [];
        $this->contrepassations = [];
    }
}
