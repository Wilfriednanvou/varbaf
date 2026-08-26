<?php

namespace Modules\Pilotage\Assistant;

use Modules\Pilotage\Data\FiltreRapport;

/**
 * Ce que la question a livré comme paramètres, et ce qu'elle a tu.
 *
 * **Le silence est une information.** `periodeExplicite` distingue une
 * question qui a nommé sa période — « en juillet », « en 2024 » — d'une
 * question qui n'en a nommé aucune et retombe sur l'exercice courant.
 * Sans cette distinction, l'assistant répondrait « pour juillet » à
 * quelqu'un qui n'a jamais parlé de juillet, ce qui est une forme
 * discrète de mensonge.
 */
final readonly class ParametresQuestion
{
    public function __construct(
        public FiltreRapport $filtre,
        public bool $periodeExplicite = false,
        public string $libellePeriode = 'sur l\'exercice en cours',
        public ?int $artisanId = null,
        public ?string $artisanNom = null,
        public ?string $artisanMatricule = null,
        public ?int $boutiqueId = null,
        public ?string $boutiqueNumero = null,
        public ?int $corpsMetierId = null,
        public ?string $corpsMetierLibelle = null,
    ) {}

    /**
     * Le paramètre demandé est-il présent ?
     */
    public function a(string $parametre): bool
    {
        return match ($parametre) {
            'periode' => $this->periodeExplicite,
            'artisan' => $this->artisanId !== null,
            'boutique' => $this->boutiqueId !== null,
            'corps_metier' => $this->corpsMetierId !== null,
            default => false,
        };
    }

    /**
     * @param  array<int, string>  $requis
     * @return array<int, string>  ceux qui manquent
     */
    public function manquants(array $requis): array
    {
        return array_values(array_filter($requis, fn (string $p): bool => ! $this->a($p)));
    }

    /**
     * @return array<string, mixed>
     */
    public function enTableau(): array
    {
        return array_filter([
            'periode' => $this->periodeExplicite ? $this->libellePeriode : null,
            'artisan' => $this->artisanNom,
            'boutique' => $this->boutiqueNumero,
            'corps_metier' => $this->corpsMetierLibelle,
        ], fn ($valeur): bool => $valeur !== null);
    }

    public static function libelleDuParametre(string $parametre): string
    {
        return match ($parametre) {
            'periode' => 'la période',
            'artisan' => 'le nom de l\'artisan',
            'boutique' => 'le numéro de la boutique',
            'corps_metier' => 'le corps de métier',
            default => $parametre,
        };
    }
}
