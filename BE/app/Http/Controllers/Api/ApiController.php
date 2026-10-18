<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class ApiController extends Controller
{
    use AuthorizesRequests;

    /** Consistent success envelope: {"success":true,"data":...,"meta":...} */
    protected function ok(mixed $data = null, int $status = 200, array $meta = []): JsonResponse
    {
        $body = ['success' => true, 'data' => $data];
        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $status);
    }

    /** @param callable(mixed): mixed $map */
    protected function paginated(LengthAwarePaginator $page, callable $map): JsonResponse
    {
        return $this->ok(
            collect($page->items())->map($map)->values(),
            200,
            [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        );
    }

    /** The signed-in user's employee profile, or a clear error for accounts that have none. */
    protected function employee(Request $request): Employee
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            throw ApiException::make('NO_EMPLOYEE_PROFILE', 'This account is not linked to an employee profile.', 403);
        }

        return $employee;
    }

    protected function perPage(Request $request, int $default = 20): int
    {
        return max(1, min(100, (int) $request->query('per_page', $default)));
    }
}
