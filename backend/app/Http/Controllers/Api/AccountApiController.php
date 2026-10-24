<?php

namespace App\Http\Controllers\Api;

use App\Accounts\AccountRefused;
use App\Accounts\CustomerAccounts;
use App\Accounts\CustomerLibrary;
use App\Accounts\CustomerSessions;
use App\Api\SiteToken;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer accounts (decision 17), behind `feature:customer_accounts`: while it is off every route is a 404. From the
 * site's server only, JSON only. Signed-in routes carry the session as `Authorization: Bearer` (RequireCustomer).
 */
class AccountApiController extends ApiController
{
    public function register(Request $request, CustomerAccounts $accounts): JsonResponse
    {
        return $this->visitor($request, fn (string $ip) => ['challenge' => $accounts->register($request->json()->all(), $ip)], 202);
    }

    public function resend(Request $request, CustomerAccounts $accounts): JsonResponse
    {
        return $this->visitor($request, fn (string $ip) => ['challenge' => $accounts->resendRegistration((string) $request->json('challenge'), $ip)], 202);
    }

    public function confirm(Request $request, CustomerAccounts $accounts): JsonResponse
    {
        return $this->visitor($request, fn (string $ip) => ['token' => $accounts->confirmRegistration((string) $request->json('challenge'), (string) $request->json('code'), $ip)], 201);
    }

    public function login(Request $request, CustomerAccounts $accounts): JsonResponse
    {
        return $this->visitor($request, fn (string $ip) => ['token' => $accounts->login($request->json('email'), $request->json('password'), $ip)]);
    }

    public function forgot(Request $request, CustomerAccounts $accounts): JsonResponse
    {
        return $this->visitor($request, fn (string $ip) => ['challenge' => $accounts->forgotPassword($request->json()->all(), $ip)], 202);
    }

    public function reset(Request $request, CustomerAccounts $accounts): JsonResponse
    {
        return $this->visitor($request, function (string $ip) use ($request, $accounts) {
            $accounts->resetPassword((string) $request->json('challenge'), (string) $request->json('code'), $request->json('password'), $ip);

            return ['reset' => true];
        });
    }

    public function logout(Request $request, CustomerSessions $sessions): JsonResponse
    {
        $sessions->close($request->bearerToken());

        return response()->json(['data' => ['signed_out' => true]]);
    }

    public function profile(Request $request, CustomerAccounts $accounts): JsonResponse
    {
        return response()->json(['data' => $accounts->profile($this->customer($request))]);
    }

    public function updateProfile(Request $request, CustomerAccounts $accounts): JsonResponse
    {
        return $this->act(function () use ($request, $accounts) {
            $accounts->updateProfile($this->customer($request), $request->json()->all());

            return $accounts->profile($this->customer($request)->fresh());
        });
    }

    public function password(Request $request, CustomerAccounts $accounts): JsonResponse
    {
        return $this->act(fn () => ['token' => $accounts->changePassword($this->customer($request), $request->json('current_password'), $request->json('password'))]);
    }

    public function destroy(Request $request, CustomerAccounts $accounts): JsonResponse
    {
        return $this->act(function () use ($request, $accounts) {
            $accounts->delete($this->customer($request), $request->json('password'));

            return ['deleted' => true];
        });
    }

    public function saved(Request $request, CustomerLibrary $library): JsonResponse
    {
        return response()->json(['data' => $library->saved($this->customer($request))]);
    }

    public function save(Request $request, int $post, CustomerLibrary $library): JsonResponse
    {
        return $this->act(function () use ($request, $post, $library) {
            $library->save($this->customer($request), $post);

            return ['saved' => true];
        });
    }

    public function unsave(Request $request, int $post, CustomerLibrary $library): JsonResponse
    {
        $library->unsave($this->customer($request), $post);

        return response()->json(['data' => ['saved' => false]]);
    }

    public function follows(Request $request, CustomerLibrary $library): JsonResponse
    {
        return response()->json(['data' => $library->follows($this->customer($request))]);
    }

    public function follow(Request $request, string $kind, int $id, CustomerLibrary $library): JsonResponse
    {
        return $this->act(function () use ($request, $kind, $id, $library) {
            $library->follow($this->customer($request), $kind, $id);

            return ['following' => true];
        });
    }

    public function unfollow(Request $request, string $kind, int $id, CustomerLibrary $library): JsonResponse
    {
        $library->unfollow($this->customer($request), $kind, $id);

        return response()->json(['data' => ['following' => false]]);
    }

    public function bookings(Request $request, CustomerLibrary $library): JsonResponse
    {
        return response()->json(['data' => $library->bookings($this->customer($request))]);
    }

    private function customer(Request $request): User
    {
        return $request->attributes->get('customer');
    }

    /** A route of a visitor who is not signed in: site only, JSON only. @param Closure(string): array<string, mixed> $work */
    private function visitor(Request $request, Closure $work, int $status = 200): JsonResponse
    {
        if (! $request->isJson()) {
            return $this->error(415, 'unsupported_media_type', 'أرسل الطلب بصيغة JSON.');
        }
        if (($ip = SiteToken::visitor($request)) === null) {
            return $this->error(403, 'site_only', 'هذا الطلب يُرسَل من الموقع فقط.');
        }

        return $this->act(fn () => $work($ip), $status);
    }

    /** @param Closure(): array<string, mixed> $work */
    private function act(Closure $work, int $status = 200): JsonResponse
    {
        try {
            $data = $work();
        } catch (AccountRefused $e) {
            return $this->error($e->status, $e->reason, $e->getMessage())->withHeaders(['Cache-Control' => 'no-store']);
        }

        return response()->json(['data' => $data], $status)->withHeaders(['Cache-Control' => 'no-store']);
    }
}
