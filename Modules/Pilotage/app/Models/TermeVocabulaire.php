<?php

namespace Modules\Pilotage\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un terme du vocabulaire du corpus, avec son IDF.
 *
 * @property int $id
 * @property string $terme
 * @property int $documents
 * @property float $idf
 */
class TermeVocabulaire extends Model
{
    protected $table = 'vocabulaire_lexical';

    public $timestamps = false;

    protected $fillable = [
        'terme',
        'documents',
        'idf',
    ];

    protected function casts(): array
    {
        return [
            'documents' => 'integer',
            'idf' => 'float',
        ];
    }

    /**
     * L'IDF des termes demandés, terme => idf.
     *
     * Les termes absents du corpus n'ont pas d'entrée : un terme que
     * personne ne porte ne discrimine rien, et l'appelant le traite
     * comme absent plutôt que comme infiniment rare.
     *
     * @param  array<int, string>  $termes
     * @return array<string, float>
     */
    public static function idfDe(array $termes): array
    {
        if ($termes === []) {
            return [];
        }

        return self::query()
            ->whereIn('terme', $termes)
            ->pluck('idf', 'terme')
            ->map(fn ($idf) => (float) $idf)
            ->all();
    }
}
