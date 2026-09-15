<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Nhà cung cấp: bên bán hàng số cho shop. Chỉ tạo và sửa qua SupplierDirectory.
 *
 * @property int $id
 * @property string $name
 * @property ?string $note
 */
class Supplier extends Model {}
