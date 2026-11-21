<?php

namespace App\Support;

use App\Models\Business;

/**
 * Holds the business / branch the current request operates in.
 *
 * Registered as a singleton. The SetTenantContext middleware fills it for every
 * authenticated request after validating that the user really belongs to the business.
 * Business-owned models are filtered by it (see BusinessScope) and FAIL CLOSED when it is empty.
 */
class TenantContext
{
    private ?int $businessId = null;

    private ?int $branchId = null;

    private bool $unscoped = false;

    private ?Business $business = null;

    public function set(?int $businessId, ?int $branchId = null): void
    {
        if ($businessId !== $this->businessId) {
            $this->business = null;
        }
        $this->businessId = $businessId;
        $this->branchId = $branchId;
    }

    public function setBranch(?int $branchId): void
    {
        $this->branchId = $branchId;
    }

    public function businessId(): ?int
    {
        return $this->businessId;
    }

    public function branchId(): ?int
    {
        return $this->branchId;
    }

    public function business(): ?Business
    {
        if (! $this->businessId) {
            return null;
        }

        return $this->business ??= Business::query()->find($this->businessId);
    }

    public function forget(): void
    {
        $this->businessId = null;
        $this->branchId = null;
        $this->business = null;
    }

    public function isUnscoped(): bool
    {
        return $this->unscoped;
    }

    /** Run a callback inside a specific business (seeders, queued jobs, services). */
    public function runAs(int $businessId, callable $callback, ?int $branchId = null): mixed
    {
        $previous = [$this->businessId, $this->branchId, $this->business];
        $this->set($businessId, $branchId);

        try {
            return $callback();
        } finally {
            [$this->businessId, $this->branchId, $this->business] = $previous;
        }
    }

    /** Explicitly bypass tenant filtering (super-admin tooling, migrations). Use sparingly. */
    public function withoutScope(callable $callback): mixed
    {
        $previous = $this->unscoped;
        $this->unscoped = true;

        try {
            return $callback();
        } finally {
            $this->unscoped = $previous;
        }
    }
}
