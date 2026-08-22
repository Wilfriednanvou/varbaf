<?php

namespace Modules\Tresorerie\Livewire\Concerns;

use Filament\Notifications\Notification;
use Modules\Tresorerie\Enums\EtatSectionCaisse;
use Modules\Tresorerie\Models\SectionCaisse;

/**
 * Partagé par les onglets Ventes et Mouvements de caisse : la même
 * section fermée doit refuser une écriture de la même façon, quel que
 * soit le composant qui la propose — c'est la défense côté serveur qui
 * ne dépend pas du `->visible()` d'une action Filament.
 *
 * @property-read int $sectionId
 */
trait VerifieSectionOuverte
{
    protected ?SectionCaisse $sectionCourante = null;

    /**
     * Mémoïsé pour la durée de la requête Livewire en cours — chaque
     * requête réhydrate un composant neuf, cette propriété protégée
     * n'est donc jamais partagée entre deux appels utilisateur.
     */
    protected function section(): ?SectionCaisse
    {
        return $this->sectionCourante ??= SectionCaisse::find($this->sectionId);
    }

    protected function isSectionOpen(): bool
    {
        return $this->section()?->etat === EtatSectionCaisse::OUVERTE;
    }

    /**
     * Envoie la notification de refus et renvoie `true` si la section
     * est fermée — à appeler en tête de chaque méthode d'écriture,
     * avant tout appel à un service métier.
     */
    protected function refuserSiSectionFermee(string $message): bool
    {
        if ($this->isSectionOpen()) {
            return false;
        }

        Notification::make()
            ->title('Section clôturée')
            ->body($message)
            ->danger()
            ->send();

        return true;
    }
}
