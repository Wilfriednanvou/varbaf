# Audit du module Trésorerie

**Date :** 22 août 2026 — **Périmètre :** `Modules/Tresorerie/` et les tests qui le couvrent.
**Référentiels appliqués :** `docs/specification-tresorerie.md` (RG-01 à RG-27), `CLAUDE.md`, `docs/dette-technique.md`.

Les écarts déjà consignés comme dette assumée (DT-08 sur les Policies, DT-12 sur RG-23) ne sont pas recomptés ici.

---

## 1. Ce qui tient

Avant les défauts, ce qui est solide et défendable tel quel :

- **RG-06 tenu partout.** Aucune écriture directe dans `mouvements_caisse`. Même le `CreateAction` de `MouvementCaisseResource` passe par `->using()` pour déléguer à `ServiceTresorerie::enregistrer()` — c'est le point que la plupart des implémentations ratent.
- **Les règles vivent dans les modèles, pas dans les écrans.** Immuabilité (`updating`/`deleting` sur `MouvementCaisse` et `ArreteCaisse`), RG-01 dans le `creating` de `SectionCaisse`, RG-26 dans le `creating` de `ArreteCaisse`. Une commande console ne les contourne pas.
- **Double barrière sur RG-01 :** crochet applicatif *et* index unique partiel PostgreSQL (`sections_caisse_une_ouverte_par_caisse`). C'est la bonne façon de le faire et ça s'explique bien en soutenance.
- **Contre-passation correcte :** le mouvement d'origine n'est jamais touché, la double contre-passation et la contre-passation d'une contre-passation sont refusées, et les trois cas sont testés.
- **Défense en profondeur sur la section clôturée** via le trait `VerifieSectionOuverte`, testée par appel direct au composant Livewire (`SessionCaisseTest`) et pas seulement par la visibilité du bouton.

---

## 2. Bloquant

### B1 — « Ouvrir une section » lève une erreur SQL sur les deux écrans

`exercices` n'a pas de colonne `actif`. La colonne est `en_cours` (`Modules/Socle/database/migrations/2026_08_20_100100_create_exercices_table.php:17`). Or deux endroits requêtent `actif` :

| Fichier | Ligne | Code |
|---|---|---|
| `app/Filament/Pages/ManageCaisseSession.php` | 245 | `Exercice::query()->where('actif', true)->value('id')` |
| `app/Filament/Resources/SectionCaisseResource.php` | 68 | `Exercice::query()->where('actif', true)->value('id')` |

Résultat : `SQLSTATE[42703] : column "actif" does not exist`. Dans `SectionCaisseResource` le défaut du champ `Hidden` est évalué à la construction du formulaire — la modale plante avant même d'être affichée. Dans `ManageCaisseSession` c'est la soumission qui plante. **Ouvrir une section de caisse est impossible par l'interface, sur les deux chemins.**

Pourquoi aucun test ne l'a vu : `SessionCaisseTest::test_aucune_section_ouverte_propose_l_ouverture_plutot_qu_un_ecran_vide` vérifie `assertActionVisible('ouvrir_section')` — la visibilité du bouton, jamais son exécution. Les sections utilisées par les autres tests viennent du seeder, qui lui utilise correctement `en_cours`.

**Correctif.** Le Socle expose déjà le point d'entrée prévu pour ça, avec un docblock explicite (« aucun [module] ne requête directement la table des exercices ») :

```php
$exercice = Exercice::courant();

if (! $exercice) {
    Notification::make()
        ->title('Aucun exercice en cours')
        ->body("Ouvrez un exercice avant d'ouvrir une section de caisse.")
        ->danger()
        ->send();

    return;
}
```

`exercice_id` est `NOT NULL` : sans la garde, l'absence d'exercice en cours redonnerait une erreur SQL brute au lieu d'un message. Même remarque pour `village_id` : `ManageCaisseSession.php:244` retombe sur `VillageArtisanal::query()->value('id')` alors que `$this->caisse->village_id` est la valeur juste et toujours disponible.

À ajouter dans la foulée : un test qui **appelle** l'action (`->callAction('ouvrir_section', [...])`) et assert qu'une `SectionCaisse` existe. C'est le test manquant qui aurait attrapé ça.

---

## 3. Majeur

### M2 — `sectionId` est modifiable par le client (Livewire)

`VentesCaisseTable`, `MouvementsCaisseTable`, `BrouillardCaisseTable` et `ArretesCaisseTable` déclarent tous `public int $sectionId;` sans `#[Locked]`. Une propriété publique Livewire non verrouillée est réinscriptible depuis le navigateur : un compte porteur de `saisir_mouvement_caisse` peut réémettre la requête avec l'identifiant d'une section ouverte d'**une autre caisse** et y écrire. La garde `refuserSiSectionFermee()` ne protège pas de ça — elle vérifie que la section est ouverte, pas que c'est la bonne.

Deux méthodes aggravent le cas en acceptant un identifiant sans le rattacher à la section :

- `MouvementsCaisseTable::contrepasserMouvement()` — `MouvementCaisse::find($mouvementId)` sans `where('section_id', $this->sectionId)`
- `VentesCaisseTable::annulerVente()` — `Vente::find($venteId)` sans `where('section_caisse_id', $this->sectionId)`

**Correctif :** `#[Locked] public int $sectionId;` sur les quatre composants, plus le filtrage explicite sur les deux `find()`. C'est un correctif de quelques lignes et un argument fort en soutenance : il montre qu'on distingue « l'écran n'affiche pas le bouton » de « le serveur refuse l'opération ».

### M3 — RG-27 ne verrouille que le jour arrêté, pas les jours antérieurs

`MouvementCaisse::booted()` (ligne 87) cherche un arrêté dont `date_arrete` **égale** la date visée :

```php
$journeeArretee = $caisseId && ArreteCaisse::query()
    ->where('caisse_id', $caisseId)
    ->whereDate('date_arrete', $dateCible->toDateString())
    ->exists();
```

Conséquence : si le 20 a été arrêté et que le 19 ne l'a pas été, un mouvement daté du 19 passe sans report. Il entre dans le périmètre du `soldeTheorique` du 20 (`whereDate('date_operation', '<=', $dateArrete)`), qui devient donc rétroactivement faux, alors que l'arrêté du 20 est immuable et affiche toujours son ancien chiffre. Un écart de caisse peut ainsi apparaître *après* le contrôle censé le constater — exactement le trou que §7.7 de la spécification voulait fermer.

**Correctif :** comparer au dernier jour arrêté, pas au jour visé.

```php
->whereDate('date_arrete', '>=', $dateCible->toDateString())
```

Test à ajouter : arrêter le jour J, écrire un mouvement daté de J−2, vérifier `date_origine` renseignée et `date_operation` reportée à aujourd'hui.

### M4 — RG-07 n'est pas implémenté

RG-07 : « La clôture d'une section n'est possible que si tous ses mouvements sont validés et si toutes ses journées ont été arrêtées. » `SectionCaisse::cloturer()` ne vérifie que `estOuverte()`. On peut donc clôturer un exercice entier sans qu'aucune journée ait été arrêtée — ce qui vide de sa substance le mécanisme de contrôle interne que l'arrêté journalier était censé apporter.

**Correctif** dans `cloturer()`, avant le `forceFill` :

```php
$joursNonArretes = $this->mouvements()
    ->selectRaw('DISTINCT date(date_operation) as jour')
    ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
        ->from('arretes_caisse')
        ->whereColumn('arretes_caisse.date_arrete', DB::raw('date(mouvements_caisse.date_operation)'))
        ->where('arretes_caisse.caisse_id', $this->caisse_id))
    ->pluck('jour');

if ($joursNonArretes->isNotEmpty()) {
    throw SectionCaisseException::journeesNonArretees($joursNonArretes);
}
```

Si l'implémentation dépasse le budget de temps, l'alternative honnête est de l'inscrire en DT-13 avec la justification — mais ne pas la laisser dans l'angle mort : la spécification l'énonce et le jury peut la lire.

### M5 — RG-02 n'est pas garanti, seulement suggéré

RG-02 : « Le solde d'ouverture d'une section est égal au solde de clôture de la section précédente. »

- Dans `ManageCaisseSession`, la valeur est un `->default()` — pré-remplie, donc **modifiable par l'utilisateur avant validation**.
- Dans `SectionCaisseResource`, le champ vaut simplement `->default(0)` : aucun lien avec la section précédente.

Une règle qui se laisse écraser dans le formulaire n'est pas une règle. C'est aussi la porte d'entrée la plus directe pour fabriquer du solde de caisse.

**Correctif :** calculer `solde_ouverture` dans le crochet `creating` de `SectionCaisse` à partir de la dernière section clôturée de la même caisse, et passer le champ des deux formulaires en lecture seule (`->disabled()->dehydrated(false)`), purement informatif. Même raisonnement que A-01 dans `dette-technique.md` : dérivé sur le plan métier, affiché sur le plan technique.

### M6 — Le verrou de numérotation ne verrouille rien sur une section vide, et charge toute la section

`ServiceTresorerie::enregistrer()`, ligne 126 :

```php
MouvementCaisse::query()
    ->where('section_id', $section->getKey())
    ->lockForUpdate()
    ->get();
```

Deux problèmes distincts :

1. **`SELECT … FOR UPDATE` ne verrouille que les lignes retournées.** Sur une section sans mouvement, il n'y a rien à verrouiller : deux saisies simultanées obtiennent toutes deux `numero_ordre = 1`. L'unicité `(section_id, numero_ordre)` rattrape le cas — l'une échoue et la boucle réessaie — donc la correction est sauve, mais par la contrainte, pas par le verrou. Autant que le verrou fasse son travail.
2. **Chaque écriture charge en mémoire tous les mouvements de la section.** §7.6 de la spécification prévoit « plusieurs milliers de lignes » par section. Chaque vente hydrate donc quelques milliers de modèles Eloquent pour n'en tirer aucune donnée.

**Correctif :** verrouiller la ligne de la section, qui existe toujours et qui est l'objet réellement sérialisé :

```php
SectionCaisse::query()->whereKey($section->getKey())->lockForUpdate()->first();
```

Le `max('numero_ordre')` et le calcul du solde par agrégats restent inchangés — ils sont déjà en O(1) mémoire.

Point annexe : le `catch (\Illuminate\Database\QueryException $e)` réessaie **toute** erreur SQL trois fois, y compris une violation de clé étrangère ou une contrainte `NOT NULL` qui ne réussiront jamais. Restreindre à la violation d'unicité (`$e->getCode() === '23505'`) rend l'échec immédiat et lisible.

---

## 4. Moyen

### Y7 — `ServiceTresorerie::$sectionCible` est un état statique global

Le service est un singleton et `$sectionCible` est une propriété **statique**. Aujourd'hui c'est correct parce que `VentesCaisseTable` remet à `null` dans un bloc `finally`. Mais la garantie repose entièrement sur la discipline de l'appelant : un appel futur qui l'oublie fait fuiter une section vers la requête suivante, définitivement sous Octane ou dans un worker de file.

Deux issues, par ordre de préférence :

1. Passer la section en paramètre explicite à `ServiceVente::enregistrer()` — l'état disparaît.
2. À défaut, encapsuler le `try/finally` dans le service : `ServiceTresorerie::pourSection($section, fn () => …)`, pour qu'aucun appelant ne puisse l'oublier.

### Y8 — `ecart` est `fillable` alors que le modèle affirme qu'il est déduit

Le docblock de `ArreteCaisse` dit : « `ecart` est déduit des deux — jamais saisi séparément ». Il est pourtant dans `$fillable`, et c'est le service qui le calcule et le passe. Un `ArreteCaisse::create(['ecart' => 0, 'solde_physique' => 50000, 'solde_theorique' => 0])` passe la garde RG-26 sans commentaire. La règle est donc appliquée par le service, pas par le modèle, contrairement à ce que le commentaire annonce.

**Correctif :** recalculer dans le `creating`, avant la garde, et retirer `ecart` de `$fillable`.

```php
$arrete->ecart = $arrete->solde_physique - $arrete->solde_theorique;

if ($arrete->ecart !== 0 && blank($arrete->commentaire_ecart)) {
    throw ArreteCaisseException::ecartNonJustifie();
}
```

### Y9 — Le commentaire de justification est exigé dès l'ouverture de la modale d'arrêté

`ArretesCaisseTable.php:205` : `->required(fn (Get $get) => $this->ecart(...) !== 0)`. Tant que `solde_physique` est vide, `ecart()` renvoie `null`, et `null !== 0` vaut `true` : le champ s'affiche obligatoire avant toute saisie, puis se libère quand le caissier tape un montant qui tombe juste. C'est déroutant à l'usage et ça se voit en démonstration.

```php
->required(function (Get $get): bool {
    $ecart = $this->ecart($get('date_arrete'), $get('solde_physique'));

    return $ecart !== null && $ecart !== 0;
})
```

### Y10 — Le solde artisan charge tout l'historique en mémoire

`ServiceCompteArtisan::totalVendu()` appelle `ventesValidees()` — un `->get()` de toutes les ventes de l'artisan — pour en faire la somme en PHP. Et `SituationArtisan` appelle `totalVendu()` puis `soldeDu()`, qui rappelle `totalVendu()` : deux chargements complets par affichage.

```php
public function totalVendu(Artisan $artisan): int
{
    return (int) Vente::query()
        ->where('artisan_id', $artisan->getKey())
        ->where('etat', EtatVente::VALIDEE->value)
        ->sum('part_artisan');
}
```

`ventesValidees()` reste utile pour l'écran, mais devrait rendre une requête paginable plutôt qu'une collection complète.

### Y11 — L'arrêté est unique par caisse, le solde théorique est calculé par section

`arretes_caisse` porte `unique(caisse_id, date_arrete)`, mais `ServiceArreteCaisse::soldeTheorique()` ne cumule que les mouvements de **la section passée**. Si une section est clôturée et une autre ouverte le même jour, l'arrêté de ce jour ne voit qu'une partie des flux de la caisse. Cas rare — la section couvre un exercice — mais l'incohérence est réelle : soit le cumul passe par `caisse_id`, soit l'unicité passe par `(section_id, date_arrete)`. Un commentaire assumant le choix suffit si le temps manque.

### Y12 — Typage des montants : `float` en surface, `int` en profondeur

RG-12 bis impose des entiers. Pourtant `enregistrer(float $montant, …)` accepte un flottant et l'arrondit silencieusement (`1000.6` devient `1001`), et `ServiceTresorerie::solde()` renvoie `float` là où `SectionCaisse::soldeCourant()` renvoie `int` — deux méthodes qui calculent la même chose, dans deux classes, avec deux types de retour.

Passer la signature à `int` laisse PHP refuser lui-même ce que RG-12 bis interdit, et supprime la question « pourquoi arrondissez-vous ? » en soutenance. Et faire de `ServiceTresorerie::solde()` un simple relais vers `SectionCaisse::soldeCourant()`.

### Y13 — `origine_type` stocke un nom court, pas une relation

`class_basename($origine)` produit `'Vente'`, et `contrepasserEncaissementDeVente()` compare à la chaîne `'Vente'` en dur. Deux modèles homonymes dans deux modules deviendraient indiscernables. Sans risque à l'échelle actuelle, mais c'est une question probable du jury : soit passer à un `morphTo` avec `Relation::enforceMorphMap()`, soit assumer explicitement le choix dans `dette-technique.md`.

### Y14 — Les notifications d'erreur exposent le message brut

Les quatre `catch (\Exception $e)` des composants Livewire affichent `$e->getMessage()` à l'utilisateur. Pour une exception métier c'est exactement ce qu'on veut. Pour une `QueryException`, c'est du SQL dans une notification — et c'est précisément ce qui se produirait avec B1. N'attraper que les exceptions métier (`SectionCaisseException`, `ArreteCaisseException`, `MouvementCaisseImmuableException`, `InvalidArgumentException`) et laisser le reste remonter au gestionnaire d'erreurs.

---

## 5. À expliquer en soutenance (pas des défauts)

**Le code est plus strict que RG-05.** RG-05 et la règle 4 de `CLAUDE.md` autorisent la correction directe d'un mouvement tant que sa journée n'est pas arrêtée. `MouvementCaisse` refuse toute modification, sans condition : la contre-passation est la seule voie, en toute circonstance. C'est plus sûr et c'est cohérent avec §7.7 (« sinon, la contre-passation reste la seule voie »). Mais le code et la spécification ne disent pas la même chose. **Aligne la spécification sur le code**, comme tu l'as fait pour l'audit des suppressions (« Convention amendée » dans `dette-technique.md`) : un jury qui compare les deux documents relèvera l'écart, et il vaut mieux qu'il y trouve une décision consignée qu'une divergence.

**`solde_apres` est un solde d'ordre de saisie, pas un solde à la date.** Un mouvement antidaté reçoit `numero_ordre = max + 1` et le solde courant du moment. Le brouillard trie par `numero_ordre` — cohérent. L'arrêté trie par date — donc son solde théorique ne correspond pas au `solde_apres` de la dernière ligne du jour. Ce n'est pas un bogue si c'est assumé, mais c'est une question à laquelle il faut pouvoir répondre en une phrase.

**Écriture croisée vers `ventes`.** `enregistrerEncaissementDeVente()` fait `$vente->newQuery()->where('id', …)->update(['section_caisse_id' => …])`, ce qui contourne les crochets du modèle `Vente` et le journal d'audit, et laisse l'instance en mémoire périmée. Le sens de dépendance est légal (Trésorerie → Commerce, descendant), et le contournement est probablement voulu — mais il mérite un commentaire dans le code disant pourquoi.

---

## 6. Couverture de test

Le module est bien couvert sur son cœur : `MouvementCaisseTest` éprouve RG-01, RG-03, RG-04, RG-05, RG-07 (l'irréversibilité), le port `JournalDeCaisse` et la contre-passation ; `ArreteCaisseTest` couvre RG-25 à RG-27 ; `SessionCaisseTest` couvre la lecture seule par appel direct au composant. C'est du bon travail.

Les angles morts, par ordre de valeur :

| Manque | Ce qu'il aurait attrapé |
|---|---|
| Exécution de l'action `ouvrir_section` | **B1** |
| RG-02 : solde d'ouverture hérité de la clôture précédente | M5 |
| RG-07 : refus de clôture si des journées ne sont pas arrêtées | M4 |
| RG-27 avec une date **antérieure** au dernier jour arrêté | M3 |
| Écriture avec un `sectionId` d'une autre caisse | M2 |
| `SituationArtisan` et `BrouillardCaisseTable` | Aucun test — pages non éprouvées |

---

## 7. Ordre de traitement suggéré

Le gel du code est le 3 septembre. Priorité décroissante, un objectif vérifiable par commit :

1. **B1** — l'écran est cassé, tout le reste attend. Correctif + test qui appelle l'action.
2. **M2** — `#[Locked]` et filtrage sur les deux `find()`. Une demi-heure, argument fort en soutenance.
3. **M3** — une ligne (`>=`), plus un test.
4. **M5** puis **M4** — RG-02 puis RG-07 ; si RG-07 déborde, DT-13 assumée.
5. **M6** — verrou sur la section ; corrige un défaut de performance que la spécification anticipe explicitement.
6. **Y7 à Y14** — au fil de l'eau ; Y8 et Y9 sont chacun de quelques lignes.
