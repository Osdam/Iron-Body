<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppAd;
use App\Models\AppAdView;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Publicidad para el miembro autenticado (Bloque 4). Devuelve solo los anuncios
 * vigentes que TOCA mostrar según la frecuencia (once/daily/always) y lo ya
 * visto. La app muestra el primero (mayor prioridad) como modal premium en Home.
 */
class AppAdController extends Controller
{
    public function active(Request $request): JsonResponse
    {
        $member = $this->member($request);
        if (! $member) {
            return response()->json(['ok' => true, 'data' => []]);
        }

        $ads = AppAd::active()
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get()
            ->filter(fn (AppAd $ad) => $ad->shouldShowTo($member->id))
            ->values()
            ->map(fn (AppAd $ad) => $ad->toAppArray());

        return response()->json(['ok' => true, 'data' => $ads]);
    }

    public function seen(Request $request, AppAd $ad): JsonResponse
    {
        $member = $this->member($request);
        if ($member) {
            AppAdView::updateOrCreate(
                ['app_ad_id' => $ad->id, 'member_id' => $member->id],
                ['seen_at' => now()],
            );
        }
        return response()->json(['ok' => true]);
    }

    private function member(Request $request): ?Member
    {
        return $request->attributes->get('auth_member');
    }
}
