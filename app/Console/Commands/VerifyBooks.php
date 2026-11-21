<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\Accounting\AccountingReportService;
use App\Services\Accounting\ChartOfAccounts;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Checks every business: the trial balance must balance and the control accounts must agree with the customer,
 * supplier and stock records. Exits non-zero on any difference so it can run from cron or a monitoring job.
 */
class VerifyBooks extends Command
{
    protected $signature = 'pos:verify-books {--business= : Check one business id only}';

    protected $description = 'Verify that the books balance and agree with customers, suppliers and stock';

    public function handle(TenantContext $tenant, AccountingReportService $report): int
    {
        $businesses = Business::query()->when($this->option('business'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->get();
        if ($businesses->isEmpty()) {
            $this->warn('No business to check.');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($businesses as $business) {
            $problems = $tenant->runAs($business->id, function () use ($report) {
                $problems = [];
                $tb = $report->trialBalance(null);
                if (! $tb['balanced']) {
                    $problems[] = "Trial balance is out: debit {$tb['total_debit']} vs credit {$tb['total_credit']}";
                }
                foreach ($report->reconciliation(app(ChartOfAccounts::class)) as $check) {
                    if (! $check['ok']) {
                        $problems[] = "{$check['label']}: books {$check['books']}, records {$check['records']}, difference {$check['difference']}";
                    }
                }

                return $problems;
            });

            if ($problems) {
                $failed++;
                $this->error("#{$business->id} {$business->name}: DIFFERENCES");
                foreach ($problems as $p) {
                    $this->line("  - {$p}");
                }
            } else {
                $this->info("#{$business->id} {$business->name}: OK");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
