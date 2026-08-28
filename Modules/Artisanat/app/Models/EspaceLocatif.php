<?php

namespace Modules\Artisanat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Modules\Artisanat\Enums\EtatEspaceLocatif;
use Modules\Artisanat\Enums\StatutAttribution;
use Modules\Artisanat\Exceptions\EspaceLocatifException;

/**
 * Unité réellement louée du village : une place de vente à l'intérieur
 * d'une boutique.
 *
 * **Ce que le relevé du village a corrigé.** Le parc compte dix-sept
 * boutiques, mais bien plus d'artisans installés : plusieurs d'entre eux
 * se partagent le même local. Tant que l'attribution portait sur la
 * boutique, deux artisans voisins produisaient un chevauchement et le
 * système refusait la seconde attribution — c'est-à-dire qu'il refusait
 * la situation réelle. L'espace locatif est la maille qui manquait :
 * c'est lui qui se loue, lui qui est libre ou pris, lui que la règle de
 * non-chevauchement protège. La boutique redevient ce qu'elle est
 * physiquement, un contenant.
 *
 * **Code dérivé et figé.** B01 abrite B0101, B0102… Le code se compose à
 * la création à partir du numéro de la boutique et d'un rang, et ne
 * bouge plus : il figure sur les contrats signés.
 *
 * **Dérivé par défaut, pas par obligation.** Un code posé explicitement
 * à la création survit au crochet : le relevé du village porte des
 * espaces dont le code ne suit pas la règle — le sous-sol SS01 abrite
 * G0201 — et ces codes-là figurent sur des contrats. Les renommer pour
 * les faire rentrer dans la règle romprait le lien avec le papier.
 * `code` reste hors de `$fillable` : il se pose depuis un seeder ou une
 * reprise, jamais depuis un formulaire.
 *
 * @property int $id
 * @property string $code
 * @property string|null $libelle
 * @property EtatEspaceLocatif $etat
 * @property int $boutique_id
 */
class EspaceLocatif extends Model
{
    protected $table = 'espaces_locatifs';

    /**
     * `code` est volontairement absent : il se dérive de la boutique,
     * il ne se saisit pas.
     */
    protected $fillable = [
        'libelle',
        'etat',
        'boutique_id',
    ];

    /**
     * Un espace naît disponible. Le défaut est porté par le modèle en
     * plus de la base pour que l'état soit lisible sur une instance
     * neuve, avant même l'écriture.
     */
    protected $attributes = [
        'etat' => 'DISPONIBLE',
    ];

    protected function casts(): array
    {
        return [
            'etat' => EtatEspaceLocatif::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $espace): void {
            if (blank($espace->code)) {
                $espace->code = static::genererCode((int) $espace->boutique_id);
            }
        });

        static::updating(function (self $espace): void {
            if ($espace->isDirty('code')) {
                throw EspaceLocatifException::codeFige((string) $espace->getOriginal('code'));
            }

            // Le code encode la boutique : les laisser diverger
            // produirait un B0103 rattaché à B02.
            if ($espace->isDirty('boutique_id')) {
                throw EspaceLocatifException::boutiqueFigee((string) $espace->code);
            }
        });

        static::deleting(function (self $espace): void {
            if ($espace->attributions()->exists()) {
                throw EspaceLocatifException::occupeParUneAttribution((string) $espace->code);
            }
        });
    }

    /**
     * Compose le préfixe de code d'un contenant.
     *
     * **Pourquoi la partie alphabétique compte.** La première version ne
     * gardait que les chiffres : « B01 », « B-01 » et « 1 » donnaient
     * tous « B01 », ce qui suffisait tant que le parc n'était fait que
     * de boutiques numérotées. Depuis que le sous-sol et l'espace vert
     * sont entrés dans le parc, « SS01 » et « EV01 » se réduisaient eux
     * aussi à « B01 » — trois contenants distincts fabriquant les mêmes
     * codes, et le rang de l'un décalant celui des autres. Les lettres
     * sont donc conservées telles quelles ; un numéro purement
     * numérique reçoit toujours le « B » historique, pour que les codes
     * déjà émis ne bougent pas.
     */
    public static function genererPrefixe(int|string|null $numero): string
    {
        $propre = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $numero));

        if (! preg_match('/^([A-Z]*)(\d*)$/', $propre, $parties)) {
            // Numéro composite du type « B1A » : rien à décomposer, il
            // sert de préfixe tel quel plutôt que d'être mutilé.
            return $propre !== '' ? $propre : 'B00';
        }

        $lettres = $parties[1] !== '' ? $parties[1] : 'B';
        $chiffres = $parties[2];

        // Un numéro sans chiffres — « HALL » — se suffit à lui-même : lui
        // coller un « 00 » n'ajouterait qu'un rang qui n'existe pas.
        return $chiffres === ''
            ? $lettres
            : $lettres.str_pad($chiffres, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Produit le code suivant pour un contenant : B01 donne B0101, puis
     * B0102 ; SS01 donne SS0101.
     *
     * Le rang est propre au contenant, ce qui rend le code lisible à
     * l'œil sur un plan du bâtiment.
     */
    public static function genererCode(int $boutiqueId): string
    {
        $prefixe = static::genererPrefixe(
            Boutique::query()->whereKey($boutiqueId)->value('numero'),
        );

        return DB::transaction(function () use ($boutiqueId, $prefixe): string {
            // Le rang se cherche numériquement, et non par tri du code.
            //
            // « B0099 » et « B00100 » ne se comparent pas comme les
            // nombres qu'ils encodent : lexicographiquement, le premier
            // est le plus grand. Un `orderByDesc('code')` retournerait
            // donc B0099 alors que B00100 existe, et la boutique
            // reproposerait au centième espace un code déjà pris — que
            // l'index unique refuserait, sans qu'on comprenne pourquoi.
            //
            // Le cas n'est pas théorique : la reprise du registre
            // transcrit rattache plus de cent emplacements hors parc à
            // une même boutique technique.
            //
            // Seuls les codes qui suivent la règle entrent dans le
            // calcul du rang. Un code posé au relevé — G0201 sous SS01 —
            // ne dit rien du rang suivant : lui appliquer `substr` sur
            // la longueur du préfixe découperait une position
            // arbitraire de sa numérotation. L'unicité du couple
            // (boutique, code) reste la garantie contre la collision.
            $rangs = static::query()
                ->where('boutique_id', $boutiqueId)
                ->lockForUpdate()
                ->pluck('code')
                ->filter(fn (string $code) => str_starts_with($code, $prefixe))
                ->map(fn (string $code) => (int) substr($code, strlen($prefixe)))
                ->all();

            $rang = ($rangs === [] ? 0 : max($rangs)) + 1;

            return $prefixe.str_pad((string) $rang, 2, '0', STR_PAD_LEFT);
        });
    }

    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class, 'boutique_id');
    }

    public function attributions(): HasMany
    {
        return $this->hasMany(AttributionEspace::class, 'espace_locatif_id');
    }

    public function attributionsActives(): HasMany
    {
        return $this->attributions()->where('statut', StatutAttribution::ACTIVE->value);
    }

    public function scopeAttribuable(Builder $requete): Builder
    {
        return $requete->where('etat', '!=', EtatEspaceLocatif::INDISPONIBLE->value);
    }

    /**
     * La clause « attribution en cours », écrite une seule fois.
     *
     * Reprise telle quelle par `getOccupantActuel()`, par les deux
     * scopes ci-dessous et par les indicateurs des deux modules. La
     * dupliquer serait le moyen le plus sûr de finir avec deux comptages
     * différents de la même chose.
     */
    protected static function clauseEnCours(Builder $requete): Builder
    {
        return $requete
            ->where('statut', StatutAttribution::ACTIVE->value)
            ->whereDate('date_debut', '<=', now())
            ->where(fn (Builder $terme) => $terme
                ->whereNull('date_fin')
                ->orWhereDate('date_fin', '>=', now()));
    }

    /**
     * Espaces portant une attribution en cours à la date du jour.
     *
     * **L'occupation se calcule, elle ne se stocke pas.** La colonne
     * `etat` porte aussi une valeur `OCCUPE`, mais elle est écrite à
     * l'import et **jamais mise à jour par les attributions** :
     * `AttributionEspace` la lit — pour refuser un espace indisponible —
     * et ne l'écrit nulle part. Une attribution qui atteint sa date de
     * fin libère donc l'espace au sens du métier tout en le laissant
     * « Occupé » en base, définitivement.
     *
     * Compter sur `etat` revient à faire d'un champ dénormalisé la
     * source de vérité d'un fait qui vit ailleurs. C'est exactement ce
     * que RG-9 interdit pour le solde de l'artisan — « calculé, jamais
     * stocké comme valeur modifiable » — et rien ne justifie que
     * l'occupation d'un espace y échappe.
     *
     * Les deux définitions donnaient le même nombre le 28/08, l'import
     * ayant écrit `OCCUPE` sur exactement les espaces attribués. Ce
     * scope existe pour qu'elles ne puissent plus se séparer en silence.
     */
    public function scopeOccupe(Builder $requete): Builder
    {
        return $requete->whereHas('attributions', static::clauseEnCours(...));
    }

    /**
     * Attribuable et sans occupant à la date du jour.
     *
     * Ce n'est pas « tout sauf occupé » : un espace indisponible n'est
     * ni occupé ni libre, et l'ajouter aux libres annoncerait une
     * capacité qui n'existe pas.
     */
    public function scopeLibre(Builder $requete): Builder
    {
        return $requete->attribuable()->whereDoesntHave('attributions', static::clauseEnCours(...));
    }

    /**
     * Artisan attributaire à la date du jour.
     */
    public function getOccupantActuel(): ?Artisan
    {
        return $this->attributionsActives()
            ->whereDate('date_debut', '<=', now())
            ->where(fn (Builder $requete) => $requete
                ->whereNull('date_fin')
                ->orWhereDate('date_fin', '>=', now()))
            ->latest('date_debut')
            ->first()
            ?->artisan;
    }

    /**
     * Vérifie l'absence d'attribution active sur la période.
     *
     * Une date de fin nulle vaut « sans terme » : la période court
     * jusqu'à l'infini et recouvre donc toute attribution ultérieure.
     */
    public function estDisponible(string $dateDebut, ?string $dateFin = null, ?int $ignorerAttributionId = null): bool
    {
        if ($this->etat === EtatEspaceLocatif::INDISPONIBLE) {
            return false;
        }

        return ! AttributionEspace::existeChevauchement(
            $this->getKey(),
            $dateDebut,
            $dateFin,
            $ignorerAttributionId,
        );
    }

    /**
     * Réaligne l'état sur la réalité des contrats.
     *
     * Appelée par AttributionEspace à chaque création, modification ou
     * résiliation. INDISPONIBLE n'est jamais écrasé : c'est une décision
     * administrative que le système n'a pas à défaire.
     */
    public function synchroniserEtat(): void
    {
        if ($this->etat === EtatEspaceLocatif::INDISPONIBLE) {
            return;
        }

        $occupe = $this->attributionsActives()
            ->whereDate('date_debut', '<=', now())
            ->where(fn (Builder $requete) => $requete
                ->whereNull('date_fin')
                ->orWhereDate('date_fin', '>=', now()))
            ->exists();

        $etat = $occupe ? EtatEspaceLocatif::OCCUPE : EtatEspaceLocatif::DISPONIBLE;

        if ($this->etat !== $etat) {
            $this->etat = $etat;
            $this->save();
        }
    }

    public function getIdentiteAttribute(): string
    {
        return filled($this->libelle)
            ? "{$this->code} — {$this->libelle}"
            : (string) $this->code;
    }
}
