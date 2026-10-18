<?php

namespace App\Http\Controllers\Api;

use App\Api\Presenter;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

abstract class ApiController extends Controller
{
    public const PER_PAGE = 12;

    public function __construct(protected Presenter $presenter) {}

    protected function error(int $status, string $code, string $message, array $details = []): JsonResponse
    {
        return response()->json(['error' => array_filter(['code' => $code, 'message' => $message, 'details' => $details ?: null])], $status);
    }

    protected function notFound(): JsonResponse
    {
        return $this->error(404, 'not_found', 'غير موجود.');
    }

    /** @param  array<string, mixed>  $rules */
    protected function query(Request $request, array $rules): array
    {
        $validator = Validator::make($request->query(), $rules + ['page' => ['nullable', 'integer', 'min:1', 'max:10000']]);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    /** @return array{page: int} */
    protected function page(Request $request): int
    {
        return (int) ($this->query($request, [])['page'] ?? 1);
    }

    protected function pageOf(LengthAwarePaginator $p, array $data, array $keep = []): JsonResponse
    {
        $link = fn (int $page) => '?'.http_build_query($keep + ['page' => $page]);

        return response()->json([
            'data' => $data,
            'meta' => ['page' => $p->currentPage(), 'per_page' => self::PER_PAGE, 'total' => $p->total(), 'last_page' => max(1, $p->lastPage())],
            'links' => ['next' => $p->hasMorePages() ? $link($p->currentPage() + 1) : null, 'prev' => $p->currentPage() > 1 ? $link($p->currentPage() - 1) : null],
        ]);
    }
}
