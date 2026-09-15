<?php

namespace App\Http\Middleware;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;

/**
 * Cắt phiên đang mở của nhân viên vừa bị Khoá nhân viên: đăng xuất và huỷ phiên
 * rồi đưa về trang đăng nhập, thay vì để Filament trả 403 cho phiên còn sống.
 * Nằm trong Authenticate vì Laravel luôn xếp middleware xác thực lên trước
 * middleware thường của panel.
 */
class Authenticate extends FilamentAuthenticate
{
    /**
     * @param  array<string>  $guards
     */
    protected function authenticate($request, array $guards): void
    {
        $user = Filament::auth()->user();

        if ($user instanceof User && $user->isDeactivated()) {
            Filament::auth()->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        parent::authenticate($request, $guards);
    }
}
