<?php

namespace Modules\Artisanat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Artisanat\Enums\NatureContenant;
use Modules\Artisanat\Enums\ZoneBoutique;
use Modules\Socle\Models\VillageArtisanal;

/**
 * Local du village : un contenant physique, rien de plus.
 *
 * **Ce qui a quitté cette classe.** La boutique portait un occupant, un
 * état d'occupation, un tarif au mètre carré et une redevance calculée.
 * Le relevé du parc réel a montré que ces quatre notions ne
 * s'appliquaient pas à elle : dix-sept boutiques abritent bien plus
 * d'artisans, plusieurs se partageant le même local. Un local n'a donc
 * ni occupant unique ni redevance propre — ce sont les espaces locatifs
 * qu'il contient qui se louent, chacun pour un montant convenu.
 *
 * Ce qui reste ici est ce qui est vraiment de la boutique : son numéro,
 * sa place dans le bâtiment, sa surface, et la liste des espaces
 * qu'elle abrite.
 *
 * **Ce que la classe abrite en plus depuis le 26/08.** Le sous-sol et
 * l'espace vert, qu'on croyait dépourvus d'espace locatif, en portent
 * trois : la table devient celle des contenants et `nature` dit lequel
 * est un local de vente. Le nom `Boutique` ne bouge pas — le renommer à
 * huit jours du gel coûterait plus que la gêne de lecture.
 *
 * @property int $id
 * @property string $numero
 * @property NatureContenant $nature
 * @property int $village_id
 */
class Boutique extends Model
{
    protected $table = 'boutiques';

    protected $fillable = [
        'numero',
        'nature',
        'superficie',
        'emplacement',
        'village_id',
    ];

    protected $attributes = [
        'nature' => 'BOUTIQUE',
    ];

    protected function casts(): array
    {
        return [
            'superficie' => 'decimal:2',
            'emplacement' => ZoneBoutique::class,
            'nature' => NatureContenant::class,
        ];
    }

    /**
     * Les seuls locaux de vente.
     *
     * C'est le périmètre du taux d'occupation présenté à la tutelle :
     * le sous-sol et l'espace vert se louent, mais on n'y vend pas.
     */
    public function scopeLocauxDeVente(Builder $requete): Builder
    {
        return $requete->where('nature', NatureContenant::BOUTIQUE->value);
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(VillageArtisanal::class, 'village_id');
    }

    public function espacesLocatifs(): HasMany
    {
        return $this->hasMany(EspaceLocatif::class, 'boutique_id');
    }

    /**
     * Artisans installés dans la boutique à la date du jour.
     *
     * Plusieurs, désormais : c'est tout l'objet de la correction.
     *
     * @return array<int, Artisan>
     */
    public function occupantsActuels(): array
    {
        return $this->espacesLocatifs()
            ->get()
            ->map(fn (EspaceLocatif $espace) => $espace->getOccupantActuel())
            ->filter()
            ->values()
            ->all();
    }
}
