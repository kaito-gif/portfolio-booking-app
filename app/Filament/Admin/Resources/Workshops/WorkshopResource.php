<?php

namespace App\Filament\Admin\Resources\Workshops;

use App\Filament\Admin\Resources\Workshops\Pages\CreateWorkshop;
use App\Filament\Admin\Resources\Workshops\Pages\EditWorkshop;
use App\Filament\Admin\Resources\Workshops\Pages\ListWorkshops;
use App\Filament\Admin\Resources\Workshops\Schemas\WorkshopForm;
use App\Filament\Admin\Resources\Workshops\Tables\WorkshopsTable;
use App\Models\Workshop;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WorkshopResource extends Resource
{
    protected static ?string $model = Workshop::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $navigationLabel = '講座';

    protected static ?string $modelLabel = '講座';

    public static function form(Schema $schema): Schema
    {
        return WorkshopForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WorkshopsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkshops::route('/'),
            'create' => CreateWorkshop::route('/create'),
            'edit' => EditWorkshop::route('/{record}/edit'),
        ];
    }
}
