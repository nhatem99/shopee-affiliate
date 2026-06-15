<?php

namespace App\Http\Controllers;

use App\Exceptions\AffiliateScanException;
use App\Models\AffiliateLink;
use App\Services\AffiliateScanOrchestrator;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AffiliateController extends Controller
{
    public function __construct(private AffiliateScanOrchestrator $orchestrator) {}

    public function scan(Request $request): Response|\Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'url' => ['required', 'url', 'max:2000'],
        ]);

        try {
            $result = $this->orchestrator->scan(
                $request->input('url'),
                $request->user()
            );

            return Inertia::render('Result', $result);
        } catch (AffiliateScanException $e) {
            return back()->withErrors(['url' => $e->getMessage()]);
        }
    }

    public function history(Request $request): Response
    {
        $links = AffiliateLink::where('user_id', $request->user()->id)
            ->with('vouchers')
            ->latest()
            ->paginate(20);

        return Inertia::render('History', [
            'links' => $links,
        ]);
    }
}
