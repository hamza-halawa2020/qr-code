<?php

namespace Tests\Feature;

use App\Jobs\PublishBranch;
use App\Models\Branch;
use App\Models\Counter;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Services\FirebaseGateway;
use App\Services\QueueService;
use App\Services\QueueSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class FirebasePublishingTest extends TestCase
{
    use RefreshDatabase;

    public function test_rest_publish_preserves_firestore_types_and_commit_url(): void
    {
        config(['firebase.project_id' => 'test-project']);
        Cache::put('firebase-access-test-project', 'fake-token', 60);
        Http::fake(['firestore.googleapis.com/*' => Http::response(['writeResults' => []])]);
        app(FirebaseGateway::class)->publish(['counters/1' => ['number' => null, 'paused' => false, 'version' => 4]]);
        Http::assertSent(fn ($request) => $request->url() === 'https://firestore.googleapis.com/v1/projects/test-project/databases/(default)/documents:commit'
            && $request['writes'][0]['update']['fields']['paused']['booleanValue'] === false
            && $request['writes'][0]['update']['fields']['version']['integerValue'] === '4'
            && array_key_exists('nullValue', $request['writes'][0]['update']['fields']['number']));
        app(FirebaseGateway::class)->deleteDocuments(['tickets/old-ticket']);
        Http::assertSent(fn ($request) => ($request['writes'][0]['delete'] ?? null) === 'projects/test-project/databases/(default)/documents/tickets/old-ticket');
    }

    public function test_outbox_publishes_latest_snapshot_and_marks_messages_processed(): void
    {
        $this->travelTo(now('UTC')->setTime(10, 0));
        $branch = Branch::create(['name' => 'Test', 'timezone' => 'UTC']);
        $queue = app(QueueService::class);
        $secret = Str::random(64);
        $ticket = $queue->book($branch, $secret, $secret);
        $user = User::factory()->create(['branch_id' => $branch->id, 'role' => 'agent']);
        $counter = Counter::create(['branch_id' => $branch->id, 'name' => 'Counter', 'number' => 1, 'user_id' => $user->id, 'display_token_hash' => hash('sha256', Str::random(64))]);
        $queue->next($counter, $user);
        $queue->transition($ticket, 'start', $user);
        config(['firebase.enabled' => true]);
        $gateway = $this->mock(FirebaseGateway::class);
        $gateway->shouldReceive('publish')->once()->withArgs(fn ($docs) => $docs['tickets/'.$ticket->public_id]['status'] === 'serving' && $docs['counters/'.$counter->id]['number'] === '001');
        $gateway->shouldNotReceive('notify');
        (new PublishBranch($branch->id))->handle($gateway, app(QueueSnapshot::class));
        $this->assertSame(0, OutboxMessage::whereNull('processed_at')->count());
        (new PublishBranch($branch->id))->handle($gateway, app(QueueSnapshot::class));
    }

    public function test_publish_failure_keeps_outbox_for_retry(): void
    {
        $branch = Branch::create(['name' => 'Test']);
        OutboxMessage::create(['branch_id' => $branch->id, 'version' => 1, 'type' => 'test']);
        config(['firebase.enabled' => true]);
        $gateway = $this->mock(FirebaseGateway::class);
        $gateway->shouldReceive('publish')->once()->andThrow(new \RuntimeException('Temporary network failure'));
        try {
            (new PublishBranch($branch->id))->handle($gateway, app(QueueSnapshot::class));
        } catch (\RuntimeException) {
        }
        $this->assertSame(1, OutboxMessage::whereNull('processed_at')->count());
        $recovered = \Mockery::mock(FirebaseGateway::class);
        $recovered->shouldReceive('publish')->once()->andReturnNull();
        (new PublishBranch($branch->id))->handle($recovered, app(QueueSnapshot::class));
        $this->assertSame(0, OutboxMessage::whereNull('processed_at')->count());
    }

    public function test_notification_request_is_scoped_to_ticket_with_repeat_tag(): void
    {
        config(['firebase.project_id' => 'test-project']);
        Cache::put('firebase-access-test-project', 'fake-token', 60);
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'message-id'])]);
        $this->assertTrue(app(FirebaseGateway::class)->notify('device-token', 'event-1', ['id' => 'ticket-id', 'number' => '001', 'counter' => 'Window 1']));
        Http::assertSent(fn ($request) => $request['message']['data']['ticket_id'] === 'ticket-id' && $request['message']['webpush']['notification']['tag'] === 'queue-event-1');
    }

    public function test_unregistered_device_is_detected_even_when_error_detail_is_not_first(): void
    {
        config(['firebase.project_id' => 'test-project']);
        Cache::put('firebase-access-test-project', 'fake-token', 60);
        Http::fake(['fcm.googleapis.com/*' => Http::response(['error' => ['details' => [['message' => 'Another detail'], ['errorCode' => 'UNREGISTERED']]]], 404)]);
        $this->assertFalse(app(FirebaseGateway::class)->notify('invalid-device', 'event-1', ['id' => 'ticket-id', 'number' => '001', 'counter' => 'Window 1']));
    }

    public function test_provider_errors_do_not_expose_device_tokens_in_exception_messages(): void
    {
        config(['firebase.project_id' => 'test-project']);
        Cache::put('firebase-access-test-project', 'fake-token', 60);
        Http::fake(['fcm.googleapis.com/*' => Http::response(['error' => ['message' => 'Invalid token private-device-token']], 400)]);
        try {
            app(FirebaseGateway::class)->notify('private-device-token', 'event-1', ['id' => 'ticket-id', 'number' => '001', 'counter' => 'Window 1']);
            $this->fail('Provider failure should raise an error');
        } catch (\RuntimeException $exception) {
            $this->assertStringNotContainsString('private-device-token', $exception->getMessage());
            $this->assertStringContainsString('HTTP 400', $exception->getMessage());
        }
    }
}
