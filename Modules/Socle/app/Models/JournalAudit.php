<?php

namespace Modules\Socle\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Modules\Socle\Exceptions\JournalAuditImmuableException;

/**
 * Journal d'audit : trace de toute action sensible du système.
 *
 * **Table en écriture seule, imposée par le modèle.** Les crochets
 * « updating » et « deleting » lèvent une exception : la règle vaut
 * donc pour tout le système, et plus seulement pour la ressource
 * Filament qui masquait les boutons. Le modèle ne porte pas non plus
 * de colonne « updated_at » — une ligne d'audit modifiable n'aurait
 * aucune valeur probante en soutenance comme en exploitation.
 *
 * Limite connue : les crochets Eloquent ne se déclenchent pas sur une
 * suppression de masse passée par le constructeur de requêtes
 * (`JournalAudit::query()->delete()`) ni sur un `DELETE` SQL direct.
 * Fermer complètement cette porte demanderait un déclencheur
 * PostgreSQL ou un retrait du droit DELETE au rôle applicatif, ce qui
 * dépasse le périmètre de ce correctif.
 *
 * Le nom de l'utilisateur est figé dans la ligne en plus de la clé
 * étrangère : si le compte est supprimé ou renommé, la trace reste
 * lisible. C'est le même principe de figement que celui appliqué aux
 * ventes.
 *
 * @property int $id
 * @property string $action
 * @property string $module
 * @property string|null $entite
 * @property int|null $entite_id
 * @property array|null $donnees
 */
class JournalAudit extends Model
{
    protected $table = 'journaux_audit';

    public const UPDATED_AT = null;

    protected $fillable = [
        'action',
        'module',
        'entite',
        'entite_id',
        'donnees',
        'utilisateur_id',
        'nom_utilisateur',
        'adresse_ip',
    ];

    protected function casts(): array
    {
        return [
            'donnees' => 'array',
            'entite_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Une exception, et non un « return false » : ce dernier
        // annulerait l'opération en silence, et l'appelant croirait sa
        // suppression effectuée.
        static::updating(function (self $ecriture): void {
            throw JournalAuditImmuableException::modification();
        });

        static::deleting(function (self $ecriture): void {
            throw JournalAuditImmuableException::suppression();
        });
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }

    /**
     * Point d'entrée unique de l'audit.
     *
     * Appelée depuis le crochet ->after() de chaque action Filament.
     * L'écriture ne doit jamais faire échouer l'action métier qu'elle
     * trace : une panne du journal ne peut pas annuler une vente déjà
     * encaissée. Les erreurs sont donc absorbées et remontées dans le
     * journal applicatif.
     *
     * @param  array<string, mixed>  $donnees
     */
    public static function enregistrer(
        string $action,
        string $module,
        ?string $entite = null,
        ?int $entiteId = null,
        array $donnees = [],
    ): ?self {
        try {
            /** @var Utilisateur|null $utilisateur */
            $utilisateur = Auth::user();

            return static::create([
                'action' => $action,
                'module' => $module,
                'entite' => $entite,
                'entite_id' => $entiteId,
                'donnees' => $donnees ?: null,
                'utilisateur_id' => $utilisateur?->getKey(),
                'nom_utilisateur' => $utilisateur?->name ?? 'système',
                'adresse_ip' => request()->ip(),
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
