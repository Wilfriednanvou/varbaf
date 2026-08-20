# Conventions de code — ressources Filament

Référence de style pour toutes les ressources du projet. Toute nouvelle ressource reproduit ce patron.

---

## Structure d'un module

```
Modules/Artisanat/
  app/
    Filament/Resources/
    Models/
    Providers/
    Services/
  database/
    migrations/
    seeders/
  resources/views/
```

---

## Déclaration d'une ressource

```php
class ArtisanResource extends Resource
{
    protected static ?string $model = Artisan::class;
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-user-group';
    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::ARTISANAT;
    protected static ?string $navigationLabel = 'Artisans';
    protected static ?string $modelLabel = 'Artisan';
    protected static ?string $pluralModelLabel = 'Artisans';
    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_artisans');
    }
```

---

## Formulaire

Règles :
- Utiliser `Filament\Schemas\Schema` et `Filament\Schemas\Components\Grid`, **jamais** `Form`.
- Forcer `->columns(1)` sur le schéma, puis `Grid::make(2)` pour les paires de champs.
- Placeholders français obligatoires sur les `TextInput`.
- `helperText` uniquement sur les `Toggle`.
- Champs uniques : `->unique(ignoreRecord: true)`.

```php
public static function form(Schema $form): Schema
{
    return $form
        ->columns(1)
        ->schema([
            Grid::make(2)->schema([
                Forms\Components\TextInput::make('nom')
                    ->label('Nom')
                    ->placeholder('Nom de famille')
                    ->required(),
                Forms\Components\TextInput::make('prenom')
                    ->label('Prénom')
                    ->placeholder('Prénom')
                    ->required(),
            ]),
            Grid::make(2)->schema([
                Forms\Components\Select::make('corps_metier_id')
                    ->label('Corps de métier')
                    ->relationship('corpsMetier', 'libelle')
                    ->required(),
                Forms\Components\TextInput::make('telephone')
                    ->label('Téléphone')
                    ->placeholder('6XX XX XX XX'),
            ]),
            Forms\Components\Toggle::make('actif')
                ->label('Artisan actif')
                ->default(true)
                ->helperText('Un artisan inactif n\'apparaîtra pas dans les listes de sélection'),
        ]);
}
```

---

## Table

- `->searchable()` et `->sortable()` sur les champs pertinents.
- `->toggleable(isToggledHiddenByDefault: true)` sur les colonnes secondaires (dates système).
- Filtres `SelectFilter` et `TernaryFilter` en haut de table.
- Montants formatés avec `->money('XAF')`.

---

## Actions de ligne

Icône seule, avec infobulle.

```php
Actions\EditAction::make()
    ->iconButton()
    ->tooltip('Modifier')
    ->visible(fn () => auth()->user()->can('modifier_artisan'))
    ->modalHeading('Modifier l\'artisan')
    ->modalWidth('3xl')
    ->modalSubmitActionLabel('Enregistrer')
    ->modalCancelActionLabel('Fermer')
    ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
    ->stickyModalHeader()
    ->stickyModalFooter()
    ->after(function ($record) {
        JournalAudit::enregistrer(
            'Modification artisan',
            'ARTISANAT',
            'Artisan',
            $record->id,
            ['nom' => $record->nom]
        );
    }),
```

**Règles obligatoires sur chaque modal :**

| Propriété | Valeur |
|---|---|
| `modalWidth` | `'3xl'` sauf cas particulier |
| `modalSubmitActionLabel` | `'Enregistrer'` |
| `modalCancelActionLabel` | `'Fermer'` — jamais « Annuler » |
| `modalFooterActionsAlignment` | `Alignment::End` |
| `stickyModalHeader()` | Oui |
| `stickyModalFooter()` | Oui |
| `createAnother(false)` | Oui sur les créations |

---

## Page Manage

Les actions d'en-tête vivent dans `Pages/Manage<Entite>.php`.

```php
class ManageArtisans extends ManageRecords
{
    protected static string $resource = ArtisanResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.admin.pages.dashboard') => 'Accueil',
            '' => 'Artisans',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn () => auth()->user()->can('ajouter_artisan'))
                ->modalHeading('Nouvel artisan')
                ->modalWidth('3xl')
                ->createAnother(false)
                ->modalSubmitActionLabel('Enregistrer')
                ->modalCancelActionLabel('Fermer')
                ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->after(fn ($record) => JournalAudit::enregistrer(
                    'Création artisan', 'ARTISANAT', 'Artisan', $record->id, ['nom' => $record->nom]
                )),
        ];
    }
}
```

---

## Permissions

Nommage `<action>_<entite>` en snake_case, déclarées dans le seeder de permissions du module Socle :

```php
['name' => 'ajouter_artisan', 'module' => 'ARTISANAT', 'description' => 'Ajouter un artisan'],
```

Le `Gate::before` du module Socle accorde toutes les permissions aux super-utilisateurs : inutile de traiter ce cas dans les ressources.

---

## Écrans hors CRUD

Les écrans métier — saisie de vente, brouillard de caisse, campagne de reversement, tableau de bord — sont des pages Filament personnalisées, pas des ressources. Ils respectent les mêmes conventions de libellés, de permissions et d'audit.

---

## CSS

Aucun CSS personnalisé hors du fichier de thème du panneau. Les classes Filament standard héritent déjà du style du projet.

---

## Checklist avant chaque commit

- [ ] Permissions ajoutées dans le seeder
- [ ] `canAccess()` définie sur la ressource
- [ ] Chaque action porte un `->visible()` avec la bonne permission
- [ ] Actions de ligne en `iconButton()` + `tooltip()`
- [ ] Modals conformes au tableau ci-dessus
- [ ] `getBreadcrumbs()` défini
- [ ] `JournalAudit::enregistrer()` dans les `->after()`
- [ ] Libellés et placeholders en français
- [ ] Aucun CSS ajouté hors du thème
- [ ] `php artisan migrate:fresh --seed` passe sans erreur
- [ ] Test avec un utilisateur non super-utilisateur