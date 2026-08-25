<?php

namespace Modules\Portail\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Socle\Enums\NavigationGroup;
use Modules\Socle\Models\JournalAudit;
use Modules\Portail\Enums\StatutDemandeContact;
use Modules\Portail\Filament\Resources\DemandeContactResource\Pages;
use Modules\Portail\Models\DemandeContact;

/**
 * Suivi des demandes reçues par le formulaire public.
 *
 * **Aucune création, aucune suppression.** Une demande naît sur le site
 * et n'est écrite que par un visiteur ; on la lit et on la traite. Ce
 * qui n'appelle pas de réponse s'archive — la ligne reste.
 *
 * Le formulaire de traitement ne montre le message qu'en lecture : le
 * modèle refuserait de toute façon sa modification, mais un champ
 * modifiable donnerait à croire qu'on peut le corriger.
 */
class DemandeContactResource extends Resource
{
    protected static ?string $model = DemandeContact::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-envelope';

    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::PORTAIL;

    protected static ?string $navigationLabel = 'Demandes de contact';

    protected static ?string $modelLabel = 'Demande de contact';

    protected static ?string $pluralModelLabel = 'Demandes de contact';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_demandes_contact');
    }

    public static function getNavigationBadge(): ?string
    {
        $aTraiter = DemandeContact::query()->aTraiter()->count();

        return $aTraiter > 0 ? (string) $aTraiter : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->schema([
                Grid::make(2)->schema([
                    Forms\Components\Select::make('statut')
                        ->label('Statut')
                        ->options(StatutDemandeContact::options())
                        ->required(),

                    Forms\Components\Hidden::make('traitee_par')
                        ->default(fn () => auth()->id()),
                ]),

                Forms\Components\Textarea::make('note_traitement')
                    ->label('Note de traitement')
                    ->placeholder('Répondu par téléphone le 22/08')
                    ->rows(4),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Reçue le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('nom')
                    ->label('Nom')
                    ->searchable(),
                Tables\Columns\TextColumn::make('contact')
                    ->label('Contact')
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('sujet')
                    ->label('Sujet')
                    ->placeholder('—')
                    ->limit(40),
                Tables\Columns\TextColumn::make('statut')
                    ->label('Statut')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('traiteePar.name')
                    ->label('Traitée par')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('adresse_ip')
                    ->label('Adresse IP')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('statut')
                    ->label('Statut')
                    ->options(StatutDemandeContact::options()),
            ])
            ->recordActions([
                Actions\Action::make('consulter')
                    ->label('Consulter')
                    ->icon('heroicon-o-eye')
                    ->iconButton()
                    ->tooltip('Lire le message')
                    ->visible(fn () => auth()->user()->can('lister_demandes_contact'))
                    ->modalHeading(fn (DemandeContact $record) => "Message de {$record->nom}")
                    ->modalWidth('2xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('contact')->label('Contact'),
                            TextEntry::make('created_at')->label('Reçue le')->dateTime('d/m/Y H:i'),
                        ]),
                        TextEntry::make('sujet')->label('Sujet')->placeholder('—'),
                        TextEntry::make('message')->label('Message'),
                        TextEntry::make('note_traitement')
                            ->label('Note de traitement')
                            ->placeholder('—'),
                    ]),

                Actions\EditAction::make()
                    ->label('Traiter')
                    ->icon('heroicon-o-check-circle')
                    ->iconButton()
                    ->tooltip('Traiter la demande')
                    ->visible(fn () => auth()->user()->can('traiter_demande_contact'))
                    ->modalHeading('Traiter la demande')
                    ->modalWidth('2xl')
                    ->modalSubmitActionLabel('Enregistrer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->mutateDataUsing(function (array $data): array {
                        // L'horodatage se constate au moment où l'on
                        // clôt la demande, il ne se saisit pas.
                        $statut = StatutDemandeContact::tryFrom((string) ($data['statut'] ?? ''));

                        $data['date_traitement'] = $statut?->estClose() ? now() : null;

                        return $data;
                    })
                    ->after(fn ($record) => JournalAudit::enregistrer(
                        'Traitement demande de contact',
                        'PORTAIL',
                        'DemandeContact',
                        $record->id,
                        ['statut' => $record->statut?->value],
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageDemandesContact::route('/'),
        ];
    }
}
