<?php

namespace App\Import;

use Illuminate\Database\Eloquent\Model;

/**
 * Ce qu'une ligne du registre est devenue.
 *
 * L'empreinte est la clé de l'idempotence : elle porte sur la ligne
 * source — son rang et son contenu — et non sur la vente produite. Voir
 * la migration `create_lignes_registre_importees_table` pour le
 * raisonnement.
 *
 * Table en écriture seule dans les faits : une ligne déjà reprise n'est
 * jamais relue ni corrigée. Reprendre autrement un registre corrigé se
 * fait en repartant d'une base fraîche, ce qui est la procédure de
 * déploiement du projet.
 *
 * @property string $empreinte
 * @property string $statut
 * @property array<int, string>|null $anomalies
 */
class TraceLigneImportee extends Model
{
    public const STATUT_IMPORTEE = 'IMPORTEE';

    public const STATUT_NON_IMPORTEE = 'NON_IMPORTEE';

    protected $table = 'lignes_registre_importees';

    protected $fillable = [
        'fichier',
        'numero_ligne',
        'empreinte',
        'statut',
        'vente_id',
        'produit_id',
        'artisan_id',
        'espace_locatif_id',
        'anomalies',
    ];

    protected function casts(): array
    {
        return [
            'numero_ligne' => 'integer',
            'anomalies' => 'array',
        ];
    }

    public function estImportee(): bool
    {
        return $this->statut === self::STATUT_IMPORTEE;
    }
}
