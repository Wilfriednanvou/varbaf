<?php

namespace Modules\Portail\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Socle\Models\Utilisateur;

/**
 * Texte de présentation affiché par le portail, éditable depuis le
 * panneau.
 *
 * Une clé libre plutôt qu'une énumération figée : la coordination doit
 * pouvoir ajouter un encart sans redéploiement. Le portail demande une
 * clé et affiche ce qu'il trouve — un contenu absent se traduit par une
 * section manquante, jamais par une erreur.
 *
 * C'est un libellé, pas une histoire : au sens de la règle de
 * suppression de CLAUDE.md, il est corrigible et supprimable par qui
 * peut le créer.
 */
class ContenuPage extends Model
{
    protected $table = 'contenus_page';

    protected $fillable = [
        'cle',
        'titre',
        'corps',
        'actif',
        'ordre_affichage',
        'modifie_par',
    ];

    protected $attributes = [
        'actif' => true,
        'ordre_affichage' => 0,
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'ordre_affichage' => 'integer',
        ];
    }

    public function modifiePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'modifie_par');
    }

    public function scopeActif(Builder $requete): Builder
    {
        return $requete->where('actif', true);
    }
}
