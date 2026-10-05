<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FacebookProfile;
use App\Services\FacebookGroupRunnerSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Các page bot dùng để đăng nhóm (mục "Page đăng bài" ở /admin/fb-groups): thêm Trang, đổi thứ
 * tự, trần bài/ngày, bật/tắt, mở lại page Facebook đã chặn. Nick chính luôn có, không xoá được.
 */
class FacebookProfileController extends Controller
{
    /**
     * Thêm một Trang nick chính quản lý. Page chưa có nhóm nào — xin bot lấy nhóm luôn (bot chuyển
     * sang từng page, mở "Nhóm của bạn"), xong thì page mới được giao bài.
     */
    public function store(Request $request, FacebookGroupRunnerSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:500'],
            'name' => ['nullable', 'string', 'max:255'],
            'max_per_day' => ['required', 'integer', 'min:1', 'max:200'],
        ]);

        $parsed = FacebookProfile::parseUrl($data['url']);
        if ($parsed === null) {
            return back()->withErrors(['profile_url' => 'Đây không phải link Trang Facebook (dạng facebook.com/profile.php?id=... hoặc facebook.com/tên-trang).']);
        }
        $duplicate = FacebookProfile::where('url', $parsed['url'])
            ->when($parsed['fb_id'], fn ($query) => $query->orWhere('fb_id', $parsed['fb_id']))
            ->exists();
        if ($duplicate || ($parsed['fb_id'] !== null && $parsed['fb_id'] === $settings->runnerStatus()['account_id'])) {
            return back()->withErrors(['profile_url' => 'Page này đã có trong danh sách.']);
        }

        FacebookProfile::create([
            'fb_id' => $parsed['fb_id'],
            'url' => $parsed['url'],
            'name' => ($data['name'] ?? null) ?: null,
            'max_per_day' => $data['max_per_day'],
            'position' => (int) FacebookProfile::max('position') + 1,
        ]);
        $settings->requestSync();

        return back()->with('success', 'Đã thêm page — bot sẽ chuyển sang page này lấy danh sách nhóm ở lượt hỏi tới.');
    }

    public function update(Request $request, FacebookProfile $facebookProfile): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'max_per_day' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $facebookProfile->forceFill($data)->save();

        return back();
    }

    /** Đổi chỗ với page liền trên/dưới — page đứng trước đăng trước. */
    public function move(Request $request, FacebookProfile $facebookProfile): RedirectResponse
    {
        $data = $request->validate(['direction' => ['required', 'in:up,down']]);

        DB::transaction(function () use ($facebookProfile, $data) {
            // Đánh lại số 0, 1, 2... trước, để hai page trùng position cũng đổi chỗ được.
            $ordered = FacebookProfile::ordered()->lockForUpdate()->get()->values();
            $index = $ordered->search(fn (FacebookProfile $profile) => $profile->is($facebookProfile));
            $swap = $data['direction'] === 'up' ? $index - 1 : $index + 1;
            if ($swap >= 0 && $swap < $ordered->count()) {
                $ordered = $ordered->replace([$index => $ordered[$swap], $swap => $ordered[$index]]);
            }
            $ordered->each(fn (FacebookProfile $profile, int $position) => $profile->forceFill(['position' => $position])->save());
        });

        return back();
    }

    /** Admin đã để page nghỉ đủ (thường 1–2 ngày) — cho page đăng lại. */
    public function unblock(FacebookProfile $facebookProfile): RedirectResponse
    {
        $facebookProfile->forceFill(['blocked_at' => null, 'blocked_reason' => null])->save();

        return back()->with('success', 'Đã mở lại '.$facebookProfile->label().'.');
    }

    public function destroy(FacebookProfile $facebookProfile): RedirectResponse
    {
        if ($facebookProfile->is_primary) {
            return back()->withErrors(['profile_url' => 'Nick chính không xoá được — muốn nick chính thôi đăng thì tắt đi.']);
        }

        $facebookProfile->delete();

        return back()->with('success', 'Đã xoá page.');
    }
}
