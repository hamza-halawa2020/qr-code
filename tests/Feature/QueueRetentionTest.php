<?php

namespace Tests\Feature;

use App\Jobs\PublishBranch;
use App\Models\Branch;
use App\Models\OutboxMessage;
use App\Models\Ticket;
use App\Services\FirebaseGateway;
use App\Services\QueueRetentionService;
use App\Services\QueueService;
use App\Services\QueueSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class QueueRetentionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        config(['firebase.enabled' => false, 'queue-data.retention_days' => 365]);
        $this->travelTo(now()->setTime(10, 0));
        $this->branch = Branch::create(['name' => 'Test', 'timezone' => 'UTC']);
    }

    private function ticket(string $status, int $days, bool $published = true): Ticket
    {
        $secret = Str::random(64);
        $ticket = app(QueueService::class)->book($this->branch, $secret, $secret);
        $ticket->update(['status' => $status, 'finished_at' => now()->subDays($days)]);
        if ($published) {
            OutboxMessage::where('ticket_id', $ticket->id)->update(['processed_at' => now()]);
        }

        return $ticket;
    }

    public function test_cleanup_removes_old_finished_tickets_events_and_subscriptions_but_preserves_active_recent_and_pending(): void
    {
        $old = $this->ticket('completed', 366);
        $recent = $this->ticket('completed', 10);
        $active = $this->ticket('serving', 400);
        $pending = $this->ticket('cancelled', 400, false);
        DB::table('push_subscriptions')->insert(['ticket_id' => $old->id, 'token' => 'old-token', 'token_hash' => hash('sha256', 'old-token'), 'created_at' => now(), 'updated_at' => now()]);
        $counts = app(QueueRetentionService::class)->prune();
        $this->assertSame(1, $counts['tickets']);
        $this->assertDatabaseMissing('tickets', ['id' => $old->id]);
        $this->assertDatabaseMissing('ticket_events', ['ticket_id' => $old->id]);
        $this->assertDatabaseMissing('push_subscriptions', ['ticket_id' => $old->id]);
        $this->assertSame(3, Ticket::whereIn('id', [$recent->id, $active->id, $pending->id])->count());
        $this->assertDatabaseHas('outbox_messages', ['type' => 'ticket_deleted', 'ticket_public_id' => $old->public_id, 'processed_at' => null]);
    }

    public function test_dry_run_does_not_delete_any_records(): void
    {
        $old = $this->ticket('expired', 366);
        $this->artisan('queue:prune', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseHas('tickets', ['id' => $old->id]);
        $this->assertSame(0, OutboxMessage::where('type', 'ticket_deleted')->count());
    }

    public function test_remote_deletion_failure_preserves_deletion_reference_for_retry(): void
    {
        $old = $this->ticket('completed', 366);
        app(QueueRetentionService::class)->prune();
        config(['firebase.enabled' => true]);
        $gateway = $this->mock(FirebaseGateway::class);
        $gateway->shouldReceive('publish')->twice();
        $gateway->shouldReceive('deleteDocuments')->once()->with(['tickets/'.$old->public_id])->andThrow(new \RuntimeException('Temporary network error'));
        try {
            (new PublishBranch($this->branch->id))->handle($gateway, app(QueueSnapshot::class));
        } catch (\RuntimeException) {
        }
        $this->assertDatabaseHas('outbox_messages', ['type' => 'ticket_deleted', 'ticket_public_id' => $old->public_id, 'processed_at' => null]);
        $gateway->shouldReceive('deleteDocuments')->once()->with(['tickets/'.$old->public_id])->andReturnNull();
        (new PublishBranch($this->branch->id))->handle($gateway, app(QueueSnapshot::class));
        $this->assertSame(0, OutboxMessage::where('type', 'ticket_deleted')->whereNull('processed_at')->count());
    }

    public function test_old_unpublished_deletions_are_not_pruned(): void
    {
        OutboxMessage::create(['branch_id' => $this->branch->id, 'version' => 1, 'type' => 'ticket_deleted', 'ticket_public_id' => (string) Str::uuid(), 'created_at' => now()->subDays(500)]);
        app(QueueRetentionService::class)->prune();
        $this->assertSame(1, OutboxMessage::whereNull('processed_at')->count());
    }
}
