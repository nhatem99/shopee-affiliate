<?php

namespace App\Http\Middleware;

use App\Services\TrackingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function __construct(private TrackingService $tracking) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check() || ! auth()->user()->isAdmin()) {
            $this->tracking->logSecurityEvent('admin_access_denied', $request, [
                'metadata' => ['path' => $request->path(), 'user_id' => auth()->id()],
            ]);

            abort(403, 'Bạn không có quyền truy cập trang này.');
        }

        return $next($request);
    }
}
