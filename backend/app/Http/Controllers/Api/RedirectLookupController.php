<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RedirectLookupController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = $this->query($request, ['path' => ['required', 'string', 'min:1', 'max:500', 'regex:/^\//']]);
        $row = DB::table('redirects')->where('from_path', $q['path'])->first();

        if (! $row) {
            return $this->notFound();
        }

        DB::table('redirects')->where('id', $row->id)->update(['hits' => DB::raw('hits + 1'), 'last_hit_at' => DB::raw('now()')]);

        if ((int) $row->status_code === 410) {
            return $this->error(410, 'gone', 'هذا العنوان حُذف.');
        }

        return response()->json(['to' => $row->to_path], (int) $row->status_code)->header('Location', $row->to_path);
    }
}
