<?php

use Filament\Schemas\Schema;

/**
 * Mọi `*Resource` dưới `app/Filament/Resources`, đọc từ đĩa thay vì từ panel: không phụ thuộc thứ tự
 * boot, và resource mới nào chưa kịp đăng ký cũng không lọt. Duyệt đệ quy vì cây thư mục là quy ước
 * chứ không phải ràng buộc — resource đặt thẳng ở gốc hay lồng sâu hơn một tầng đều phải bị soát.
 */
dataset('schema gốc của resource', function (): Generator {
    $root = dirname(__DIR__, 2).'/app/Filament/Resources';

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! str_ends_with($file->getFilename(), 'Resource.php')) {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root) + 1, -4);
        /** @var class-string $resource */
        $resource = 'App\\Filament\\Resources\\'.str_replace('/', '\\', $relative);

        foreach (['form', 'infolist'] as $method) {
            // `Resource::form()` và `::infolist()` có bản mặc định trả `$schema` nguyên; chỉ soát
            // class nào tự khai, không thì mọi resource chỉ-bảng cũng bị đòi khai cột.
            if ((new ReflectionMethod($resource, $method))->getDeclaringClass()->getName() !== $resource) {
                continue;
            }

            yield "{$resource}::{$method}" => [$resource, $method];
        }
    }
});

/**
 * Mặc định thật của Filament là một cột. "Ngầm hai cột" chỉ đến từ `defaultForm`/`defaultInfolist`
 * của ViewRecord/EditRecord/CreateRecord và resolver form-trong-modal, và chỉ khi root schema không
 * tự khai `columns()` — nên thiếu `->columns(1)` là bố cục đổi im lặng, đã cắn hai lần. Quy ước của
 * kho: một cột ở gốc mọi schema resource, không trừ resource nào; nhiều cột chỉ tồn tại bên trong
 * Section/Grid/Fieldset, nơi được khai tường minh.
 *
 * Schema của custom `Page` và của modal action tự khai nằm ngoài phép đếm này: chúng không đi qua
 * bốn chỗ áp ngầm nói trên nên vốn đã một cột.
 */
it('khai một cột ở gốc mọi schema resource', function (string $resource, string $method) {
    // Schema::make() nhận null livewire nên dựng được không cần trang.
    $schema = $resource::{$method}(Schema::make());

    expect($schema->getColumns('lg'))->toBe(1);
})->with('schema gốc của resource');
