<?php

namespace Modules\Commerce\Contracts;

use Modules\Commerce\Models\Vente;

/**
 * Port vers le brouillard de caisse (RG-06).
 *
 * L'interface est déclarée **dans le Commerce**, pas dans la
 * Trésorerie, et ce n'est pas un détail d'organisation. La règle de
 * dépendance descendante interdit au module 3 de référencer le module 4.
 * En définissant ici le service dont il a besoin, le Commerce ne dépend
 * que de lui-même ; la Trésorerie viendra fournir l'implémentation et
 * la lier au conteneur. C'est le consommateur qui définit le contrat,
 * le fournisseur qui s'y conforme.
 *
 * Conséquence pratique : quand le module 4 arrivera, `ServiceVente` ne
 * changera pas d'une ligne. Seule la liaison du conteneur bougera.
 *
 * Les méthodes reçoivent la vente entière plutôt que des montants nus :
 * la Trésorerie a besoin de la section de caisse, du libellé de
 * mouvement et de l'origine pour écrire son brouillard, et elle a le
 * droit de connaître le Commerce — la dépendance va dans le bon sens.
 */
interface JournalDeCaisse
{
    /**
     * Écrit l'encaissement d'une vente au brouillard.
     *
     * L'intégralité du montant encaissé entre en caisse, y compris la
     * part revenant à l'artisan : celle-ci constitue une dette du
     * village jusqu'à son reversement (RG-13).
     *
     * @return int|null Identifiant du mouvement de caisse créé, ou null
     *                  tant que la Trésorerie n'est pas en place.
     */
    public function enregistrerEncaissementDeVente(Vente $vente): ?int;

    /**
     * Contre-passe l'encaissement d'une vente annulée.
     *
     * Jamais une suppression : le mouvement d'origine reste au
     * brouillard et un mouvement de sens inverse le référence (RG-05).
     */
    public function contrepasserEncaissementDeVente(Vente $vente, string $motif): ?int;

    /**
     * Le brouillard est-il réellement branché ?
     *
     * Permet aux écrans d'avertir l'utilisateur que les ventes
     * n'alimentent pas encore la caisse, sans que le service de vente
     * ait à connaître l'implémentation.
     */
    public function estOperationnel(): bool;
}
