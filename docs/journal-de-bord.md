# Journal de bord

Notes du soir, tenues au titre de la règle 3 du rétroplanning : *une demi-heure sur ce qui a été fait et sur les difficultés rencontrées, parce que c'est la matière du chapitre réalisation et qu'elle est irrécupérable a posteriori.*

Chaque entrée suit la même forme — ce qui a été fait, ce qui a résisté, ce qui a été décidé, ce qu'on en retient. La troisième et la quatrième colonnes sont les plus utiles au rapport : un chapitre réalisation qui n'énumère que des fonctionnalités livrées se lit comme une brochure ; celui qui montre ce qui a résisté et comment se lit comme un travail d'ingénieur.

---

## Mercredi 26 août 2026

### Ce qui a été fait

**Le jeu de données a été entièrement refait.** L'ancien registre, transcrit à la main le 20 août, a été supprimé — rapports d'import compris — et remplacé par la reprise de trois documents réels du village : l'état des ventes au 25 février 2026, l'inventaire du stock produits, et la liste des produits artisanaux, qui porte aussi la liste des occupants. Le taux de commission, jusque-là ouvert, est confirmé à **10 %**.

Le registre normalisé compte **603 lignes**, dont **602 ventes importées**. Les chiffres ont été vérifiés un à un en console :

| Grandeur | Valeur | Ce qu'elle vérifie |
|---|---|---|
| Chiffre d'affaires | 2 021 350 F | Somme des lignes du registre, au franc |
| Commission | 202 135 F | Exactement 10 % — RG-12 bis, arrondi à l'unité |
| Part artisan | 1 819 215 F | Commission + part = CA, sans reste |
| Entrées en caisse | 2 021 350 F | RG-13 : la vente entre en caisse pour son montant entier |
| Mouvements de stock | 1 204 | 602 × 2 — le stock revient à zéro, dépôt puis sortie |
| Attributions | 24, pour 115 000 F de redevance | Aucune sans montant |

**Le sous-sol et l'espace vert sont entrés au parc locatif.** L'état de recouvrement des redevances 2026 nomme trois espaces loués hors du bâtiment de vente : G0201 au sous-sol, occupé par la CNTC pour 60 000 F par mois — la redevance la plus élevée de tout le parc —, G0202 pour SCOOPS AAMRO à 10 000 F, et EV0101 sur l'espace vert à 5 000 F. Soit 75 000 F de redevance mensuelle que le système ne voyait pas et trois attributions qu'il aurait refusé d'enregistrer. La table `boutiques` devient celle des contenants et porte une colonne `nature` — boutique, sous-sol, espace vert — de sorte que le taux d'occupation présenté à la tutelle se calcule toujours sur les seuls locaux de vente, sans que l'autre chiffre disparaisse.

**La dette DT-01 est fermée.** `Exercice::cloturer()` ne vérifiait ni les sections de caisse ouvertes ni les campagnes de reversement non validées : on pouvait refermer une période en laissant de l'argent en caisse et des artisans impayés. Elle était restée ouverte pour une raison de fond — le Socle est le module 1, la Trésorerie le module 4, et la règle de dépendance descendante interdit au premier de connaître le second. La solution retourne le sens de la connaissance : le Socle expose un point d'accroche (`VerrouDeCloture`) et un registre, la Trésorerie vient s'y déclarer depuis son propre fournisseur. Le Socle ne référence rien du module 4, et le registre accueillerait demain un verrou du Commerce sans changer d'une ligne. Neuf tests, dont un qui n'éprouve pas la règle mais **le câblage** : si le fournisseur du module 4 cesse d'être chargé, la clôture ne protège plus rien, et il faut que quelque chose le dise.

**La nature d'un contenant est devenue éditable**, filtrable et lisible en liste. Le seeder seul savait déclarer un local hors vente ; la coordination ne pouvait pas.

### Ce qui a résisté

**Un bug de production qui cassait toutes les pages du panneau.** La migration standard de Laravel pose `notifications.data` en `text`. La cloche de Filament compte les notifications non lues avec `data->>'format'`, que PostgreSQL refuse sur du texte. Toute page du panneau plantait dans le navigateur — et rien ne le signalait, parce que les tests montent les composants Livewire directement : seuls deux tests font une vraie requête HTTP, donc rendent la barre supérieure, et ils vivent dans un module sans rapport avec les notifications. Le défaut n'est apparu qu'en lançant la suite **complète**, non filtrée.

**Une collision de codes d'espaces locatifs.** `EspaceLocatif::genererCode()` ne gardait que les chiffres du numéro de son contenant : `SS01` et `EV01` se réduisaient tous deux à `B01` et fabriquaient les codes de la boutique B01. Le préfixe est désormais alphanumérique, et le rang se calcule sur les seuls codes qui suivent la règle — de sorte qu'un code relevé sur le terrain, `G0201` sous `SS01`, survit à la dérivation au lieu de fausser la numérotation.

**Un défaut de conception dans l'import, que j'avais introduit moi-même.** Les attributions d'espaces étaient créées *avant* la transaction de la vente. Une attribution refusée pour chevauchement levait donc une exception qui remontait et **tuait la vente** — une donnée réelle perdue à cause d'une donnée annexe douteuse. Le refus est maintenant capté, compté sous une anomalie propre (`OCCUPATIONS_REFUSEES`), et la vente passe. Symptôme initial : un test annonçait trois lignes non importées là où deux étaient attendues.

**L'import fabriquait des espaces locatifs à la volée** — 327 au premier passage, tous fictifs, parce que `resoudreEspace()` créait ce qu'il ne trouvait pas. Il ne crée plus rien : il signale `ESPACE_ABSENT` quand la ligne n'en nomme aucun et `ESPACE_INTROUVABLE` quand le code n'est pas au parc. Le parc réel fait autorité.

**Le rapport d'import annonçait « 100 % des lignes signalées »**, ce qui n'apprend rien. Il ventile désormais les anomalies par nature, en regroupant les rejets techniques sous un seul libellé.

**Le rattachement des artisans à leurs espaces.** Le registre nomme les vendeurs comme on les appelle au village — prénom seul, surnom, enseigne. Ma première approche comparait les patronymes ; elle est tombée sur « Crousti Delice NGASSAM », où NGASSAM est le nom de l'artisan et non celui de la boutique. La méthode retenue croise le produit vendu et le corps de métier de l'occupant, document par document : un même nom présent dans les trois fichiers et sur le même local est très probablement une seule personne. La couverture passe de 70 % à 77 %, et chaque arbitrage est consigné avec son motif dans `docs/donnees/correspondance-artisans.csv` — un rattachement sans motif écrit est un rattachement qu'on ne saura pas défendre en soutenance.

### Ce qui a été décidé

- **`quantite` et `date` restent vides dans le registre** là où la source ne les donne pas. Ma première version écrivait `quantite = 1` : c'était faire passer une approximation pour une donnée relevée. Le lecteur déduit et signale, ce qui laisse la trace visible.
- **Les catégories de produits ne seront pas devinées.** 285 produits sans catégorie, aucune source ne disant à laquelle « biscuit de manioc » appartient. Une catégorie inventée ne se distinguerait plus, dans six mois, d'une catégorie relevée.
- **`Boutique` garde son nom** alors qu'il désigne désormais un contenant qui peut ne pas être une boutique. Renommer le modèle, la table et leurs références à huit jours du gel coûterait plus que la gêne de lecture. Consigné plutôt que corrigé.
- **Deux points partent en question à la coordination** plutôt que d'être tranchés seul : Mme Justina, 190 000 F de ventes sans aucun espace à son nom, et « Bijoux en perles », qui n'entre dans aucun des quatorze secteurs officiels.

### Ce que j'en retiens

**La documentation vieillit dans les deux sens.** `docs/dette-technique.md` annonçait l'arbitrage A-03 comme restant à implémenter : il l'était depuis plusieurs jours. J'ai failli réécrire du code existant. Un document qui décrit une dette éteinte fait perdre autant de temps qu'un document qui en tait une.

**Une règle inventée par déduction ne se fait jamais démentir tant que rien ne la remplit.** C'est la deuxième fois — après la redevance au mètre carré de l'arbitrage A-01, qui n'a jamais produit un seul montant. Ici, « le sous-sol ne comporte aucun espace locatif » n'avait jamais été vérifié contre une donnée du village : il était déduit de ce que le sous-sol *est*. Le raisonnement était juste, sa prémisse était fausse, et rien dans le système ne pouvait le dire.

**La suite complète attrape ce que la suite filtrée laisse passer.** Le bug de la cloche n'existait, du point de vue des tests, que dans deux fichiers d'un module sans rapport. Lancer `--filter` sur ce qu'on vient de modifier donne une confiance qui ne correspond à rien.

### En fin de journée

- **374 tests au vert** sur la suite complète. Quatre échecs constatés vers 2 h 40 dans `AlerteStockTest`, tous en `SQLSTATE[40P01] Deadlock detected` sur les index d'unicité de Spatie : deux processus écrivaient sur la même base au même moment. Repassés 11/11 en isolation. Artefact d'exécution, pas régression.
- Trois commits poussés : le registre de verrous, la nature du contenant, la mise à jour de la dette technique.
