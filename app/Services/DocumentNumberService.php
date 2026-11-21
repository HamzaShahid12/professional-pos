<?php

namespace App\Services;

use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Gap-free document numbers per business and document type (ADJ-000001, TRF-000001, later INV-000001 ...).
 *
 * MUST be called inside the transaction that saves the document: the sequence row stays locked until that
 * transaction ends, so a rolled-back document does not burn a number and two requests never share one.
 */
class DocumentNumberService
{
    public function __construct(private TenantContext $tenant) {}

    public function next(string $type, string $prefix): string
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Document numbers must be allocated inside a database transaction.');
        }

        $businessId = $this->tenant->businessId();
        if (! $businessId) {
            throw new LogicException('Document numbers need a business context.');
        }

        DB::table('document_sequences')->insertOrIgnore([
            'business_id' => $businessId, 'doc_type' => $type, 'last_number' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = DB::table('document_sequences')
            ->where('business_id', $businessId)->where('doc_type', $type)->lockForUpdate()->first();

        $next = ((int) $row->last_number) + 1;
        DB::table('document_sequences')->where('id', $row->id)->update(['last_number' => $next, 'updated_at' => now()]);

        return $prefix.'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
