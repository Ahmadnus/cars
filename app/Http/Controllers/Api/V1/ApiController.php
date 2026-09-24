<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Shared response shape for the whole API.
 *
 * Every endpoint answers with the same envelope — success, message, data, meta
 * — so the Flutter clients can parse a response without knowing which endpoint
 * produced it. Errors use the same shape, built centrally in bootstrap/app.php.
 */
abstract class ApiController extends Controller
{
    protected function ok(mixed $data = null, ?string $message = null, array $meta = []): JsonResponse
    {
        return $this->respond(true, $message ?? 'تم تنفيذ العملية بنجاح.', $data, $meta);
    }

    protected function created(mixed $data = null, ?string $message = null): JsonResponse
    {
        return $this->respond(true, $message ?? 'تم الإنشاء بنجاح.', $data, [], 201);
    }

    protected function failed(string $message, array $errors = [], int $status = 422): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => (object) $errors,
        ], $status);
    }

    /**
     * Wrap a paginated result, lifting the pagination details into `meta` so
     * `data` is always a plain array the client can map over.
     */
    protected function paginated(LengthAwarePaginator $paginator, string $resource, ?string $message = null): JsonResponse
    {
        return $this->respond(
            true,
            $message ?? 'تم جلب البيانات بنجاح.',
            $resource::collection($paginator->getCollection()),
            [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'has_more' => $paginator->hasMorePages(),
            ],
        );
    }

    protected function respond(bool $success, string $message, mixed $data, array $meta, int $status = 200): JsonResponse
    {
        $payload = [
            'success' => $success,
            'message' => $message,
            'data' => $this->normalise($data),
        ];

        $payload['meta'] = (object) $meta;

        return response()->json($payload, $status);
    }

    /** Resolve resources to arrays so the envelope never double-wraps `data`. */
    protected function normalise(mixed $data): mixed
    {
        if ($data instanceof JsonResource || $data instanceof ResourceCollection) {
            return $data->resolve(request());
        }

        if ($data instanceof \Illuminate\Support\Collection) {
            return $data->values()->all();
        }

        return $data ?? (object) [];
    }

    /** Page size, clamped so a client cannot ask for the whole table. */
    protected function perPage(int $default = 20, int $max = 100): int
    {
        return min(max((int) request()->integer('per_page', $default), 1), $max);
    }
}
