<?php

namespace Modules\Pilotage\Modele;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Pilotage\Contracts\ModeleDeLangage;
use Modules\Pilotage\Recherche\SegmentTrouve;

/**
 * Un rédacteur derrière le dialecte « chat completions » d'OpenAI.
 *
 * **Une seule classe pour tous les fournisseurs, et ce n'est pas de la
 * paresse.** xAI, Groq, Cerebras, Mistral, OpenRouter, GitHub Models et
 * Ollama exposent tous `POST /v1/chat/completions` avec le même corps :
 * un `model`, une liste de `messages`, une `temperature`. Écrire une
 * classe par fournisseur reviendrait à recopier six fois le même appel
 * pour ne varier qu'une URL. Ce qui distingue les fournisseurs — le
 * tarif, les limites de débit, la latence, le lieu où les données
 * transitent — ne se voit pas dans ce code et n'a rien à y faire : c'est
 * de la configuration.
 *
 * **Deux profils, une classe.** L'instance « local » vise Ollama sur la
 * machine ; l'instance « distant » vise un service en ligne. Elles ne
 * diffèrent que par ce qu'elles lisent dans
 * `pilotage.redaction.profils.*`, et `ResolveurDeModele` les essaie dans
 * l'ordre configuré.
 *
 * **Ce que ce client n'envoie jamais.** Les extraits proviennent de la
 * seule branche descriptive, qui n'a aucun accès aux indicateurs. Aucun
 * montant, aucun solde d'artisan, aucun mouvement de caisse ne peut se
 * trouver dans ce qui part : la frontière posée par le `Routeur` en
 * amont fait le tri avant que ce code ne soit atteint. Ce qui sort, ce
 * sont des désignations de produits et des noms de corps de métier, déjà
 * destinés au portail public — et avec le profil local, rien ne sort du
 * tout.
 *
 * **Ce client ne lève jamais.** Clé absente, service en panne, délai
 * dépassé, quota épuisé, réponse vide : chacun de ces cas rend `null`, et
 * l'appelant retombe sur la composition mécanique. Une réponse moins bien
 * tournée vaut mieux qu'une page d'erreur, et le jour de la soutenance il
 * n'y aura peut-être pas de connexion.
 */
class ClientCompatibleOpenAI implements ModeleDeLangage
{
    /**
     * Mémo de disponibilité, valable pour la durée du processus.
     *
     * N'est renseigné que pour les profils qui se sondent — voir
     * `estDisponible()`.
     */
    protected ?bool $disponible = null;

    /**
     * @param  string  $profil  clé dans `pilotage.redaction.profils`
     */
    public function __construct(protected string $profil) {}

    public function nom(): string
    {
        return 'Rédaction — '.$this->reglage('libelle', $this->profil).' ('.$this->modele().')';
    }

    public function modele(): string
    {
        return (string) $this->reglage('modele', '');
    }

    /**
     * Disponible, et à quel prix le savoir.
     *
     * **Deux régimes, parce qu'il y a deux coûts.** Sonder un service en
     * ligne ajouterait un aller-retour réseau à chaque question, sur le
     * chemin même où l'utilisateur attend : on s'en abstient, une clé
     * présente suffit, et une panne se découvrira à l'appel, où le repli
     * est déjà écrit. Sonder un service local coûte une milliseconde, et
     * le cas « Ollama n'est pas lancé » est bien plus fréquent qu'une
     * panne de fournisseur : là, mieux vaut demander que supposer, sans
     * quoi chaque question descriptive attendrait le budget entier avant
     * de retomber sur la liste.
     *
     * Le choix est déclaré en configuration (`sonder`) plutôt que déduit
     * de l'URL : une règle qui devine à partir d'un nom d'hôte se
     * tromperait le jour où le service local vit ailleurs.
     */
    public function estDisponible(): bool
    {
        if (! (bool) config('pilotage.redaction.active', true)) {
            return false;
        }

        if ($this->cle() === '' || $this->modele() === '') {
            return false;
        }

        if (! (bool) $this->reglage('sonder', false)) {
            return true;
        }

        return $this->disponible ??= $this->sonder();
    }

    /**
     * @param  Collection<int, SegmentTrouve>  $extraits
     */
    public function redigerDepuisExtraits(string $question, Collection $extraits): ?string
    {
        if (! $this->estDisponible() || $extraits->isEmpty()) {
            return null;
        }

        return $this->appeler($this->consigne(), $this->matiere($question, $extraits));
    }

    /**
     * Accueille une saisie qui n'est pas une question sur le village.
     *
     * **Rien du village ne part avec l'appel.** La matière est la saisie
     * seule. Le modèle ne peut donc pas rapporter un fait qu'il n'a pas :
     * c'est la même contrainte que la rédaction, poussée au bout.
     */
    public function accueillir(string $saisie): ?string
    {
        if (! $this->estDisponible() || trim($saisie) === '') {
            return null;
        }

        return $this->appeler($this->consigneDAccueil(), trim($saisie));
    }

    /**
     * Rend une question de suite autonome, à partir de l'historique.
     *
     * Ce que le modèle rend est une **question**. Elle repart ensuite
     * dans le routeur, l'extracteur et `RapportService` : aucun chiffre
     * ne sort d'ici.
     *
     * @param  array<int, array{question: string, reponse: string}>  $historique
     */
    public function reformuler(string $saisie, array $historique): ?string
    {
        if (! $this->estDisponible() || $historique === [] || trim($saisie) === '') {
            return null;
        }

        $fil = [];

        foreach ($historique as $tour) {
            $fil[] = 'Q : '.($tour['question'] ?? '');
            $fil[] = 'R : '.($tour['reponse'] ?? '');
        }

        $matiere = implode("\n", $fil)."\n\nNouvelle saisie : ".trim($saisie);

        $reformulee = $this->appeler($this->consigneDeReformulation(), $matiere);

        if ($reformulee === null) {
            return null;
        }

        // Un modèle bavard répond parfois « Voici la question : ... ».
        // On ne garde qu'une ligne, et jamais plus long qu'une question.
        $reformulee = trim(strtok($reformulee, "\n"));

        return ($reformulee === '' || mb_strlen($reformulee) > 200) ? null : $reformulee;
    }

    // =================================================================

    /**
     * L'unique voie d'appel du service.
     *
     * Les trois opérations ne diffèrent que par leur consigne et leur
     * matière. Une seule voie signifie un seul endroit où se posent le
     * budget de temps, la capture de panne et la lecture de la réponse —
     * donc un repli qui ne peut pas être correct pour la rédaction et
     * oublié pour l'accueil.
     */
    protected function appeler(string $consigne, string $matiere): ?string
    {
        try {
            $reponse = Http::baseUrl($this->racine())
                ->withToken($this->cle())
                ->timeout((int) config('pilotage.redaction.budget', 8))
                ->acceptJson()
                ->post('/v1/chat/completions', [
                    'model' => $this->modele(),
                    // Une rédaction n'est pas une création : on veut la
                    // même phrase pour la même matière, d'une
                    // démonstration à l'autre. Une température non nulle
                    // rendrait la soutenance irreproductible.
                    'temperature' => 0,
                    'messages' => [
                        ['role' => 'system', 'content' => $consigne],
                        ['role' => 'user', 'content' => $matiere],
                    ],
                ]);
        } catch (ConnectionException $panne) {
            // Le service a disparu entre la sonde et l'appel. On oublie
            // le mémo pour que la prochaine question resonde.
            $this->disponible = null;

            Log::warning('Rédacteur injoignable.', [
                'profil' => $this->profil,
                'message' => $panne->getMessage(),
            ]);

            return null;
        }

        if (! $reponse->successful()) {
            Log::warning('Rédacteur en refus.', [
                'profil' => $this->profil,
                'statut' => $reponse->status(),
            ]);

            return null;
        }

        $texte = trim((string) $reponse->json('choices.0.message.content', ''));

        // Un texte vide n'est pas une rédaction : le rendre tel quel
        // effacerait la réponse au lieu de la tourner autrement.
        return $texte === '' ? null : $texte;
    }

    // =================================================================

    /**
     * Le service répond-il, et le modèle demandé y est-il ?
     *
     * Les deux comptent, et la seconde plus que la première : un Ollama
     * qui tourne sans le modèle voulu accepterait l'appel puis
     * téléchargerait plusieurs gigaoctets à la première question, ce qui
     * ferait passer une rédaction pour un blocage.
     */
    protected function sonder(): bool
    {
        try {
            $reponse = Http::baseUrl($this->racine())
                ->withToken($this->cle())
                ->timeout((int) $this->reglage('delai_sonde', 2))
                ->acceptJson()
                ->get('/v1/models');
        } catch (ConnectionException) {
            return false;
        }

        if (! $reponse->successful()) {
            return false;
        }

        $modele = $this->modele();

        foreach ((array) $reponse->json('data', []) as $present) {
            $identifiant = (string) ($present['id'] ?? '');

            // Ollama nomme ses modèles « famille:étiquette ». Une
            // configuration qui dit « qwen2.5:3b » doit reconnaître la
            // forme exacte, et « mistral » doit reconnaître
            // « mistral:latest ».
            if ($identifiant === $modele || str_starts_with($identifiant, $modele.':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * La consigne, en français, et volontairement restrictive.
     *
     * **Elle n'est pas le garde-fou.** Une consigne se contourne, se
     * dilue sur un long contexte, et ne se démontre pas devant un jury :
     * c'est `GardeDesChiffres` qui garantit, mécaniquement, qu'aucun
     * chiffre absent des extraits ne franchit la sortie. La consigne sert
     * à ce que le cas nominal se passe bien ; le contrôle sert à ce que
     * le cas anormal ne passe pas. La distinction compte d'autant plus
     * ici qu'un petit modèle local suit les consignes moins fidèlement
     * qu'un grand modèle en ligne — et que le contrôle, lui, ne varie
     * pas avec la taille du modèle.
     */
    protected function consigne(): string
    {
        return <<<'TEXTE'
            Tu rédiges la réponse d'un système d'information de village artisanal, en français.

            Règles :
            - N'utilise QUE les extraits fournis. N'ajoute aucune connaissance extérieure.
            - N'invente aucun chiffre. Si un nombre ne figure pas dans un extrait, ne l'écris pas.
            - Ne calcule rien, ne totalise rien, n'estime rien.
            - Deux à quatre phrases, ton neutre et factuel, sans formule de politesse.
            - Si les extraits ne répondent pas à la question, dis-le simplement.
            TEXTE;
    }

    /**
     * La consigne d'accueil.
     *
     * Elle décrit le rôle et interdit d'énoncer quoi que ce soit sur le
     * village. **La consigne n'est pas la garantie** : l'appelant rejette
     * toute sortie portant un chiffre, et ce rejet-là ne dépend d'aucune
     * obéissance du modèle. La consigne sert à ce que le cas nominal soit
     * agréable ; le contrôle sert à ce que le cas anormal ne passe pas.
     */
    protected function consigneDAccueil(): string
    {
        return <<<'TEXTE'
            Tu es l'assistant du Village Artisanal Régional de Bafoussam (VARBAF), en français.

            Le message reçu n'est PAS une question sur le village : c'est une salutation,
            un remerciement, ou un propos hors sujet.

            Règles :
            - Réponds en une ou deux phrases, courtoisement.
            - Dis ce que tu sais traiter : les artisans, les produits, les boutiques,
              les locations et les chiffres du village.
            - N'énonce AUCUN fait sur le village : aucun nom, aucune quantité, aucun montant.
            - N'écris aucun chiffre, sous aucune forme.
            - Pas de liste, pas de titre : du texte suivi.
            TEXTE;
    }

    /**
     * La consigne de reformulation.
     *
     * Le modèle rend une question, jamais une réponse — et si la saisie
     * se suffit déjà, il la rend inchangée. C'est ce qui permet à
     * l'appelant de savoir qu'il n'y a rien eu à reformuler : la sortie
     * est égale à l'entrée.
     */
    protected function consigneDeReformulation(): string
    {
        return <<<'TEXTE'
            Tu reformules la dernière saisie d'un utilisateur en une question autonome, en français.

            Règles :
            - Rends UNIQUEMENT la question reformulée, sur une seule ligne, sans préambule.
            - Remplace les sous-entendus par ce à quoi ils renvoient dans l'échange précédent.
            - Ne réponds PAS à la question. Ne calcule rien. N'ajoute aucun fait.
            - N'introduis aucun chiffre qui ne soit déjà dans la saisie ou dans la question précédente.
            - Si la saisie est déjà une question autonome, rends-la mot pour mot.
            TEXTE;
    }

    /**
     * @param  Collection<int, SegmentTrouve>  $extraits
     */
    protected function matiere(string $question, Collection $extraits): string
    {
        $lignes = $extraits
            ->map(fn (SegmentTrouve $segment): string => '- '.$segment->titre.' : '.$segment->extrait)
            ->implode("\n");

        return "Question : {$question}\n\nExtraits du corpus :\n{$lignes}";
    }

    protected function cle(): string
    {
        return trim((string) $this->reglage('cle', ''));
    }

    protected function racine(): string
    {
        return rtrim((string) $this->reglage('url', ''), '/');
    }

    protected function reglage(string $nom, mixed $defaut = null): mixed
    {
        return config("pilotage.redaction.profils.{$this->profil}.{$nom}", $defaut);
    }
}
