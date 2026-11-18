<?php

namespace App\Services;

use App\Jobs\PublishBranch;
use App\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TicketNotificationService
{
    public function __construct(
        private readonly CustomerQueueService $customers,
        private readonly FirebaseGateway $firebase,
    ) {}

    public function firebaseCredentials(string $secret): array
    {
        abort_unless(config('firebase.enabled'), 503);
        $ticket = $this->customers->findTicket($secret);

        return [
            'token' => $this->firebase->customToken('ticket-'.$ticket->public_id, ['ticket_id' => $ticket->public_id]),
            'path' => 'tickets/'.$ticket->public_id,
        ];
    }

    public function subscribe(string $secret, mixed $token): void
    {
        abort_unless(config('firebase.enabled'), 503);
        $ticket = $this->customers->findTicket($secret);
        abort_unless($ticket->status->isActive(), 422);
        $data = Validator::make(['token' => $token], ['token' => ['required', 'string', 'max:4096']])->validate();

        DB::transaction(function () use ($ticket, $data) {
            $branch = Branch::whereKey($ticket->branch_id)->lockForUpdate()->firstOrFail();
            $ticket = $ticket->fresh();
            abort_unless($ticket->status->isActive(), 422);
            DB::table('push_subscriptions')->updateOrInsert(
                ['token_hash' => hash('sha256', $data['token'])],
                ['ticket_id' => $ticket->id, 'token' => $data['token'], 'created_at' => now(), 'updated_at' => now()],
            );
            app(CustomerExperienceService::class)->enqueueNear($branch);
            PublishBranch::dispatch($branch->id)->afterCommit();
        });
    }

    public function unsubscribe(string $secret, mixed $token): void
    {
        $ticket = $this->customers->findTicket($secret);
        $data = Validator::make(['token' => $token], ['token' => ['required', 'string', 'max:4096']])->validate();
        DB::table('push_subscriptions')->where('ticket_id', $ticket->id)->where('token_hash', hash('sha256', $data['token']))->delete();
    }
}
