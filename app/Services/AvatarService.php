<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ảnh đại diện của khách: lưu ở /profile/thong-tin, hiện ở bảng vàng hoàn tiền và các chỗ
 * đang vẽ chữ cái đầu của tên (sidebar Tài khoản, menu trượt, đầu trang Tổng quan).
 *
 * Ảnh này là thứ CÔNG KHAI trước người lạ — bảng vàng ai vào trang chủ cũng thấy — nên hai
 * việc dưới đây không phải tối ưu vặt mà là điều kiện để tính năng này không gây hại:
 *
 *  1. ẢNH ĐƯỢC MÃ HOÁ LẠI, không lưu nguyên file khách gửi. Ảnh chụp từ điện thoại mang theo
 *     EXIF: toạ độ GPS nơi chụp, model máy, giờ chụp. Đăng nguyên file đó ra trang công khai là
 *     phát tán vị trí của khách mà họ không hề biết. Vẽ lại bằng GD thì mọi metadata rụng hết.
 *  2. CẮT VUÔNG VÀ THU NHỎ CÒN 256px. Bảng vàng hiện tới 10 avatar cùng lúc, mỗi ô rộng chưa
 *     tới 96px; để nguyên 10 tấm ảnh 4MB thì trang chủ — trang đầu tiên khách mới nhìn thấy —
 *     tải nặng hơn cả phần nội dung.
 *
 * Không có GD thì vẫn lưu được (fallback ở store()), chỉ là giữ nguyên file gốc: thà avatar
 * nặng còn hơn khách bấm lưu mà không hiểu vì sao máy báo lỗi.
 */
class AvatarService
{
    /** Cùng trần với ảnh chat — khách chụp màn hình/ảnh điện thoại thường dưới mức này. */
    public const MAX_IMAGE_KB = 5120;

    /** Cạnh ảnh sau khi cắt vuông. 256 đủ nét cho ô lớn nhất (96px) trên màn hình Retina. */
    public const SIZE = 256;

    private const JPEG_QUALITY = 82;

    /** Ảnh cũ bị xoá ngay khi có ảnh mới — không để lại file mồ côi chiếm ổ đĩa mãi mãi. */
    public function store(User $user, UploadedFile $file): void
    {
        $binary = $this->square($file);

        // Tên file ngẫu nhiên, KHÔNG dùng tên khách đặt: tên file của khách không bao giờ được
        // thành tên file trên server (cùng lý do với ảnh chat, xem ChatService::storeImage).
        // Chia thư mục theo tháng để một thư mục không phình ra hàng chục nghìn file.
        $path = $binary !== null
            ? 'avatars/'.now()->format('Y/m').'/'.Str::random(40).'.jpg'
            : null;

        $this->deleteFile($user);

        if ($path !== null) {
            Storage::disk('uploads')->put($path, $binary);
        } else {
            $path = $file->store('avatars/'.now()->format('Y/m'), 'uploads');
        }

        // forceFill chứ không update(): avatar_path cố ý không nằm trong #[Fillable] của User —
        // đây là đường dẫn file trên server, chỉ service này được đặt, không bao giờ nhận
        // thẳng từ request.
        $user->forceFill(['avatar_path' => $path])->save();

        // Bảng vàng cache 10 phút. Không xoá cache thì khách vừa đổi ảnh xong vào xem vẫn thấy
        // chữ cái cũ và tưởng là tải hỏng.
        CashbackLeaderboardService::forget();
    }

    public function remove(User $user): void
    {
        $this->deleteFile($user);

        $user->forceFill(['avatar_path' => null])->save();

        CashbackLeaderboardService::forget();
    }

    private function deleteFile(User $user): void
    {
        if ($user->avatar_path) {
            Storage::disk('uploads')->delete($user->avatar_path);
        }
    }

    /**
     * Cắt vuông từ giữa ảnh + thu nhỏ + mã hoá lại thành JPEG. Trả về null nếu máy chủ không
     * xử lý được (thiếu GD, hoặc file không phải ảnh GD đọc nổi) để store() lưu nguyên file.
     */
    private function square(UploadedFile $file): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            return null;
        }

        $source = @imagecreatefromstring((string) file_get_contents($path));

        if ($source === false) {
            return null;
        }

        $source = $this->applyExifOrientation($source, $path);

        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);

        // Ảnh nhỏ hơn 256 thì giữ nguyên cỡ — phóng to chỉ làm ảnh mờ mà file lại nặng thêm.
        $target = min(self::SIZE, $side);

        $canvas = imagecreatetruecolor($target, $target);

        // JPEG không có kênh trong suốt: nền PNG/WebP trong suốt phải được lấp trước, không thì
        // vùng đó ra màu đen. Nền trắng hợp với cả giao diện sáng lẫn tối trong khung tròn.
        imagefilledrectangle($canvas, 0, 0, $target, $target, imagecolorallocate($canvas, 255, 255, 255));

        imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            intdiv($width - $side, 2),
            intdiv($height - $side, 2),
            $target,
            $target,
            $side,
            $side,
        );

        ob_start();
        imagejpeg($canvas, null, self::JPEG_QUALITY);
        $binary = (string) ob_get_clean();

        imagedestroy($canvas);
        imagedestroy($source);

        return $binary !== '' ? $binary : null;
    }

    /**
     * Xoay ảnh theo cờ EXIF trước khi vẽ lại.
     *
     * Ảnh dọc chụp bằng điện thoại thường được lưu NẰM NGANG kèm cờ "xoay 90°"; trình duyệt tự
     * hiểu cờ đó nên khách thấy ảnh đúng chiều lúc chọn file. Vẽ lại bằng GD thì cờ mất, và
     * avatar hiện lên nằm nghiêng — khách sẽ tưởng web làm hỏng ảnh của họ.
     *
     * @param  \GdImage  $image
     * @return \GdImage
     */
    private function applyExifOrientation($image, string $path)
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 0);

        $degrees = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($degrees === 0) {
            return $image;
        }

        $rotated = @imagerotate($image, $degrees, 0);

        if ($rotated === false) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }
}
