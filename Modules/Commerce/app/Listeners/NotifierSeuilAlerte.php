<?php

namespace Modules\Commerce\Listeners;

use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Modules\Commerce\Events\SeuilAlerteFranchi;
use Modules\Socle\Models\Utilisateur;

/**
 * Porte l'alerte de rupture jusqu'à ses destinataires (règle 15).
 *
 * **Ce qui manquait était la remise, pas la détection.**
 * `ServiceMouvementStock` émettait déjà `SeuilAlerteFranchi` au bon
 * moment — au franchissement, jamais à l'état — mais personne n'écoutait.
 * La règle 15 était donc écrite dans la spécification et absente du
 * système : un écran affichait la liste des produits sous le seuil, à
 * condition qu'on pense à aller la consulter.
 *
 * **Sur la portée effective.** La règle nomme trois destinataires :
 * l'artisan, la section Production, la section Commercialisation. Les
 * deux sections sont servies ici. L'artisan ne l'est pas, et ce n'est
 * pas un oubli : un artisan n'a pas de compte dans le système — la
 * table `artisans` ne porte aucun lien vers `users`, et le panneau
 * artisan de la règle 12 n'est pas construit. Lui « notifier » quelque
 * chose supposerait un canal — courriel, SMS — que le village n'a pas,
 * et sur des adresses que le registre ne renseigne pas. Le nom de
 * l'artisan est donc porté dans le corps du message, pour que la
 * section sache qui appeler. Le reste est consigné en dette (A-09).
 *
 * **L'écoute est synchrone, délibérément.** Une alerte de stock n'a
 * d'intérêt qu'immédiate, et le village n'exploite aucun ouvrier de
 * file d'attente : un écouteur `ShouldQueue` s'empilerait dans la table
 * `jobs` sans que personne ne l'en sorte, ce qui serait pire que pas
 * d'alerte du tout — l'illusion d'une alerte. Le coût est une requête
 * et quelques insertions, sur un événement qui ne part qu'au
 * franchissement.
 *
 * L'écouteur ne porte aucune action cliquable. Une action Filament
 * s'appuie sur une URL de panneau, que `varbaf:importer` — qui écrit du
 * stock en ligne de commande, hors de tout panneau — ne saurait
 * construire. Un import ne doit pas tomber parce qu'une alerte a voulu
 * fabriquer un lien.
 */
class NotifierSeuilAlerte
{
    /**
     * Les deux sections nommées par la règle 15.
     *
     * Répétées ici bien que la configuration les porte : un
     * `config()` qui rendrait null — fichier non fusionné, cache de
     * configuration périmé — laisserait l'alerte sans destinataire, en
     * silence. Le repli garantit qu'elle part quand même, et le test
     * `test_la_configuration_du_module_est_enregistree` échoue
     * bruyamment si c'est le repli qui travaille.
     *
     * @var array<int, string>
     */
    public const ROLES_PAR_DEFAUT = [
        'chef_section_production',
        'chef_section_promotion_commercialisation',
    ];

    public function handle(SeuilAlerteFranchi $evenement): void
    {
        $destinataires = $this->destinataires();

        if ($destinataires->isEmpty()) {
            return;
        }

        Notification::make()
            ->title('Stock au seuil d\'alerte')
            ->body($this->corps($evenement))
            ->warning()
            ->sendToDatabase($destinataires);
    }

    /**
     * Les comptes actifs portant l'un des rôles destinataires.
     *
     * `whereHas` plutôt que le scope `role()` de spatie : celui-ci lève
     * `RoleDoesNotExist` dès qu'un nom de la liste n'existe pas en base.
     * Un rôle renommé dans l'organigramme ferait alors échouer une
     * vente, faute d'avoir pu envoyer une alerte de stock. L'ordre des
     * dégâts serait inversé.
     *
     * @return Collection<int, Utilisateur>
     */
    protected function destinataires(): Collection
    {
        $roles = config('commerce.alerte_stock.roles_destinataires') ?: self::ROLES_PAR_DEFAUT;

        return Utilisateur::query()
            ->where('actif', true)
            ->whereHas('roles', fn (Builder $requete) => $requete->whereIn('name', $roles))
            ->get();
    }

    /**
     * Le message : ce qu'il faut pour agir sans ouvrir un écran.
     *
     * Le produit, l'artisan à prévenir, la boutique où aller, la
     * quantité restante et le seuil. Un chef de section qui lit cette
     * ligne sur son téléphone sait quoi faire.
     */
    protected function corps(SeuilAlerteFranchi $evenement): string
    {
        $produit = $evenement->produit;
        $artisan = $produit->artisan?->nom_complet;
        $boutique = $produit->boutique?->numero;

        $origine = array_filter([
            $artisan,
            $boutique ? "boutique {$boutique}" : null,
        ]);

        return sprintf(
            '%s%s : %d en stock pour un seuil de %d.',
            $produit->identite,
            $origine === [] ? '' : ' ('.implode(', ', $origine).')',
            $evenement->soldeApres,
            $evenement->seuil,
        );
    }
}
