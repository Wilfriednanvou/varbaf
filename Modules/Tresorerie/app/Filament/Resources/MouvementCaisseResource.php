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
use Modules\Tresorerie\Enums\NatureMouvementCaisse;
use Modules\Tresorerie\Enums\SensMouvementCaisse;
use Modules\Tresorerie\Filament\Resources\MouvementCaisseResource\Pages;
use Modules\Tresorerie\Models\MouvementCaisse;
use Modules\Tresorerie\Services\ServiceTresorerie;

class MouvementCaisseResource extends Resource
{
    protected static ?string $model = MouvementCaisse::class;
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-text';
    protected static string | \UnitEnum | null $navigationGroup = NavigationGroup::TRESORERIE;
    protected static ?string $navigationLabel = 'Brouillard de caisse';
    protected static ?string $modelLabel = 'Mouvement de caisse';
    protected static ?string $pluralModelLabel = 'Mouvements de caisse';
    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return auth()->user()->can('lister_mouvements_caisse');
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->schema([
                Grid::make(2)->schema([
                    Forms\Components\Select::make('section_id')
                        ->label('Section de caisse')
                        ->relationship('section', 'libelle')
                        ->required()
                        ->searchable()
                        ->preload(),
                    Forms\Components\Select::make('nature')
                        ->label('Nature')
                        ->options(NatureMouvementCaisse::options())
                        ->required(),
                ]),
                Grid::make(2)->schema([
                    Forms\Components\Select::make('sens')
                        ->label('Sens')
                        ->options(SensMouvementCaisse::options())
                        ->required(),
                    Forms\Components\TextInput::make('montant')
                        ->label('Montant')
                        ->placeholder('0')
                        ->integer()
                        ->required()
                        ->minValue(1),
                ]),
                Forms\Components\TextInput::make('libelle')
                    ->label('Libellé')
                    ->placeholder('Description du mouvement')
                    ->required(),
                Forms\Components\TextInput::make('piece_justificative')
                    ->label('Pièce justificative')
                    ->placeholder('N° de pièce'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('numero_ordre')
                    ->label('N°')
                    ->sortable(),
                Tables\Columns\TextColumn::make('date_operation')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('nature')
                    ->label('Nature')
                    ->badge(),
                Tables\Columns\TextColumn::make('libelle')
                    ->label('Libellé')
                    ->searchable()
                    ->limit(50),
                Tables\Columns\TextColumn::make('sens')
                    ->label('Sens')
                    ->badge(),
                Tables\Columns\TextColumn::make('montant')
                    ->label('Montant')
                    ->money('XAF')
                    ->sortable(),
                Tables\Columns\TextColumn::make('solde_apres')
                    ->label('Solde après')
                    ->money('XAF')
                    ->sortable(),
                Tables\Columns\TextColumn::make('piece_justificative')
                    ->label('Pièce')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('section.libelle')
                    ->label('Section')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('saisiPar.name')
                    ->label('Saisi par')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('numero_ordre', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('nature')
                    ->label('Nature')
                    ->options(NatureMouvementCaisse::options()),
                Tables\Filters\SelectFilter::make('sens')
                    ->label('Sens')
                    ->options(SensMouvementCaisse::options()),
                Tables\Filters\SelectFilter::make('section_id')
                    ->label('Section')
                    ->relationship('section', 'libelle'),
                Tables\Filters\Filter::make('date_operation')
                    ->form([
                        Forms\Components\DatePicker::make('du')
                            ->label('Du'),
                        Forms\Components\DatePicker::make('au')
                            ->label('Au'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['du'] ?? null, fn ($q, $date) => $q->whereDate('date_operation', '>=', $date))
                            ->when($data['au'] ?? null, fn ($q, $date) => $q->whereDate('date_operation', '<=', $date));
                    }),
            ])
            ->recordActions([
                Actions\Action::make('contrepasser')
                    ->label('Contre-passer')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->iconButton()
                    ->tooltip('Contre-passer ce mouvement')
                    ->color('warning')
                    ->visible(fn (MouvementCaisse $record) =>
                        ! $record->estUneContrepassation()
                        && ! $record->estContrepasse()
                        && auth()->user()->can('contrepasser_mouvement_caisse')
                    )
                    ->form([
                        Forms\Components\Textarea::make('motif')
                            ->label('Motif de la contre-passation')
                            ->required()
                            ->placeholder('Décrivez la raison de la correction'),
                    ])
                    ->modalHeading('Contre-passer le mouvement')
                    ->modalWidth('3xl')
                    ->modalSubmitActionLabel('Confirmer')
                    ->modalCancelActionLabel('Fermer')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->action(function (MouvementCaisse $record, array $data) {
                        $service = app(ServiceTresorerie::class);
                        $contrepassation = $service->contrepasser($record, $data['motif']);

                        JournalAudit::enregistrer(
                            'Contre-passation mouvement de caisse',
                            'TRESORERIE',
                            'MouvementCaisse',
                            $contrepassation->id,
                            [
                                'mouvement_origine' => $record->numero_ordre,
                                'motif' => $data['motif'],
                            ]
                        );
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageMouvementsCaisse::route('/'),
        ];
    }
}
