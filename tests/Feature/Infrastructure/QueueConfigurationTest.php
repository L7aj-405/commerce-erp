<?php

namespace Tests\Feature\Infrastructure;

use App\Jobs\CreateScheduledOrganizationBackupJob;
use App\Jobs\SyncOrganizationBackupToPersonalCloudJob;
use App\Jobs\SyncWooCommerceProductsJob;
use Tests\TestCase;

class QueueConfigurationTest extends TestCase
{
    public function test_retry_after_exceeds_every_long_running_job_timeout(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');
        $jobs = [
            new SyncWooCommerceProductsJob(1, 1),
            new CreateScheduledOrganizationBackupJob(1),
            new SyncOrganizationBackupToPersonalCloudJob(1),
        ];

        foreach ($jobs as $job) {
            $this->assertGreaterThan($job->timeout, $retryAfter);
            $this->assertTrue($job->failOnTimeout);
            $this->assertGreaterThan($job->timeout, $job->uniqueFor);
        }
    }

    public function test_long_running_jobs_have_resource_specific_unique_ids(): void
    {
        $this->assertSame('woocommerce-sync:9', (new SyncWooCommerceProductsJob(9, 1))->uniqueId());
        $this->assertSame('scheduled-organization-backup:9', (new CreateScheduledOrganizationBackupJob(9))->uniqueId());
        $this->assertSame('organization-backup-cloud-copy:9', (new SyncOrganizationBackupToPersonalCloudJob(9))->uniqueId());
    }
}
