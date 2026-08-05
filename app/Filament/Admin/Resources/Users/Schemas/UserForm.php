<?php

namespace App\Filament\Admin\Resources\Users\Schemas;

use App\Enums\UserRole;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('氏名')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('メールアドレス')
                    ->required()
                    ->email()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Select::make('role')
                    ->label('権限')
                    ->options(collect(UserRole::cases())->mapWithKeys(
                        fn (UserRole $role) => [$role->value => $role->label()]
                    ))
                    ->required(),
                TextInput::make('password')
                    ->label('パスワード')
                    ->password()
                    ->maxLength(255)
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrateStateUsing(fn (?string $state) => filled($state) ? Hash::make($state) : null)
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->helperText('編集時は、変更する場合のみ入力してください'),
            ]);
    }
}
