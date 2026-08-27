<?php

namespace Modules\Pilotage\Embeddings;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Pilotage\Contracts\FournisseurDEmbeddings;

/**
 * Le fournisseur d'embeddings local : Ollama, sur la machine du village.
 *
 * **Pourquoi Ollama et pas un service distant.** Le déploiement documenté
 * est « un poste du village en réseau local, sans coût ». Un service
 * distant ajouterait une clé d'API, un budget par appel, une connexion
 * Internet le jour de la soutenance — et ferait sortir du village des
 * noms d'artisans et des montants d'argent public. Ollama tourne sur la
 * machine, ne facture rien et ne sort rien.
 *
 * **Ce client ne lève jamais.** Un fournisseur d'embeddings est, par
 * construction, la pièce la plus fragile de la chaîne : un service
 * arrêté, un modèle non téléchargé, un port occupé. Chacun de ces cas
 * rend `null` plutôt qu'une exception, parce que la réponse attendue du
 * système n'est pas une erreur mais un repli sur la branche lexicale —
 * décidé un cran plus haut, par `MoteurHybride`, qui nomme alors ce qui
 * a réellement répondu.
 *
 * **Deux points d'entrée, pour deux âges d'Ollama.** Les versions
 * récentes exposent `/api/embed`, qui accepte plusieurs textes d'un
 * coup ; les plus anciennes `/api/embeddings`, un texte à la fois. Le
 * client tente le premier et bascule définitivement sur le second si le
 * serveur ne le connaît pas. Sans cela, un poste avec un Ollama d'il y a
 * six mois donnerait une branche dense muette sans raison lisible.
 */
class ClientOllama implements FournisseurDEmbeddings
{
    /**
     * Mémo de disponibilité, valable pour la durée du processus.
     *
     * `estDisponible()` est appelée à chaque résolution de moteur, donc
     * potentiellement à chaque question. Sonder le service à chaque fois
     * ajouterait un aller-retour réseau au chemin le plus sensible du
     * système — celui où l'utilisateur attend une réponse.
     */
    protected ?bool $disponible = null;

    /**
     * `null` tant qu'on n'a pas tranché ; `true` pour /api/embed.
     */
    protected ?bool $pointDEntreeGroupe = null;

    public function nom(): string
    {
        return 'Ollama — '.$this->modele();
    }

    public function modele(): string
    {
        return (string) config('pilotage.dense.ollama.modele', 'nomic-embed-text');
    }

    public function estDisponible(): bool
    {
        return $this->disponible ??= $this->sonder();
    }

    /**
     * @param  array<int, string>  $textes
     * @return array<int, array<int, float>|null>
     */
    public function vecteurs(array $textes): array
    {
        if ($textes === [] || ! $this->estDisponible()) {
            return array_fill(0, count($textes), null);
        }

        if ($this->pointDEntreeGroupe === false) {
            return array_map(fn (string $texte): ?array => $this->unVecteur($texte), $textes);
        }

        $reponse = $this->appeler('/api/embed', [
            'model' => $this->modele(),
            'input' => array_values($textes),
        ]);

        // 404 : ce serveur ne connaît pas `/api/embed`. On ne réessaiera
        // plus, et on repart sur le point d'entrée unitaire.
        if ($reponse === 404) {
            $this->pointDEntreeGroupe = false;

            return array_map(fn (string $texte): ?array => $this->unVecteur($texte), $textes);
        }

        if (! is_array($reponse)) {
            return array_fill(0, count($textes), null);
        }

        $this->pointDEntreeGroupe = true;

        /** @var array<int, mixed> $bruts */
        $bruts = $reponse['embeddings'] ?? [];

        return array_map(
            fn (int $rang): ?array => $this->flottants($bruts[$rang] ?? null),
            range(0, count($textes) - 1),
        );
    }

    /**
     * @return array<int, float>|null
     */
    public function vecteur(string $texte): ?array
    {
        return $this->vecteurs([$texte])[0] ?? null;
    }

    // =================================================================

    /**
     * Un texte, par le point d'entrée unitaire des anciennes versions.
     *
     * @return array<int, float>|null
     */
    protected function unVecteur(string $texte): ?array
    {
        $reponse = $this->appeler('/api/embeddings', [
            'model' => $this->modele(),
            'prompt' => $texte,
        ]);

        return is_array($reponse) ? $this->flottants($reponse['embedding'] ?? null) : null;
    }

    /**
     * Le service répond-il, et le modèle y est-il téléchargé ?
     *
     * Les deux questions comptent, et la seconde plus que la première :
     * un Ollama qui tourne sans le modèle demandé accepterait l'appel,
     * téléchargerait plusieurs centaines de mégaoctets au premier
     * vecteur, et ferait passer une indexation pour un blocage. Mieux
     * vaut se déclarer indisponible et le dire.
     */
    protected function sonder(): bool
    {
        try {
            $reponse = Http::baseUrl($this->racine())
                ->timeout((int) config('pilotage.dense.ollama.delai_sonde', 2))
                ->acceptJson()
                ->get('/api/tags');
        } catch (ConnectionException) {
            return false;
        }

        if (! $reponse->successful()) {
            return false;
        }

        $modele = $this->modele();

        foreach ((array) $reponse->json('models', []) as $present) {
            $nom = (string) ($present['name'] ?? '');

            // Ollama nomme ses modèles « famille:étiquette ». Une
            // configuration qui dit « nomic-embed-text » doit
            // reconnaître « nomic-embed-text:latest », faute de quoi le
            // cas le plus courant serait déclaré absent.
            if ($nom === $modele || str_starts_with($nom, $modele.':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rend le corps décodé, l'entier 404, ou null.
     *
     * @param  array<string, mixed>  $charge
     * @return array<string, mixed>|int|null
     */
    protected function appeler(string $chemin, array $charge): array|int|null
    {
        try {
            $reponse = Http::baseUrl($this->racine())
                ->timeout((int) config('pilotage.dense.ollama.delai', 20))
                ->acceptJson()
                ->post($chemin, $charge);
        } catch (ConnectionException $panne) {
            // Le service a disparu entre la sonde et l'appel. On oublie
            // le mémo pour que la prochaine question resonde.
            $this->disponible = null;

            Log::warning('Ollama injoignable pendant un appel d\'embeddings.', [
                'chemin' => $chemin,
                'message' => $panne->getMessage(),
            ]);

            return null;
        }

        if ($reponse->status() === 404) {
            return 404;
        }

        if (! $reponse->successful()) {
            Log::warning('Ollama a refusé un appel d\'embeddings.', [
                'chemin' => $chemin,
                'statut' => $reponse->status(),
            ]);

            return null;
        }

        return (array) $reponse->json();
    }

    /**
     * @return array<int, float>|null
     */
    protected function flottants(mixed $brut): ?array
    {
        if (! is_array($brut) || $brut === []) {
            return null;
        }

        $vecteur = [];

        foreach ($brut as $valeur) {
            if (! is_int($valeur) && ! is_float($valeur)) {
                return null;
            }

            $vecteur[] = (float) $valeur;
        }

        return $vecteur;
    }

    protected function racine(): string
    {
        return rtrim((string) config('pilotage.dense.ollama.url', 'http://127.0.0.1:11434'), '/');
    }
}
