<?php

namespace Modules\Tresorerie\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Socle\Models\Utilisateur;
use Modules\Tresorerie\Exceptions\ArreteCaisseException;

/**
 * Arrêté de caisse journalier (§7.7, RG-25 à RG-27).
 *
 * Un enregistrement de contrôle, pas un conteneur de mouvements : il
 * constate, à une date donnée, l'écart entre ce que le brouillard
 * annonce (`solde_theorique`, calculé) et ce que le caissier a compté
 * (`solde_physique`, saisi). `ecart` est déduit des deux — jamais saisi
 * séparément — et un écart non nul exige `commentaire_ecart` (RG-26) :
 * la garde vit ici, dans le modèle, pas dans l'écran qui le présente.
 *
 * Une fois créé, un arrêté ne se modifie ni ne se supprime : c'est un
 * constat daté, au même titre qu'une écriture du journal d'audit.
 *
 * @property int $id
 * @property int $caisse_id
 * @property int $section_id
 * @property \Illuminate\Support\Carbon $date_arrete
 * @property int $solde_theorique
 * @property int $solde_physique
 * @property int $ecart
 * @property string|null $commentaire_ecart
 * @property int|null $arrete_par
 */
class ArreteCaisse extends Model
{
    protected $table = 'arretes_caisse';

    protected $fillable = [
        'caisse_id',
        'section_id',
        'date_arrete',
        'solde_theorique',
        'solde_physique',
        'ecart',
        'commentaire_ecart',
        'arrete_par',
        'date_validation',
    ];

    protected function casts(): array
    {
        return [
            'date_arrete' => 'date',
            'date_validation' => 'datetime',
            'solde_theorique' => 'integer',
            'solde_physique' => 'integer',
            'ecart' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $arrete): void {
            // RG-26 : un écart non nul exige sa justification. Vérifié
            // ici pour qu'aucune voie d'écriture — écran, service,
            // console — ne puisse l'omettre.
            if ($arrete->ecart !== 0 && blank($arrete->commentaire_ecart)) {
                throw ArreteCaisseException::ecartNonJustifie();
            }
        });

        static::updating(function (self $arrete): void {
            throw ArreteCaisseException::immuable();
        });

        static::deleting(function (self $arrete): void {
            throw ArreteCaisseException::immuable();
        });
    }

    public function caisse(): BelongsTo
    {
        return $this->belongsTo(Caisse::class, 'caisse_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(SectionCaisse::class, 'section_id');
    }

    public function arretePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'arrete_par');
    }

    public function estEquilibre(): bool
    {
        return $this->ecart === 0;
    }
}
