<?php

namespace Modules\Socle\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Groupes de navigation du panneau d'administration.
 *
 * Déclaré dans le Socle parce que tous les modules en dépendent :
 * la règle de dépendance descendante autorise Artisanat, Commerce,
 * Trésorerie et Pilotage à référencer le Socle, jamais l'inverse.
 */
enum NavigationGroup: string implements HasLabel
{
    case SOCLE = 'socle';
    case ARTISANAT = 'artisanat';
    case COMMERCE = 'commerce';
    case TRESORERIE = 'tresorerie';
    case PILOTAGE = 'pilotage';
    case PORTAIL = 'portail';
    case SECURITE = 'securite';

    public function getLabel(): string
    {
        return match ($this) {
            self::SOCLE => 'Référentiel',
            self::ARTISANAT => 'Artisanat',
            self::COMMERCE => 'Commerce',
            self::TRESORERIE => 'Trésorerie',
            self::PILOTAGE => 'Pilotage',
            self::PORTAIL => 'Portail public',
            self::SECURITE => 'Sécurité',
        };
    }
}
