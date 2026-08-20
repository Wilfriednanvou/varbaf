<?php

namespace Modules\Socle\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Journal d'audit : trace de toute action sensible du système.
 *
 * Table en écriture seule. Aucune ressource n'expose de modification ni
 * de suppression, et le modèle ne porte volontairement pas de colonne
 * « updated_at » : une ligne d'audit modifiable n'aurait aucune valeur
 * probante en soutenance comme en exploitation.
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
