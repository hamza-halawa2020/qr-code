<?php

namespace Tests\Feature;

use App\Jobs\PublishBranch;
use App\Models\Branch;
use App\Models\Counter;
use App\Models\OutboxMessage;
use App\Models\Ticket;
use App\Models\User;
use App\Services\BranchBoardService;
use App\Services\CustomerExperienceService;
use App\Services\FirebaseGateway;
use App\Services\QueueReportService;
use App\Services\QueueService;
use App\Services\QueueSnapshot;
use App\Services\WaitEstimateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ExperienceFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $agent;

    private Counter $counter;

    private int $serviceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['firebase.enabled' => false]);
        Queue::fake([PublishBranch::class]);
        $this->travelTo(now()->setTime(10, 0));
        $this->branch = Branch::create(['name' => 'Branch', 'timezone' => 'UTC']);
        $this->serviceId = $this->branch->services()->create(['name' => 'Service'])->id;
        $this->agent = User::factory()->create(['role' => 'agent', 'branch_id' => $this->branch->id]);
        $this->agent->services()->sync([$this->serviceId]);
        $this->counter = Counter::create(['branch_id' => $this->branch->id, 'name' => 'Window', 'number' => 1, 'user_id' => $this->agent->id, 'display_token_hash' => hash('sha256', Str::random(64))]);
    }

    private function book(): Ticket
    {
        return app(QueueService::class)->book($this->branch, $s = Str::random(64), $s, ['service_id' => $this->serviceId, 'customer_phone' => '01012345678']);
    }

    public function test_branch_board_is_private_rotatable_and_contains_only_public_counter_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $url = app(BranchBoardService::class)->rotate($admin, $this->branch->id);
        $ticket = $this->book();
        app(QueueService::class)->next($this->counter, $this->agent);
        $this->get($url)->assertOk()->assertSee('Window')->assertSee('001')->assertDontSee('01012345678')->assertDontSee('customer_name');
        $this->getJson($url.'/state')->assertOk()->assertJsonPath('counters.0.number', '001')->assertJsonMissingPath('token_hash')->assertDontSee('01012345678');
        app(BranchBoardService::class)->rotate($admin, $this->branch->id);
        $this->get($url)->assertNotFound();
        $this->assertArrayNotHasKey('board_token_hash', $this->branch->fresh()->toArray());
    }

    public function test_board_manager_is_scoped_and_firebase_credentials_are_board_only(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'branch_id' => $this->branch->id]);
        $url = app(BranchBoardService::class)->rotate($manager, $this->branch->id);
        config(['firebase.enabled' => true]);
        $this->mock(FirebaseGateway::class)->shouldReceive('customToken')->once()->with('board-'.$this->branch->id, ['branch_display_id' => (string) $this->branch->id])->andReturn('scoped-token');
        $this->postJson($url.'/firebase')->assertOk()->assertJsonPath('path', 'branch_displays/'.$this->branch->id);
        $other = Branch::create(['name' => 'Other']);
        $this->expectException(HttpException::class);
        app(BranchBoardService::class)->rotate($manager, $other->id);
    }

    public function test_estimate_requires_five_real_samples_and_respects_service_and_staff(): void
    {
        $target = $this->book();
        $estimator = app(WaitEstimateService::class);
        $this->assertNull($estimator->estimate($target)['minutes']);
        for ($i = 0; $i < 5; $i++) {
            $this->book()->update(['status' => 'completed', 'started_at' => now()->subMinutes(10), 'finished_at' => now()->subMinutes(5)]);
        }
        $this->assertSame(0, $estimator->estimate($target)['minutes']);
        app(QueueService::class)->next($this->counter, $this->agent);
        $next = $this->book();
        $this->assertSame(5, $estimator->estimate($next)['minutes']);
        $this->counter->update(['is_paused' => true]);
        $this->assertNull($estimator->estimate($next)['minutes']);
    }

    public function test_near_reminder_is_persistent_once_and_uses_correct_fcm_kind(): void
    {
        $first = $this->book();
        $this->book();
        $this->book();
        $target = $this->book();
        DB::table('push_subscriptions')->insert(['ticket_id' => $target->id, 'token' => 'device', 'token_hash' => hash('sha256', 'device'), 'created_at' => now(), 'updated_at' => now()]);
        app(CustomerExperienceService::class)->enqueueNear($this->branch);
        $this->assertNull($target->fresh()->near_notified_at);
        app(QueueService::class)->transition($first, 'cancel');
        $this->assertNotNull($target->fresh()->near_notified_at);
        app(CustomerExperienceService::class)->enqueueNear($this->branch->fresh());
        $this->assertSame(1, OutboxMessage::where('ticket_id', $target->id)->where('type', 'near_turn')->count());
        config(['firebase.enabled' => true]);
        $firebase = $this->mock(FirebaseGateway::class);
        $firebase->shouldReceive('publish')->once();
        $firebase->shouldReceive('notify')->once()->withArgs(fn ($token, $id, $data) => $token === 'device' && $data['notification_kind'] === 'near_turn')->andReturn(true);
        (new PublishBranch($this->branch->id))->handle($firebase, app(QueueSnapshot::class));
        $this->assertSame(0, OutboxMessage::whereNull('processed_at')->count());
    }

    public function test_rating_is_completed_only_once_and_reports_are_branch_scoped(): void
    {
        $secret = Str::random(64);
        $ticket = app(QueueService::class)->book($this->branch, $secret, $secret);
        $this->postJson(route('ticket.rate', $secret), ['rating' => 5])->assertUnprocessable();
        $ticket->update(['status' => 'completed', 'finished_at' => now()]);
        $this->post(route('ticket.rate', $secret), ['rating' => 4, 'rating_comment' => 'ممتاز'])->assertRedirect();
        $this->post(route('ticket.rate', $secret), ['rating' => 1])->assertRedirect();
        $this->assertSame(4, (int) $ticket->fresh()->rating);
        $manager = User::factory()->create(['role' => 'manager', 'branch_id' => $this->branch->id]);
        $report = app(QueueReportService::class)->daily($manager, now()->toDateString(), $this->branch->id);
        $this->assertSame(1, $report['ratingCount']);
        $this->assertEquals(4, $report['ratingAverage']);
        $this->postJson(route('ticket.rate', Str::random(64)), ['rating' => 5])->assertNotFound();
        $this->assertArrayNotHasKey('rating_comment', $ticket->fresh()->toArray());
    }

    public function test_firestore_preserves_nested_board_counter_types(): void
    {
        config(['firebase.project_id' => 'board-test']);
        Cache::put('firebase-access-board-test', 'fake', 60);
        Http::fake(['firestore.googleapis.com/*' => Http::response(['writeResults' => []])]);
        app(FirebaseGateway::class)->publish(['branch_displays/1' => ['counters' => [['number' => '001', 'id' => 1, 'paused' => false]]]]);
        Http::assertSent(fn ($request) => $request['writes'][0]['update']['fields']['counters']['arrayValue']['values'][0]['mapValue']['fields']['number']['stringValue'] === '001');
    }

    public function test_near_reminder_waits_for_a_suitable_running_counter_and_can_be_requeued(): void
    {
        $ticket = $this->book();
        DB::table('push_subscriptions')->insert(['ticket_id' => $ticket->id, 'token' => 'device', 'token_hash' => hash('sha256', 'device'), 'created_at' => now(), 'updated_at' => now()]);
        app(CustomerExperienceService::class)->enqueueNear($this->branch);
        $this->counter->update(['is_paused' => true]);
        config(['firebase.enabled' => true]);
        $firebase = $this->mock(FirebaseGateway::class);
        $firebase->shouldReceive('publish')->once();
        $firebase->shouldNotReceive('notify');
        (new PublishBranch($this->branch->id))->handle($firebase, app(QueueSnapshot::class));
        $this->assertNull($ticket->fresh()->near_notified_at);
        $this->assertFalse(app(CustomerExperienceService::class)->isNear($ticket->fresh()));
        $this->counter->update(['is_paused' => false]);
        app(CustomerExperienceService::class)->enqueueNear($this->branch);
        $this->assertSame(1, OutboxMessage::where('type', 'near_turn')->whereNull('processed_at')->count());
    }
}
