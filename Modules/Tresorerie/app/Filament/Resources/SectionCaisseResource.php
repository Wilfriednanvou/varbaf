<?php

namespace Modules\Tresorerie\Filament\Resources;

use Filament\Actions;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Models\JournalAudit;
use Modules\Tresorerie\Filament\Resources\SectionCaisseResource\Pages;
use Modules\Tresorerie\Models\Caisse;
use Modules\Tresorerie\Models\SectionCaisse;

class SectionCaisseResource extends Resource
{
    protected static ?string $model = SectionCaisse::class;
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-folder-open';
    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::TRESORERIE;
    protected static ?string $navigationLabel = 'Sections de caisse';
    protected static ?string $modelLabel = 'Section de caisse';
    protected static ?string $pluralModelLabel = 'Sections de caisse';
    protected static ?int $navigationSort = 2;
    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_sections_caisse');
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->schema([
                Grid::make(2)->schema([
                    Forms\Components\Select::make('caisse_id')
                        ->label('Caisse')
                        ->relationship('caisse', 'libelle')
                        ->required()
                        ->searchable()
                        ->preload()
                        // Le solde d'ouverture affiché dépend de la
                        // caisse : il se recalcule dès qu'elle change.
                        ->live(),
                    Forms\Components\TextInput::make('libelle')
                        ->label('Libellé')
                        ->placeholder('Section exercice 2026')
                        ->required(),
                ]),
                Grid::make(2)->schema([
                    Forms\Components\DateTimePicker::make('date_ouverture')
                        ->label('Date d\'ouverture')
                        ->default(now())
                        ->required(),
                    // RG-02 : le solde d'ouverture est celui de clôture
                    // de la section précédente. Affiché, jamais saisi —
                    // le modèle le calcule à l'enregistrement.
                    Forms\Components\Placeholder::make('solde_ouverture_affiche')
                        ->label('Solde d\'ouverture (repris de la section précédente)')
                        ->content(fn (Get $get) => filled($get('caisse_id'))
                            ? number_format(
                                SectionCaisse::soldeDOuverturePour((int) $get('caisse_id')),
                                0,
                                ',',
                                ' ',
                            ).' FCFA'
                            : 'Choisissez d\'abord une caisse'),
                ]),
                Forms\Components\Hidden::make('ouverte_par')
                    ->default(fn () => auth()->id()),

                // `village_id` et `exercice_id` ne figurent plus au
                // formulaire : ce sont des valeurs dérivées — le village
                // est celui de la caisse choisie, l'exercice est celui en
                // cours. `ManageSectionsCaisse` les pose à
                // l'enregistrement et refuse l'ouverture si aucun
                // exercice n'est en cours.
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('caisse.libelle')
                    ->label('Caisse')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('libelle')
                    ->label('Libellé')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('date_ouverture')
                    ->label('Ouverte le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('date_cloture')
                    ->label('Clôturée le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('solde_ouverture')
                    ->label('Solde ouverture')
                    ->money('XAF')
                    ->sortable(),
                Tables\Columns\TextColumn::make('solde_cloture')
                    ->label('Solde clôture')
                    ->money('XAF')
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('etat')
                    ->label('État')
                    ->badge(),
                Tables\Columns\TextColumn::make('ouvertePar.name')
                    ->label('Ouverte par')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('clotureePar.name')
                    ->label('Clôturée par')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('date_ouverture', 'desc')
            ->recordActions([
                Actions\Action::make('cloturer')
                    ->label('Clôturer')
                    ->icon('heroicon-o-lock-closed')
                    ->iconButton()
                    ->tooltip('Clôturer cette section')
                    ->color('danger')
                    ->visible(fn (SectionCaisse $record) =>
                        $record->estOuverte() && auth()->user()->can('cloturer_section_caisse')
                    )
                    ->requiresConfirmation()
                    ->modalHeading('Clôturer la section')
                    ->modalDescription(fn (SectionCaisse $record) =>
                        "Êtes-vous certain de vouloir clôturer la section « {$record->libelle} » ? Cette action est irréversible."
                    )
                    ->action(function (SectionCaisse $record) {
                        $soldeCourant = $record->cloturer();

                        JournalAudit::enregistrer(
                            'Clôture section de caisse',
                            'TRESORERIE',
                            'SectionCaisse',
                            $record->id,
                            ['libelle' => $record->libelle, 'solde_cloture' => $soldeCourant]
                        );
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageSectionsCaisse::route('/'),
        ];
    }
}
