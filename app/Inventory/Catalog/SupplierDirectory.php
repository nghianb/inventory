<?php

namespace App\Inventory\Catalog;

use App\Inventory\Access\MissingRole;
use App\Inventory\Access\Role;
use App\Inventory\Access\RoleGate;
use App\Models\Batch;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Danh sách Nhà cung cấp: Quản trị và Nhập kho quản lý; Bán hàng không thấy.
 * Tên là duy nhất, không phân biệt hoa thường.
 */
class SupplierDirectory
{
    public function __construct(private RoleGate $roles) {}

    /**
     * @throws MissingRole
     * @throws InvalidSupplier
     */
    public function create(User $actor, string $name, ?string $note = null): Supplier
    {
        $this->roles->authorize($actor, Role::NhapKho);

        return self::save(new Supplier, $name, $note);
    }

    /**
     * @throws MissingRole
     * @throws InvalidSupplier
     */
    public function update(User $actor, Supplier $supplier, string $name, ?string $note): Supplier
    {
        $this->roles->authorize($actor, Role::NhapKho);

        return self::save($supplier, $name, $note);
    }

    /**
     * @throws MissingRole
     */
    public function delete(User $actor, Supplier $supplier): void
    {
        $this->roles->authorize($actor, Role::NhapKho);

        if (Batch::query()->where('supplier_id', $supplier->getKey())->exists()) {
            throw new InvalidSupplier("Nhà cung cấp \"{$supplier->name}\" đã có Lô nhập, không xoá được.");
        }

        $supplier->delete();
    }

    /**
     * @throws InvalidSupplier
     */
    private static function save(Supplier $supplier, string $name, ?string $note): Supplier
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidSupplier('Tên Nhà cung cấp không được để trống.');
        }

        $taken = Supplier::query()
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->when($supplier->exists, fn ($query) => $query->whereKeyNot($supplier->getKey()))
            ->exists();

        if ($taken) {
            throw self::nameTaken($name);
        }

        try {
            $supplier->forceFill(['name' => $name, 'note' => $note])->save();
        } catch (UniqueConstraintViolationException) {
            // Hai người cùng tạo một tên: unique index trên lower(name) bắt trường hợp đồng thời.
            throw self::nameTaken($name);
        }

        return $supplier;
    }

    private static function nameTaken(string $name): InvalidSupplier
    {
        return new InvalidSupplier("Nhà cung cấp \"{$name}\" đã có.");
    }
}
