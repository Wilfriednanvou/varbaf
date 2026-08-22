<?php

namespace Modules\Tresorerie\Filament\Resources;

use Filament\Actions;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Models\JournalAudit;
use Modules\Tresorerie\Enums\EtatSectionCaisse;
use Modules\Tresorerie\Filament\Pages\ManageCaisseSession;
use Modules\Tresorerie\Filament\Resources\CaisseResource\Pages;
use Modules\Tresorerie\Models\Caisse;

class CaisseResource extends Resource
{
    protected static ?string $model = Caisse::class;
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-banknotes';
    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::TRESORERIE;
    protected static ?string $navigationLabel = 'Caisses';
    protected static ?string $modelLabel = 'Caisse';
    protected static ?string $pluralModelLabel = 'Caisses';
    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_caisses');
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->schema([
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('Code')
                        ->placeholder('CAISSE-PRINCIPALE')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(30),
                    Forms\Components\TextInput::make('libelle')
                        ->label('Libellé')
                        ->placeholder('Caisse principale')
                        ->required(),
                ]),
                Grid::make(2)->schema([
                    Forms\Components\Select::make('caissier_responsable_id')
                        ->label('Caissier responsable')
                        ->relationship('caissierResponsable', 'nom')
                        ->searchable()
                        ->preload(),
                    Forms\Components\Select::make('etat')
                        ->label('État')
                        ->options(\Modules\Tresorerie\Enums\EtatCaisse::options())
                        ->default('ACTIVE')
                        ->required(),
                ]),
                Forms\Components\Hidden::make('village_id')
                    ->default(fn () => \Modules\Socle\Models\VillageArtisanal::query()->value('id')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('libelle')
                    ->label('Libellé')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('caissierResponsable.nom')
                    ->label('Caissier responsable')
                    ->sortable()
                    ->placeholder('Non assigné'),
                Tables\Columns\TextColumn::make('etat')
                    ->label('État')
                    ->badge(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créée le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                // Route qualifiée dès le clic : caisse et section (celle
                // ouverte, ou la plus récente à défaut) portées dans
                // l'URL, pour qu'un rafraîchissement de la session ne
                // perde jamais le contexte.
                Actions\Action::make('session')
                    ->label('Session de caisse')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->iconButton()
                    ->tooltip('Ouvrir la session de caisse')
                    ->visible(fn () => auth()->user()->can('lister_sections_caisse'))
                    ->url(fn (Caisse $record) => ManageCaisseSession::getUrl([
                        'caisse' => $record->getKey(),
                        'section' => $record->sections()
                            ->where('etat', EtatSectionCaisse::OUVERTE->value)
                            ->value('id')
                            ?? $record->sections()->latest('id')->value('id'),
                    ])),
                Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Modifier')
                    ->visible(fn () => auth()->user()->can('modifier_caisse'))
                    ->modalHeading('Modifier la caisse')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->after(function ($record) {
                        JournalAudit::enregistrer(
                            'Modification caisse',
                            'TRESORERIE',
                            'Caisse',
                            $record->id,
                            ['code' => $record->code, 'libelle' => $record->libelle]
                        );
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageCaisses::route('/'),
        ];
    }
}
