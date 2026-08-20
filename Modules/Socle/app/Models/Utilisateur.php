<?php

namespace Modules\Socle\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Compte d'accès au système, rattaché à un agent du village.
 *
 * Séparation volontaire des deux notions : l'agent est une personne
 * physique du village, l'utilisateur est un moyen d'accès. Un agent
 * peut exister sans compte (personnel non informatisé) et un compte
 * technique peut exister sans agent. Le lien reste donc facultatif.
 *
 * Le modèle vit dans le Socle et non dans app/Models : le tableau des
 * modules de CLAUDE.md attribue les utilisateurs, rôles et permissions
 * au Socle. Il conserve la table « users » livrée par Laravel, ce qui
 * évite de réécrire la migration de base et les tables de session et de
 * réinitialisation de mot de passe qui la référencent.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $actif
 * @property int|null $agent_id
 */
class Utilisateur extends Authenticatable implements FilamentUser
{
    use HasRoles, Notifiable;

    public const ROLE_SUPER_UTILISATEUR = 'super_utilisateur';

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'actif',
        'agent_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'actif' => 'boolean',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    /**
     * Un compte désactivé ne peut plus entrer dans le panneau, même si
     * ses rôles restent attribués. La désactivation est préférée à la
     * suppression : elle préserve les traces d'audit du compte.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) $this->actif;
    }

    public function estSuperUtilisateur(): bool
    {
        return $this->hasRole(self::ROLE_SUPER_UTILISATEUR);
    }

    public function getFilamentName(): string
    {
        return $this->agent?->nom_complet ?? $this->name;
    }
}
