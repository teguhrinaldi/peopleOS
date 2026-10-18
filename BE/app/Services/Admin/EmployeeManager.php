<?php

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Web-admin orchestration around EmployeeService: photo storage, sensible defaults,
 * and translation of field-level domain errors into form validation errors.
 * All lifecycle rules stay in EmployeeService.
 */
class EmployeeManager
{
    private const DISK = 'public';

    /** Domain error code => form field that should show it. */
    private const FIELD_ERRORS = ['INVALID_MANAGER' => 'manager_id', 'ASSIGNMENT_OVERLAP' => 'effective_from'];

    public function __construct(private readonly EmployeeService $employees) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, ?UploadedFile $photo, User $actor): Employee
    {
        $data['photo_path'] = $photo ? $this->storePhoto($photo) : null;
        $data = $this->withDefaultBranch($data);
        $data['effective_from'] = $data['effective_from'] ?? $data['join_date'];

        try {
            return $this->translate(fn () => $this->employees->create($data, $actor));
        } catch (Throwable $e) {
            $this->deletePhoto($data['photo_path']);
            throw $e;
        }
    }

    /** @param array<string, mixed> $data */
    public function update(Employee $employee, array $data, ?UploadedFile $photo, bool $removePhoto, User $actor): Employee
    {
        $old = $employee->photo_path;
        $new = $photo ? $this->storePhoto($photo) : null;

        if ($new) {
            $data['photo_path'] = $new;
        } elseif ($removePhoto) {
            $data['photo_path'] = null;
        }

        try {
            $employee = $this->employees->update($employee, $data, $actor);
        } catch (Throwable $e) {
            $this->deletePhoto($new);
            throw $e;
        }

        if (array_key_exists('photo_path', $data) && $old && $old !== $employee->photo_path) {
            $this->deletePhoto($old);
        }

        return $employee;
    }

    /** @param array<string, mixed> $data assignment fields + optional assignment_reason */
    public function reassign(Employee $employee, array $data, string $effectiveFrom, User $actor): void
    {
        $this->translate(fn () => $this->employees->reassign($employee, $this->withDefaultBranch($data), $effectiveFrom, $actor));
    }

    /** @param array<string, mixed> $data */
    private function withDefaultBranch(array $data): array
    {
        if (empty($data['branch_id']) && ! empty($data['department_id'])) {
            $data['branch_id'] = Department::query()->whereKey($data['department_id'])->value('branch_id');
        }

        return $data;
    }

    private function translate(callable $work): mixed
    {
        try {
            return $work();
        } catch (ApiException $e) {
            if ($field = self::FIELD_ERRORS[$e->errorCode] ?? null) {
                throw ValidationException::withMessages([$field => $e->getMessage()]);
            }
            throw $e;
        }
    }

    private function storePhoto(UploadedFile $file): string
    {
        // store() names the file with a random hash, never the client-supplied name.
        return (string) $file->store('employees', self::DISK);
    }

    private function deletePhoto(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
