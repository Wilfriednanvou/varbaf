<?php

namespace Modules\Tresorerie\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Socle\Models\Exercice;
use Modules\Socle\Models\Utilisateur;
use Modules\Socle\Models\VillageArtisanal;
use Modules\Tresorerie\Enums\EtatSectionCaisse;
use Modules\Tresorerie\Enums\SensMouvementCaisse;
use Modules\Tresorerie\Exceptions\SectionCaisseException;

/**
 * Section de caisse — exercice d'une caisse (RG-01, RG-02, RG-07).
 *
 * Une section couvre un exercice comptable. Une seule peut être ouverte
 * par caisse à tout moment. Le solde d'ouverture est égal au solde de
 * clôture de la section précédente. La clôture est irréversible.
 *
 * @property int $id
 * @property string $libelle
 * @property EtatSectionCaisse $etat
 * @property int $solde_ouverture
 * @property int|null $solde_cloture
 * @property int $caisse_id
 * @property int $village_id
 * @property int $exercice_id
 */
class SectionCaisse extends Model
{
    protected $table = 'sections_caisse';

    protected $fillable = [
        'caisse_id',
        'libelle',
        'date_ouverture',
        'solde_ouverture',
        'etat',
        'ouverte_par',
        'village_id',
        'exercice_id',
    ];

    protected function casts(): array
    {
        return [
            'etat' => EtatSectionCaisse::class,
            'date_ouverture' => 'datetime',
            'date_cloture' => 'datetime',
            'solde_ouverture' => 'integer',
            'solde_cloture' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $section): void {
            // RG-02 : le solde d'ouverture est celui de clôture de la
            // section précédente de la même caisse.
            //
            // Calculé ici, et non proposé comme valeur par défaut à
            // l'écran. Une valeur par défaut se corrige dans le
            // formulaire : la règle se laissait alors écraser à la
            // saisie, ce qui en faisait une suggestion et non une
            // règle — et ouvrait la voie la plus directe pour
            // fabriquer du solde de caisse.
            $section->solde_ouverture = static::soldeDOuverturePour($section->caisse_id);

            // RG-01 : une seule section ouverte par caisse. Vérifié ici
            // (et pas seulement dans l'écran) pour qu'aucun appel — seeder,
            // console, écran — ne puisse contourner la règle ; l'index
            // unique partiel en base est la seconde ligne de défense.
            if ($section->etat !== EtatSectionCaisse::OUVERTE) {
                return;
            }

            $ouverte = self::query()
                ->where('caisse_id', $section->caisse_id)
                ->where('etat', EtatSectionCaisse::OUVERTE->value)
                ->first();

            if ($ouverte) {
                throw SectionCaisseException::dejaUneOuverte($ouverte->libelle);
            }
        });

        static::updating(function (self $section): void {
            // Une section clôturée est figée. Seul le service de
            // clôture a le droit d'écrire les colonnes de clôture,
            // et il le fait via `forceFill()` qui contourne $fillable
            // mais pas les hooks — d'où la vérification sur l'état
            // d'origine plutôt que l'état courant.
            $etatOrigine = $section->getOriginal('etat');
            $etatOrigineValeur = $etatOrigine instanceof EtatSectionCaisse
                ? $etatOrigine
                : EtatSectionCaisse::tryFrom($etatOrigine);

            if ($etatOrigineValeur === EtatSectionCaisse::CLOTUREE) {
                throw SectionCaisseException::sectionFigee($section->libelle);
            }
        });

        static::deleting(function (self $section): void {
            if ($section->etat === EtatSectionCaisse::CLOTUREE) {
                throw SectionCaisseException::sectionFigee($section->libelle);
            }

            if ($section->mouvements()->exists()) {
                throw SectionCaisseException::sectionFigee($section->libelle);
            }
        });
    }

    public function caisse(): BelongsTo
    {
        return $this->belongsTo(Caisse::class, 'caisse_id');
    }

    public function mouvements(): HasMany
    {
        return $this->hasMany(MouvementCaisse::class, 'section_id')
            ->orderBy('numero_ordre');
    }

    public function ouvertePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'ouverte_par');
    }

    public function clotureePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'cloturee_par');
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(Exercice::class, 'exercice_id');
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(VillageArtisanal::class, 'village_id');
    }

    public function estOuverte(): bool
    {
        return $this->etat === EtatSectionCaisse::OUVERTE;
    }

    public function estCloturee(): bool
    {
        return $this->etat === EtatSectionCaisse::CLOTUREE;
    }

    /**
     * Clôture la section (RG-07) : fige le solde de clôture, verrouille
     * l'écriture. Irréversible — point d'entrée unique, pour que les
     * deux écrans qui l'exposent (ressource et session de caisse)
     * n'aient plus à dupliquer ce calcul.
     */
    public function cloturer(): int
    {
        if (! $this->estOuverte()) {
            throw SectionCaisseException::dejaCloturee($this->libelle);
        }

        // RG-07 : la clôture n'est possible que si toutes les journées
        // de la section ont été arrêtées.
        //
        // Sans ce contrôle, un exercice entier pouvait se clôturer sans
        // qu'aucun comptage physique n'ait eu lieu — ce qui vidait de
        // sa substance le mécanisme de contrôle interne quotidien que
        // l'arrêté journalier apporte (§7.7). La section couvrant un
        // exercice, l'écart n'aurait été constaté qu'un an trop tard,
        // ou jamais.
        $joursNonArretes = $this->journeesNonArretees();

        if ($joursNonArretes !== []) {
            throw SectionCaisseException::journeesNonArretees($this->libelle, $joursNonArretes);
        }

        $soldeCourant = $this->soldeCourant();

        $this->forceFill([
            'date_cloture' => now(),
            'solde_cloture' => $soldeCourant,
            'etat' => EtatSectionCaisse::CLOTUREE,
            'cloturee_par' => auth()->id(),
        ])->save();

        return $soldeCourant;
    }

    /**
     * Solde courant calculé depuis le brouillard.
     */
    public function soldeCourant(): int
    {
        $entrees = (int) $this->mouvements()
            ->where('sens', SensMouvementCaisse::ENTREE->value)
            ->sum('montant');

        $sorties = (int) $this->mouvements()
            ->where('sens', SensMouvementCaisse::SORTIE->value)
            ->sum('montant');

        return $this->solde_ouverture + $entrees - $sorties;
    }

    /**
     * Les journées de la section qui portent des mouvements sans avoir
     * été arrêtées (RG-07).
     *
     * La requête part de `MouvementCaisse` et non de la relation
     * `mouvements()` : celle-ci trie par numéro d'ordre, et PostgreSQL
     * refuse un `SELECT DISTINCT` dont le `ORDER BY` porte sur une
     * colonne absente de la projection.
     *
     * Les dates arrêtées sont chargées en une fois plutôt qu'interrogées
     * par jour : une section couvre un exercice, et un test par journée
     * ferait plusieurs centaines de requêtes pour une seule clôture.
     *
     * @return array<int, string> journées au format AAAA-MM-JJ
     */
    public function journeesNonArretees(): array
    {
        $joursAvecMouvements = MouvementCaisse::query()
            ->where('section_id', $this->getKey())
            ->selectRaw('date(date_operation) as jour')
            ->distinct()
            ->orderBy('jour')
            ->pluck('jour')
            ->map(fn ($jour) => $jour instanceof \DateTimeInterface
                ? $jour->format('Y-m-d')
                : substr((string) $jour, 0, 10));

        if ($joursAvecMouvements->isEmpty()) {
            return [];
        }

        $joursArretes = ArreteCaisse::query()
            ->where('caisse_id', $this->caisse_id)
            ->pluck('date_arrete')
            ->map(fn ($jour) => $jour instanceof \DateTimeInterface
                ? $jour->format('Y-m-d')
                : substr((string) $jour, 0, 10))
            ->all();

        return $joursAvecMouvements
            ->reject(fn (string $jour) => in_array($jour, $joursArretes, true))
            ->values()
            ->all();
    }

    /**
     * Le solde de clôture de la dernière section clôturée de la caisse,
     * ou zéro s'il n'y en a pas (RG-02).
     *
     * Triée par date de clôture et non par identifiant : c'est la
     * chronologie des exercices qui fait la continuité du solde, pas
     * l'ordre d'insertion des lignes.
     */
    public static function soldeDOuverturePour(?int $caisseId): int
    {
        if (! $caisseId) {
            return 0;
        }

        $derniere = static::query()
            ->where('caisse_id', $caisseId)
            ->where('etat', EtatSectionCaisse::CLOTUREE->value)
            ->orderByDesc('date_cloture')
            ->orderByDesc('id')
            ->first();

        return (int) ($derniere?->solde_cloture ?? 0);
    }

    public function getIdentiteAttribute(): string
    {
        return "{$this->libelle} ({$this->etat->getLabel()})";
    }
}
