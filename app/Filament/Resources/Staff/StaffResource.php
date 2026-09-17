<?php

namespace App\Filament\Resources\Staff;

use App\Filament\Resources\Staff\Pages\ManageStaff;
use App\Filament\Support\NavGroup;
use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Staff\LastActiveOwner;
use App\Inventory\Staff\StaffManager;
use App\Inventory\Staff\StaffRules;
use App\Models\User;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Quản lý nhân viên trong panel. Adapter mỏng: mọi thao tác gọi StaffManager,
 * nơi kiểm tra Vai trò, chặn mất Quản trị cuối cùng và ghi Nhật ký bảo mật.
 * Không có thao tác sửa tự do hay xoá nhân viên.
 */
class StaffResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::HeThong;

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'nhân viên';

    protected static ?string $pluralModelLabel = 'Nhân viên';

    protected static ?string $navigationLabel = 'Nhân viên';

    protected static ?string $slug = 'nhan-vien';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Tên')
                ->required()
                ->maxLength(StaffRules::MAX_LENGTH),
            TextInput::make('email')
                ->label('Email')
                ->email()
                ->required()
                ->maxLength(StaffRules::MAX_LENGTH)
                ->unique(User::class, 'email'),
            TextInput::make('password')
                ->label('Mật khẩu ban đầu')
                ->password()
                ->revealable()
                ->required()
                ->rule(StaffRules::password()),
            self::rolesField(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Tên')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                TextColumn::make('roles.name')
                    ->label('Vai trò')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Role::from($state)->label()),
                IconColumn::make('two_factor')
                    ->label('2FA')
                    ->boolean()
                    ->state(fn (User $record): bool => AppAuthentication::make()->isEnabled($record)),
                TextColumn::make('deactivated_at')
                    ->label('Trạng thái')
                    ->badge()
                    ->state(fn (User $record): string => $record->isDeactivated() ? 'Đã khoá' : 'Hoạt động')
                    ->color(fn (User $record): string => $record->isDeactivated() ? 'danger' : 'success'),
            ])
            ->recordActions([
                Action::make('changeRoles')
                    ->label('Đổi Vai trò')
                    ->icon(Heroicon::OutlinedUserGroup)
                    ->fillForm(fn (User $record): array => ['roles' => $record->roles->pluck('name')->all()])
                    ->schema([self::rolesField()])
                    ->action(self::attempt(
                        fn (StaffManager $staff, User $record, array $data) => $staff->changeRoles(self::actor(), $record, self::rolesFromForm($data['roles'])),
                        'Đã đổi Vai trò.',
                    )),
                Action::make('resetTwoFactor')
                    ->label('Reset 2FA')
                    ->icon(Heroicon::OutlinedKey)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Nhân viên phải thiết lập lại 2FA ở lần vào panel kế tiếp.')
                    ->action(self::attempt(
                        fn (StaffManager $staff, User $record) => $staff->resetTwoFactor(self::actor(), $record),
                        'Đã reset 2FA.',
                    )),
                Action::make('deactivate')
                    ->label('Khoá')
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Nhân viên bị đăng xuất ngay và không đăng nhập lại được.')
                    ->visible(fn (User $record): bool => ! $record->isDeactivated())
                    ->action(self::attempt(
                        fn (StaffManager $staff, User $record) => $staff->deactivate(self::actor(), $record),
                        'Đã khoá nhân viên.',
                    )),
                Action::make('reactivate')
                    ->label('Mở khoá')
                    ->icon(Heroicon::OutlinedLockOpen)
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => $record->isDeactivated())
                    ->action(self::attempt(
                        fn (StaffManager $staff, User $record) => $staff->reactivate(self::actor(), $record),
                        'Đã mở khoá nhân viên.',
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageStaff::route('/'),
        ];
    }

    public static function rolesField(): CheckboxList
    {
        return CheckboxList::make('roles')
            ->label('Vai trò')
            ->required()
            ->options(collect(Role::cases())
                ->mapWithKeys(fn (Role $role): array => [$role->value => $role->label()])
                ->all());
    }

    /**
     * @param  array<string>  $values
     * @return list<Role>
     */
    public static function rolesFromForm(array $values): array
    {
        return array_values(array_map(fn (string $value): Role => Role::from($value), $values));
    }

    public static function actor(): User
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    /**
     * Chạy thao tác của module Kho, đổi lỗi nghiệp vụ thành thông báo cho Quản trị.
     */
    private static function attempt(Closure $operation, string $success): Closure
    {
        return function (Action $action) use ($operation, $success): void {
            try {
                $action->evaluate($operation);
            } catch (LastActiveOwner|MissingRole $exception) {
                Notification::make()->danger()->title($exception->getMessage())->send();

                return;
            }

            Notification::make()->success()->title($success)->send();
        };
    }
}
