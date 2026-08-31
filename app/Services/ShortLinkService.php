<?php

namespace App\Services;

use App\Models\ShortLink;
use Illuminate\Support\Str;

class ShortLinkService
{
    public function create(string $targetUrl, ?string $source = null, ?string $productName = null, ?string $productImage = null): ShortLink
    {
        do {
            $code = Str::random(7);
        } while (ShortLink::where('code', $code)->exists());

        return ShortLink::create([
            'code' => $code,
            'target_url' => $targetUrl,
            'source' => $source,
            'product_name' => $productName,
            'product_image' => $productImage,
        ]);
    }

    public function find(string $code): ?ShortLink
    {
        return ShortLink::where('code', $code)->first();
    }

    public function trackClick(ShortLink $link): void
    {
        $link->increment('clicks');
    }
}
