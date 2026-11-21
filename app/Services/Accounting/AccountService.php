<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountService
{
    public function create(array $data): Account
    {
        $this->assertParent($data['parent_id'] ?? null, $data['type'], null);
        $account = Account::create([
            'code' => $data['code'], 'name' => $data['name'], 'type' => $data['type'], 'parent_id' => $data['parent_id'] ?? null,
            'description' => $data['description'] ?? null, 'is_system' => false, 'is_active' => $data['is_active'] ?? true,
        ]);
        ActivityLog::record('account.created', $account, ['code' => $account->code]);

        return $account;
    }

    public function update(Account $account, array $data): Account
    {
        $hasLines = $account->lines()->exists();
        if ($data['type'] !== $account->type && ($account->is_system || $hasLines)) {
            throw ValidationException::withMessages(['type' => $account->is_system ? 'The type of a system account cannot change.' : 'An account with postings cannot change type.']);
        }
        if ($account->is_system && array_key_exists('is_active', $data) && ! $data['is_active']) {
            throw ValidationException::withMessages(['is_active' => 'System accounts are used by automatic postings and cannot be deactivated.']);
        }
        $this->assertParent($data['parent_id'] ?? null, $data['type'], $account);

        $account->update([
            'code' => $data['code'], 'name' => $data['name'], 'type' => $data['type'], 'parent_id' => $data['parent_id'] ?? null,
            'description' => $data['description'] ?? null, 'is_active' => $data['is_active'] ?? $account->is_active,
        ]);
        ActivityLog::record('account.updated', $account, ['code' => $account->code]);

        return $account;
    }

    public function delete(Account $account): void
    {
        if ($account->is_system) {
            throw ValidationException::withMessages(['account' => 'System accounts cannot be deleted.']);
        }
        if ($account->lines()->exists()) {
            throw ValidationException::withMessages(['account' => 'This account has postings. Deactivate it instead.']);
        }
        if (Account::query()->where('parent_id', $account->id)->exists()) {
            throw ValidationException::withMessages(['account' => 'Move or delete its sub-accounts first.']);
        }
        DB::transaction(function () use ($account) {
            ActivityLog::record('account.deleted', $account, ['code' => $account->code]);
            $account->delete();
        });
    }

    /** Groups are one level deep and share the type of their parent. */
    private function assertParent(?int $parentId, string $type, ?Account $self): void
    {
        if (! $parentId) {
            return;
        }
        $parent = Account::query()->find($parentId);
        if (! $parent || ($self && $parent->id === $self->id) || $parent->parent_id) {
            throw ValidationException::withMessages(['parent_id' => 'Choose a top-level account as the group.']);
        }
        if ($parent->type !== $type) {
            throw ValidationException::withMessages(['parent_id' => 'A sub-account must have the same type as its group.']);
        }
        if ($self && Account::query()->where('parent_id', $self->id)->exists()) {
            throw ValidationException::withMessages(['parent_id' => 'This account has sub-accounts, so it cannot be placed under another.']);
        }
    }
}
