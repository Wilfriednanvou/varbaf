<?php

namespace Modules\Portail\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Portail\Enums\StatutDemandeContact;
use Modules\Portail\Exceptions\PublicationPortailException;
use Modules\Socle\Models\Utilisateur;

/**
 * Message reçu par le formulaire de contact du portail.
 *
 * **Le seul enregistrement du système écrit par un visiteur anonyme.**
 * Ce qu'il a écrit est figé dès l'enregistrement : le crochet
 * `updating` n'autorise que les colonnes de suivi. Un message qu'on
 * pourrait retoucher avant de le traiter ne prouverait rien de ce qui a
 * été demandé — même raisonnement que le figement d'une vente.
 *
 * Rien n'est supprimé non plus : ce qui n'appelle pas de réponse
 * s'archive.
 *
 * @property StatutDemandeContact $statut
 */
class DemandeContact extends Model
{
    protected $table = 'demandes_contact';

    protected $fillable = [
        'nom',
        'contact',
        'sujet',
        'message',
        'statut',
        'traitee_par',
        'date_traitement',
        'note_traitement',
        'adresse_ip',
    ];

    protected $attributes = [
        'statut' => 'NOUVELLE',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutDemandeContact::class,
            'date_traitement' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $demande): void {
            // Ce que le visiteur a écrit ne bouge plus. Seul le suivi
            // s'écrit après coup.
            $champsDeSuivi = ['statut', 'traitee_par', 'date_traitement', 'note_traitement', 'updated_at'];

            $champsInterdits = array_diff(array_keys($demande->getDirty()), $champsDeSuivi);

            if ($champsInterdits !== []) {
                throw PublicationPortailException::demandeContactFigee();
            }
        });
    }

    public function traiteePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'traitee_par');
    }

    public function scopeATraiter(Builder $requete): Builder
    {
        return $requete->whereIn('statut', [
            StatutDemandeContact::NOUVELLE->value,
            StatutDemandeContact::EN_COURS->value,
        ]);
    }

    public function estTraitee(): bool
    {
        return $this->statut->estClose();
    }
}
