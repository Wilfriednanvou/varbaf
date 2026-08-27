<?php

namespace Modules\Pilotage\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Pilotage\Enums\TypeFicheLexicale;

/**
 * Une fiche du corpus indexé.
 *
 * @property int $id
 * @property TypeFicheLexicale $type
 * @property int $source_id
 * @property string $titre
 * @property string|null $texte
 * @property int $nombre_termes
 * @property float $norme
 * @property array<int, float>|null $vecteur
 * @property string|null $vecteur_modele
 * @property string|null $vecteur_empreinte
 * @property string|null $empreinte
 * @property \Illuminate\Support\Carbon|null $indexee_le
 */
class FicheLexicale extends Model
{
    protected $table = 'fiches_lexicales';

    protected $fillable = [
        'type',
        'source_id',
        'titre',
        'texte',
        'nombre_termes',
        'norme',
        'vecteur',
        'vecteur_modele',
        'vecteur_empreinte',
        'empreinte',
        'indexee_le',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeFicheLexicale::class,
            'source_id' => 'integer',
            'nombre_termes' => 'integer',
            'norme' => 'float',
            // Le vecteur dense est lu en bloc et comparé en PHP : le
            // cast le rend directement sous forme de tableau de
            // flottants, sans que chaque appelant ait à le décoder.
            'vecteur' => 'array',
            'indexee_le' => 'datetime',
        ];
    }

    public function termes(): HasMany
    {
        return $this->hasMany(TermeLexical::class, 'fiche_id');
    }

    public function scopeDeType(Builder $requete, TypeFicheLexicale $type): Builder
    {
        return $requete->where('type', $type->value);
    }

    /**
     * Une fiche sans terme ne peut être comparée à rien : sa norme est
     * nulle et le cosinus diviserait par zéro. Les moteurs l'écartent
     * plutôt que de se protéger au cas par cas.
     */
    public function scopeComparable(Builder $requete): Builder
    {
        return $requete->where('norme', '>', 0);
    }

    public function estComparable(): bool
    {
        return $this->norme > 0;
    }

    /**
     * Le vecteur creux de la fiche, terme => poids.
     *
     * @return array<string, float>
     */
    public function vecteur(): array
    {
        return $this->termes
            ->pluck('poids', 'terme')
            ->map(fn ($poids) => (float) $poids)
            ->all();
    }
}
