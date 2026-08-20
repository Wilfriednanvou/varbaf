<?php

namespace Modules\Socle\Filament\Resources;

use Filament\Actions;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Filament\Resources\JournalAuditResource\Pages;
use Modules\Socle\Models\JournalAudit;

/**
 * Consultation du journal d'audit.
 *
 * Ressource délibérément en lecture seule : ni création, ni
 * modification, ni suppression. Une piste d'audit que l'on peut
 * effacer depuis l'application ne prouve rien. Les seules opérations
 * offertes sont la recherche, le filtrage et la consultation du détail.
 */
class JournalAuditResource extends Resource
{
    protected static ?string $model = JournalAudit::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::SECURITE;

    protected static ?string $navigationLabel = 'Journal d\'audit';

    protected static ?string $modelLabel = 'Écriture d\'audit';

    protected static ?string $pluralModelLabel = 'Journal d\'audit';

    protected static ?string $slug = 'journal-audit';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_journaux_audit');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Grid::make(2)->schema([
                    Infolists\Components\TextEntry::make('action')
                        ->label('Action'),
                    Infolists\Components\TextEntry::make('module')
                        ->label('Module')
                        ->badge(),
                ]),
                Grid::make(2)->schema([
                    Infolists\Components\TextEntry::make('entite')
                        ->label('Entité')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('entite_id')
                        ->label('Identifiant de l\'entité')
                        ->placeholder('—'),
                ]),
                Grid::make(2)->schema([
                    Infolists\Components\TextEntry::make('nom_utilisateur')
                        ->label('Utilisateur')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('adresse_ip')
                        ->label('Adresse IP')
                        ->placeholder('—'),
                ]),
                Infolists\Components\TextEntry::make('created_at')
                    ->label('Horodatage')
                    ->dateTime('d/m/Y H:i:s'),
                Infolists\Components\KeyValueEntry::make('donnees')
                    ->label('Données enregistrées')
                    ->keyLabel('Champ')
                    ->valueLabel('Valeur'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Horodatage')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
                Tables\Columns\TextColumn::make('action')
                    ->label('Action')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('module')
                    ->label('Module')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('entite')
                    ->label('Entité')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('entite_id')
                    ->label('Identifiant')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('nom_utilisateur')
                    ->label('Utilisateur')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('adresse_ip')
                    ->label('Adresse IP')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('module')
                    ->label('Module')
                    ->options([
                        'SOCLE' => 'Socle',
                        'ARTISANAT' => 'Artisanat',
                        'COMMERCE' => 'Commerce',
                        'TRESORERIE' => 'Trésorerie',
                        'PILOTAGE' => 'Pilotage',
                        'PORTAIL' => 'Portail',
                    ]),
                Tables\Filters\SelectFilter::make('utilisateur_id')
                    ->label('Utilisateur')
                    ->relationship('utilisateur', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Actions\ViewAction::make()
                    ->iconButton()
                    ->tooltip('Consulter')
                    ->modalHeading('Détail de l\'écriture d\'audit')
                    ->modalWidth('3xl')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter(),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Aucune écriture d\'audit')
            ->emptyStateDescription('Le journal se remplit automatiquement au fil des opérations.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageJournauxAudit::route('/'),
        ];
    }
}
