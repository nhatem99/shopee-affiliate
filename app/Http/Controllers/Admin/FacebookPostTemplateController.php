<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FacebookPostTemplate;
use App\Services\FacebookPostImages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Mẫu "bài tự soạn" ở /admin/fb-posts: lưu nội dung + ảnh đang soạn thành mẫu, sửa đè, xoá. Danh
 * sách mẫu trang nhận qua FacebookGroupPostController::index().
 */
class FacebookPostTemplateController extends Controller
{
    public function __construct(private FacebookPostImages $images) {}

    public function store(Request $request): RedirectResponse
    {
        $template = FacebookPostTemplate::create([...$this->validated($request), 'created_by' => $request->user()->id]);

        return back()->with('success', 'Đã lưu mẫu "'.$template->name.'".');
    }

    public function update(Request $request, FacebookPostTemplate $facebookPostTemplate): RedirectResponse
    {
        $facebookPostTemplate->update($this->validated($request));

        return back()->with('success', 'Đã cập nhật mẫu "'.$facebookPostTemplate->name.'".');
    }

    public function destroy(FacebookPostTemplate $facebookPostTemplate): RedirectResponse
    {
        // Không xoá file ảnh ở đây: bài đã xếp có thể đang dùng chung — FacebookPostImages::prune() dọn.
        $facebookPostTemplate->delete();

        return back()->with('success', 'Đã xoá mẫu "'.$facebookPostTemplate->name.'".');
    }

    /**
     * @return array{name: string, caption: string, images: ?list<string>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'caption' => ['required', 'string', 'max:5000', function (string $attribute, mixed $value, \Closure $fail) {
                if (str_contains((string) $value, '{link}')) {
                    $fail('Mẫu bài tự soạn không có link mua — bỏ {link} ra khỏi nội dung.');
                }
            }],
            'images' => ['nullable', 'array', 'max:'.FacebookPostImages::MAX_PER_POST],
            'images.*' => ['string', 'distinct', $this->images->existsRule()],
        ], [
            'name.required' => 'Đặt tên cho mẫu.',
            'caption.required' => 'Mẫu phải có nội dung.',
            'images.max' => 'Mỗi mẫu tối đa '.FacebookPostImages::MAX_PER_POST.' ảnh.',
        ]);

        return [
            'name' => trim($data['name']),
            'caption' => $data['caption'],
            'images' => array_values($data['images'] ?? []) ?: null,
        ];
    }
}
