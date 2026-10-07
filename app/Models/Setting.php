<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use WeakMap;

#[Fillable(['key', 'value'])]
class Setting extends Model
{
    /** @var WeakMap<Request, array<string, mixed>>|null */
    private static ?WeakMap $loaded = null;

    protected static function booted(): void
    {
        // Ghi xong thì bỏ bản đã nạp: đọc lại ngay trong cùng request phải thấy giá trị mới.
        static::saved(fn () => self::$loaded = null);
        static::deleted(fn () => self::$loaded = null);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = static::loadedForRequest();
        $value = $all !== null ? ($all[$key] ?? null) : static::where('key', $key)->value('value');

        return $value === null ? $default : $value;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function getBool(string $key, bool $default = true): bool
    {
        $value = static::get($key, $default ? '1' : '0');

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Cả bảng settings, nạp MỘT lần cho mỗi request HTTP. Một lượt tải trang chủ đọc ~17 cờ
     * (HandleInertiaRequests, MaintenanceMode, các service của trang) — trước đây là ~17 query
     * riêng lẻ, hơn nửa số query của cả trang. Bảng chỉ vài chục dòng nên nạp hết rẻ hơn.
     *
     * null = không nạp sẵn, đọc thẳng DB từng lần: lệnh artisan và queue worker là tiến trình chạy
     * dài (zalo:group-listen chạy cả ngày), giữ bản nạp ở đó thì admin đổi cài đặt mà tiến trình
     * không bao giờ thấy.
     *
     * @return array<string, mixed>|null
     */
    private static function loadedForRequest(): ?array
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }

        // Gắn theo đối tượng Request chứ không giữ một mảng tĩnh trơn: test gửi nhiều request
        // trong cùng một tiến trình, request nào cũng phải tự đọc lại như ở production.
        self::$loaded ??= new WeakMap;

        return self::$loaded[request()] ??= static::pluck('value', 'key')->all();
    }
}
