<?php

namespace App\Services;

use App\Models\Counter;

class CounterDisplayService
{
    public function __construct(
        private readonly QueueSnapshot $snapshot,
        private readonly FirebaseGateway $firebase,
    ) {}

    public function viewData(Counter $counter, string $secret): array
    {
        return ['counter' => $counter, 'secret' => $secret, 'snapshot' => $this->state($counter, $secret)];
    }

    public function state(Counter $counter, string $secret): array
    {
        $this->authorize($counter, $secret);

        return $this->snapshot->counter($counter);
    }

    public function firebaseCredentials(Counter $counter, string $secret): array
    {
        $this->authorize($counter, $secret);
        abort_unless(config('firebase.enabled'), 503);

        return [
            'token' => $this->firebase->customToken('counter-'.$counter->id, ['counter_id' => (string) $counter->id]),
            'path' => 'counters/'.$counter->id,
        ];
    }

    private function authorize(Counter $counter, string $secret): void
    {
        abort_unless(hash_equals($counter->display_token_hash, hash('sha256', $secret)), 404);
    }
}
