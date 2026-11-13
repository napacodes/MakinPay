<?php

namespace App\Support;

use App\Enums\Api\ApiErrorCode;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * The one shape every mobile API reply takes.
 *
 * Success is `{"data": ...}` and failure is `{"message", "code", "errors"}`,
 * with `code` drawn from ApiErrorCode. A client that has to guess which of
 * several shapes it received ends up parsing messages, and a parsed message is
 * a contract nobody agreed to.
 *
 * Pagination is reported under `meta.pagination` rather than at the top level
 * so `data` means the same thing - the payload - whether the endpoint returns
 * one record or a page of them.
 */
final class ApiResponse
{
    public static function ok(mixed $data = null, int $status = 200, array $headers = []): JsonResponse
    {
        return response()->json(['data' => $data], $status, $headers);
    }

    public static function created(mixed $data = null, array $headers = []): JsonResponse
    {
        return self::ok($data, 201, $headers);
    }

    /**
     * An accepted-but-not-finished write, such as a transfer handed to a queue.
     */
    public static function accepted(mixed $data = null, array $headers = []): JsonResponse
    {
        return self::ok($data, 202, $headers);
    }

    public static function noContent(array $headers = []): JsonResponse
    {
        return response()->json(null, 204, $headers);
    }

    /**
     * A page of records, with the cursor or page links the app needs to ask
     * for the next one.
     */
    public static function paginated(
        Paginator|CursorPaginator|ResourceCollection $paginator,
        int $status = 200,
        array $headers = [],
    ): JsonResponse {
        $source = $paginator instanceof ResourceCollection ? $paginator->resource : $paginator;

        $items = $paginator instanceof ResourceCollection
            ? $paginator->resolve()
            : $paginator->items();

        return response()->json([
            'data' => $items,
            'meta' => ['pagination' => self::paginationMeta($source)],
        ], $status, $headers);
    }

    /**
     * @param array<string, list<string>> $errors
     * @param array<string, mixed>        $extra  Keys the client needs in order
     *                                            to act on this refusal - a
     *                                            two-factor challenge, say.
     *                                            Never a substitute for the code.
     */
    public static function error(
        ApiErrorCode $code,
        ?string $message = null,
        array $errors = [],
        ?int $status = null,
        array $headers = [],
        array $extra = [],
    ): JsonResponse {
        $body = [
            'message' => $message ?? $code->defaultMessage(),
            'code'    => $code->value,
        ];

        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        // Union rather than array_merge: a caller must not be able to overwrite
        // the code or the message by naming them here.
        $body += $extra;

        return response()->json($body, $status ?? $code->status(), $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private static function paginationMeta(mixed $paginator): array
    {
        if ($paginator instanceof CursorPaginator) {
            return [
                'per_page'        => $paginator->perPage(),
                'next_cursor'     => $paginator->nextCursor()?->encode(),
                'previous_cursor' => $paginator->previousCursor()?->encode(),
                'has_more'        => $paginator->hasMorePages(),
            ];
        }

        $meta = [
            'per_page'     => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'has_more'     => $paginator->hasMorePages(),
        ];

        // Only a length-aware paginator knows the totals; a simple paginator
        // deliberately does not count, and inventing a total here would make
        // the cheaper query look like the expensive one.
        if ($paginator instanceof LengthAwarePaginator) {
            $meta['total']     = $paginator->total();
            $meta['last_page'] = $paginator->lastPage();
        }

        return $meta;
    }
}
