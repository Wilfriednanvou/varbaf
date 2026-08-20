# Analyse et conception — Système d'information du Village Artisanal Régional de Bafoussam (VARBAF)
## Modèle de classes

*Structure inspirée du dossier de conception ASM (Alliance School Master).*

---

## Correspondance avec le modèle ASM

| Concept ASM | Équivalent VARBAF | Commentaire |
|---|---|---|
| Etablissement | VillageArtisanal | Racine multi-structure, réplicable aux autres villages |
| AnneeScolaire | Exercice | Une seule active, clôture irréversible |
| Personnel | Agent | Modèle allégé, sans héritage |
| Apprenant | Artisan | Sans champs médicaux ni parents |
| Inscription | AttributionBoutique | Liaison personne / période / ressource |
| SalleClasse | Espace | Salles de réunion, d'apprentissage, stands |
| SeanceCours | ReservationEspace | Reprend `verifierConflit()` |
| Caisse, SessionCaisse, MouvementCaisse | Caisse, SectionCaisse, MouvementCaisse | Transposition directe |
| LibelleCaisse | LibelleMouvement | Natures d'opération paramétrables |
| Reglement | Vente | Génère automatiquement un mouvement de caisse |
| Tranche, PaiementScolarite | EcheanceRedevance, PaiementRedevance | Échéancier des boutiques |
| Module Sécurité | Module Sécurité | Repris intégralement |
| Modules Notes, Évaluations, Bibliothèque | — | Sans équivalent |

---

## Module 1 : Référentiel et organisation

### Classe : VillageArtisanal
```
class VillageArtisanal {
// === IDENTIFICATION ===
+ id: Long
+ code: String                  // Code court unique
+ nom: String                   // Ex: Village Artisanal Regional de Bafoussam
+ categorie: Enum(REGIONAL, SPECIAL, CENTRE_INTERNATIONAL)
+ region: String                // Region de rattachement

// === COORDONNEES ===
+ adresse: String
+ telephone: String
+ email: String

// === CAPACITE ===
+ nombreBoutiques: Integer      // Ex: 24
+ superficie: Decimal           // En metres carres

// === STATUT ===
+ actif: Boolean
+ dateCreation: DateTime
+ dateModification: DateTime

// === METHODES ===
getArtisans(): List<Artisan> {
  // Retourne les artisans rattaches au village
}
getStatistiques(): Map<String, Object> {
  // Effectifs, taux d occupation, chiffre d affaires, recettes de commission
}
}
```

### Classe : Exercice
```
class Exercice {
+ id: Long
+ libelle: String               // Ex: "2026-2027"
+ dateDebut: Date
+ dateFin: Date
+ enCours: Boolean              // Un seul exercice actif a la fois
+ cloture: Boolean              // Exercice cloture, non modifiable
+ village: VillageArtisanal     // (FK)
+ dateCreation: DateTime

// === METHODES ===
activer(): Boolean {
  // Active cet exercice et desactive le precedent
}
cloturer(): Boolean {
  // Verifie que toutes les sections de caisse sont clôturees
  // et que toutes les campagnes de reversement sont validees
  // Action irreversible
}
}
```

### Classe : Agent
```
class Agent {
+ id: Long
+ matricule: String             // Genere automatiquement
+ nom: String
+ prenom: String
+ sexe: Enum(MASCULIN, FEMININ)
+ telephone: String
+ email: String
+ fonction: String              // Ex: coordonnateur, caissier, agent commercial
+ datePriseService: Date
+ actif: Boolean
+ village: VillageArtisanal     // (FK)

// === METHODES ===
getVentes(exercice: Exercice): List<Vente> {
  // Retourne les ventes realisees par cet agent
}
}
```

### Classe : CorpsMetier
```
class CorpsMetier {
+ id: Long
+ libelle: String               // Ex: vannerie, poterie, sculpture sur bois, tissage
+ code: String
+ description: String
}
```

### Classe : Artisan
```
class Artisan {
+ id: Long
+ matricule: String             // Genere automatiquement
+ nom: String
+ prenom: String
+ sexe: Enum(MASCULIN, FEMININ)
+ telephone: String
+ telephoneSecondaire: String
+ email: String
+ adresse: String
+ departementOrigine: String    // Mifi, Noun, Menoua, Bamboutos, Koung-Khi, Hauts-Plateaux
+ numeroEnregistrement: String  // Enregistrement au repertoire communal
+ dateEnregistrement: Date
+ photo: String
+ actif: Boolean
+ corpsMetier: CorpsMetier      // (FK)
+ entreprise: EntrepriseArtisanale  // (FK, nullable)
+ village: VillageArtisanal     // (FK)
+ dateCreation: DateTime
+ dateModification: DateTime

// === METHODES ===
getAttributionActive(): AttributionBoutique {
  // Retourne la boutique occupee pour l exercice en cours
}
getHistoriqueAttributions(): List<AttributionBoutique> {
  // Retourne toutes les occupations passees
}
getProduits(): List<Produit> {
  // Retourne les produits de l artisan
}
getSoldeDu(): Decimal {
  // Retourne le montant restant a reverser
}
}
```

### Classe : EntrepriseArtisanale
```
class EntrepriseArtisanale {
+ id: Long
+ raisonSociale: String
+ numeroContribuable: String
+ telephone: String
+ adresse: String
+ village: VillageArtisanal     // (FK)
}
```

### Classe : Boutique
```
class Boutique {
+ id: Long
+ numero: String                // Ex: B-01 a B-24
+ superficie: Decimal
+ emplacement: String           // Sous-sol, rez-de-chaussee, etage
+ redevanceMensuelle: Decimal   // Montant de reference
+ etat: Enum(DISPONIBLE, OCCUPEE, INDISPONIBLE)
+ village: VillageArtisanal     // (FK)

// === METHODES ===
getOccupantActuel(): Artisan {
  // Retourne l artisan attributaire a la date du jour
}
estDisponible(dateDebut: Date, dateFin: Date): Boolean {
  // Verifie l absence d attribution sur la periode
}
}
```

### Classe : AttributionBoutique
```
class AttributionBoutique {
+ id: Long
+ dateDebut: Date
+ dateFin: Date                 // Null si attribution en cours
+ redevanceConvenue: Decimal    // Montant fige a l attribution
+ statut: Enum(ACTIVE, RESILIEE, TERMINEE)
+ motifResiliation: String
+ artisan: Artisan              // (FK)
+ boutique: Boutique            // (FK)
+ exercice: Exercice            // (FK)

// === METHODES ===
resilier(motif: String): Boolean {
  // Cloture l attribution, libere la boutique
}
getEcheancesImpayees(): List<EcheanceRedevance> {
  // Retourne les redevances non reglees
}
}
```

### Classe : Espace
```
class Espace {
+ id: Long
+ nom: String                   // Ex: salle de reunion 1, salle d apprentissage 3
+ type: Enum(SALLE_REUNION, SALLE_APPRENTISSAGE, STAND, PARKING)
+ capacite: Integer
+ tarifJournalier: Decimal
+ disponible: Boolean
+ village: VillageArtisanal     // (FK)
}
```

---

## Module 2 : Produits et ventes

### Classe : CategorieProduit
```
class CategorieProduit {
+ id: Long
+ libelle: String               // Ex: bronze, poterie, tissage, mobilier en bambou
+ code: String
+ categorieParent: CategorieProduit  // (FK, nullable)
}
```

### Classe : Produit
```
class Produit {
+ id: Long
+ reference: String             // Reference unique generee, ex: BTQ12-0043
+ designation: String           // Nom du produit
+ description: String
+ prixUnitaire: Decimal         // Prix de vente courant
+ quantiteDisponible: Integer
+ pieceUnique: Boolean          // true si oeuvre non reproductible
+ photo: String
+ actif: Boolean
+ categorie: CategorieProduit   // (FK)
+ artisan: Artisan              // (FK)
+ boutique: Boutique            // (FK)
+ dateCreation: DateTime

// === METHODES ===
estDisponible(quantite: Integer): Boolean {
  // Verifie la disponibilite pour la quantite demandee
}
decrementerStock(quantite: Integer): Boolean {
  // Diminue la quantite disponible apres une vente
}
}
```

### Classe : TauxCommission
```
class TauxCommission {
+ id: Long
+ taux: Decimal                 // Pourcentage, uniforme pour tous les artisans
+ dateEffet: Date               // Date d entree en vigueur
+ referenceDecision: String     // Acte ou note de service
+ saisiPar: Utilisateur         // (FK)
+ village: VillageArtisanal     // (FK)

// === METHODES ===
static getTauxEnVigueur(date: Date): Decimal {
  // Retourne le taux applicable a la date donnee
}
}
```

### Classe : Vente
```
class Vente {
+ id: Long
+ numero: String                // Numero de ticket unique
+ dateVente: DateTime
+ montantTotal: Decimal         // Total encaisse aupres du client
+ tauxCommission: Decimal       // Taux fige a la vente
+ montantCommission: Decimal    // Part du village
+ partArtisan: Decimal          // montantTotal - montantCommission
+ modeReglement: Enum(ESPECES, MOBILE_MONEY, AUTRE)
+ nomClient: String              // Facultatif
+ contactClient: String          // Telephone ou email, facultatif
+ accepteNotifications: Boolean  // Consentement a recevoir les informations du village
+ provenanceClient: Enum(LOCAL, NATIONAL, ETRANGER)  // Facultatif, un seul clic
+ etat: Enum(VALIDEE, ANNULEE)
+ boutique: Boutique            // (FK) une seule boutique par vente
+ artisan: Artisan              // Fige a la vente (FK)
+ vendeur: Agent                // Agent du village ayant realise la vente (FK)
+ sectionCaisse: SectionCaisse  // (FK)
+ campagneReversement: CampagneReversement  // (FK, nullable) null tant que non reversee
+ exercice: Exercice            // (FK)

// === METHODES ===
calculer(): void {
  // montantTotal = somme des lignes
  // tauxCommission = TauxCommission.getTauxEnVigueur(dateVente)
  // montantCommission = montantTotal * tauxCommission / 100
  // partArtisan = montantTotal - montantCommission
}
enregistrer(): Boolean {
  // Valide la vente, decremente les stocks
  // Cree automatiquement un MouvementCaisse de sens ENTREE
  // Crediteur le compte de l artisan de partArtisan
}
annuler(motif: String): Boolean {
  // Passe l etat a ANNULEE
  // Cree une contre-passation dans le brouillard
  // Si deja reversee, reporte une ligne negative sur la campagne suivante
}
genererRecu(): byte[] {
  // Genere le recu de vente en PDF
}
}
```

### Classe : LigneVente
```
class LigneVente {
+ id: Long
+ referenceProduit: String      // Figee
+ designation: String           // Figee
+ prixUnitaire: Decimal         // Fige
+ quantite: Integer
+ montantLigne: Decimal         // prixUnitaire * quantite
+ vente: Vente                  // (FK)
+ produit: Produit              // (FK, reference technique)
}
```

---

## Module 3 : Trésorerie

### Classe : Caisse
```
class Caisse {
+ id: Long
+ code: String
+ libelle: String
+ actif: Boolean
+ caissier: Agent               // Responsable de caisse (FK)
+ village: VillageArtisanal     // (FK)

// === METHODES ===
getSectionOuverte(): SectionCaisse {
  // Retourne la section actuellement ouverte, ou null
}
}
```

### Classe : SectionCaisse
```
class SectionCaisse {
+ id: Long
+ libelle: String               // Ex: exercice 2026-2027
+ dateOuverture: Date
+ dateCloture: Date             // Null si ouverte
+ soldeOuverture: Decimal
+ soldeCloture: Decimal
+ statut: Enum(OUVERTE, CLOTUREE)
+ caisse: Caisse                // (FK)
+ exercice: Exercice            // (FK)
+ ouvertePar: Utilisateur       // (FK)
+ clotureePar: Utilisateur      // (FK, nullable)

// === METHODES ===
ouvrir(): Boolean {
  // Verifie qu aucune autre section de la caisse n est ouverte
  // soldeOuverture = soldeCloture de la section precedente
}
cloturer(): Boolean {
  // soldeCloture = soldeOuverture + entrees - sorties
  // Action irreversible
}
getSoldeActuel(): Decimal {
  // Retourne le solde du dernier mouvement
}
getProchainNumeroOrdre(): Integer {
  // Retourne le numero sequentiel suivant, sans rupture
}
}
```

### Classe : LibelleMouvement
```
class LibelleMouvement {
+ id: Long
+ libelle: String               // Ex: vente de produit, redevance de boutique, reversement artisan
+ code: String
+ sens: Enum(ENTREE, SORTIE)
+ actif: Boolean
+ village: VillageArtisanal     // (FK)
}
```

### Classe : MouvementCaisse
```
class MouvementCaisse {
+ id: Long
+ numeroOrdre: Integer          // Sequentiel dans la section, sans rupture
+ dateMouvement: DateTime
+ sens: Enum(ENTREE, SORTIE)
+ montant: Decimal
+ description: String
+ soldeApres: Decimal           // Solde de caisse apres l operation
+ pieceJustificative: String
+ origineType: String           // Vente, Reversement, PaiementRedevance, Depense
+ origineId: Long               // Identifiant de l operation source
+ mouvementContrepasse: MouvementCaisse  // (FK, nullable)
+ sectionCaisse: SectionCaisse  // (FK)
+ libelleMouvement: LibelleMouvement     // (FK)
+ saisiPar: Utilisateur         // (FK)

// === METHODES ===
static enregistrer(section, libelle, sens, montant, origine): MouvementCaisse {
  // Point d entree unique du brouillard de caisse
  // Verifie que la section est ouverte
  // Attribue le numero d ordre et calcule soldeApres
}
contrepasser(motif: String): MouvementCaisse {
  // Cree un mouvement de sens inverse referencant celui-ci
  // Le mouvement d origine reste inchange
}
}
```

### Classe : CompteArtisan
```
class CompteArtisan {
+ id: Long
+ artisan: Artisan              // (FK)
+ exercice: Exercice            // (FK)

// === METHODES ===
getTotalVendu(): Decimal {
  // Somme des parts artisan des ventes validees
}
getTotalReverse(): Decimal {
  // Somme des reversements payes
}
getSoldeDu(): Decimal {
  // getTotalVendu() - getTotalReverse()
  // Valeur toujours calculee, jamais saisie
}
getMouvements(): List<Object> {
  // Historique chronologique des credits et debits
}
}
```

### Classe : CampagneReversement
```
class CampagneReversement {
+ id: Long
+ periode: String               // Ex: "2026-08"
+ dateArrete: Date              // Date de selection des ventes
+ dateGeneration: DateTime
+ montantTotal: Decimal
+ nombreBeneficiaires: Integer
+ statut: Enum(EN_PREPARATION, VALIDEE)
+ exercice: Exercice            // (FK)
+ genereePar: Utilisateur       // (FK)
+ valideePar: Utilisateur       // (FK, nullable)

// === METHODES ===
preparer(): Boolean {
  // Selectionne les ventes validees non rattachees a une campagne validee
  // dont dateVente <= dateArrete, y compris celles des periodes anterieures
  // Regroupe par artisan et cree les Reversement
}
valider(): Boolean {
  // Rattache definitivement les ventes retenues
  // Genere un MouvementCaisse de sens SORTIE par artisan beneficiaire
  // Action irreversible
}
getEtatRecapitulatif(): byte[] {
  // Genere l etat de reversement en PDF
}
}
```

### Classe : Reversement
```
class Reversement {
+ id: Long
+ montantPeriode: Decimal       // Part issue des ventes de la periode
+ montantRegularisation: Decimal // Ventes ou annulations anterieures reportees
+ montantPaye: Decimal          // Montant effectivement decaisse
+ soldeReporte: Decimal         // Reliquat reporte sur la campagne suivante
+ datePaiement: Date
+ statut: Enum(EN_ATTENTE, PAYE, REPORTE)
+ campagne: CampagneReversement // (FK)
+ artisan: Artisan              // (FK)
+ mouvementCaisse: MouvementCaisse  // (FK, nullable)

// === METHODES ===
calculer(): void {
  // montantPaye = max(0, montantPeriode + montantRegularisation)
  // soldeReporte = montant negatif eventuel, reporte
}
genererRecu(): byte[] {
  // Genere le recu de reversement a faire signer par l artisan
}
}
```

### Classe : EcheanceRedevance
```
class EcheanceRedevance {
+ id: Long
+ periode: String               // Mois concerne
+ montant: Decimal
+ dateLimite: Date
+ statut: Enum(A_PAYER, PAYEE, EN_RETARD)
+ attribution: AttributionBoutique  // (FK)
}
```

### Classe : PaiementRedevance
```
class PaiementRedevance {
+ id: Long
+ montantPaye: Decimal
+ datePaiement: DateTime
+ echeance: EcheanceRedevance   // (FK)
+ sectionCaisse: SectionCaisse  // (FK)

// === METHODES ===
enregistrer(): Boolean {
  // Cree un MouvementCaisse de sens ENTREE
  // Met a jour le statut de l echeance
}
}
```

---

## Module 4 : Sécurité et gestion des accès

Repris du module Sécurité d'ASM, avec les classes `Utilisateur`, `Role`, `Permission`, `SessionConnexion` et `JournalAudit`.

Adaptations :
- `Utilisateur.agent: Agent` remplace le lien vers `Personnel`.
- Les permissions suivent la convention `<action>_<entite>` en snake_case : `lister_ventes`, `ajouter_vente`, `annuler_vente`, `ouvrir_section_caisse`, `cloturer_section_caisse`, `valider_campagne_reversement`, `modifier_taux_commission`.
- `JournalAudit.enregistrer()` est appelé sur toute création, modification, annulation de vente, ouverture ou clôture de section, et validation de campagne de reversement.

Règle de séparation des rôles : le profil autorisé à saisir une vente ne doit pas être celui autorisé à clôturer une section de caisse ou à valider une campagne de reversement.

---

## Module 5 : Formations, événements et réservations *(version 2)*

Classes envisagées, sur le patron `Inscription` et `SeanceCours` d'ASM :

- `SessionFormation` — thème, formateur, dates, capacité, espace utilisé
- `InscriptionFormation` — liaison artisan / session, présence, attestation
- `Evenement` — manifestation culturelle, dates, artisans participants
- `ReservationEspace` — demandeur, espace, période, statut, avec une méthode `verifierConflit()` reprise de `SeanceCours`

---

## Module 6 : Portail public *(version 2)*

Couche de diffusion au-dessus des mêmes produits et artisans — sans duplication de données.

- `PublicationProduit` — produit, statut publié, photo de mise en avant, description commerciale, ordre d'affichage
- `ArtisanVedette` — artisan, période de mise en avant, texte de présentation
- `ContenuPage` — textes de présentation du village, gérés depuis le tableau de bord

---

## Points de vigilance de conception

1. Le figement des données de vente (référence, désignation, prix, boutique, artisan, taux) est ce qui garantit l'intégrité de l'historique. Ne jamais reconstituer ces valeurs depuis les référentiels.
2. `MouvementCaisse.enregistrer()` est le point d'entrée unique du brouillard. Aucun module n'écrit directement dans la table.
3. `CompteArtisan.getSoldeDu()` est calculé, jamais stocké comme valeur modifiable.
4. Toute correction passe par contre-passation, jamais par suppression.
5. Le dossier de conception doit rester synchronisé avec l'implémentation : toute divergence sera relevée en soutenance.