<?php

namespace App\Http\Controllers;

use App\Enums\TicketStatus;
use App\Models\{Branch, Counter, Ticket};
use App\Services\{FirebaseGateway, QueueService, QueueSnapshot};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QueueController extends Controller
{
    public function home()
    {
        return view('queue.home', ['branches' => Branch::orderBy('name')->get()]);
    }

    public function branch(Request $request, Branch $branch)
    {
        $secret = $request->session()->get('ticket.'.$branch->id);
        $active = $secret ? Ticket::where('token_hash', hash('sha256', $secret))->first() : null;
        return view('queue.branch', compact('branch', 'active', 'secret'));
    }

    public function book(Request $request, Branch $branch, QueueService $queue)
    {
        $secret = $request->session()->get('ticket.'.$branch->id);
        $existing = $secret ? Ticket::where('token_hash', hash('sha256', $secret))->first() : null;
        if ($existing?->status->isActive()) { return redirect()->route('ticket.show', $secret); }
        // Keep the pending key in session across request retries.
        $secret = $request->session()->get('pending.'.$branch->id) ?? Str::random(64);
        $request->session()->put('pending.'.$branch->id, $secret);
        $queue->book($branch, $secret, $secret);
        $request->session()->put('ticket.'.$branch->id, $secret);
        $request->session()->forget('pending.'.$branch->id);
        return redirect()->route('ticket.show', $secret);
    }

    public function show(string $secret, QueueSnapshot $snapshot)
    {
        $ticket = $this->ticket($secret);
        return view('queue.ticket', ['ticket' => $ticket, 'secret' => $secret, 'snapshot' => $snapshot->ticket($ticket)]);
    }

    public function state(string $secret, QueueSnapshot $snapshot)
    {
        return response()->json($snapshot->ticket($this->ticket($secret)))->header('Cache-Control', 'no-store');
    }

    public function cancel(string $secret, QueueService $queue)
    {
        $queue->transition($this->ticket($secret), 'cancel');
        return redirect()->route('ticket.show', $secret);
    }

    public function firebaseTicket(string $secret, FirebaseGateway $firebase)
    {
        abort_unless(config('firebase.enabled'), 503);
        $ticket = $this->ticket($secret);
        return response()->json(['token' => $firebase->customToken('ticket-'.$ticket->public_id, ['ticket_id' => $ticket->public_id]), 'path' => 'tickets/'.$ticket->public_id])->header('Cache-Control', 'no-store');
    }

    public function subscribe(Request $request, string $secret)
    {
        abort_unless(config('firebase.enabled'), 503);
        $ticket = $this->ticket($secret);
        abort_unless($ticket->status->isActive(), 422);
        $data = $request->validate(['token' => ['required', 'string', 'max:4096']]);
        DB::table('push_subscriptions')->updateOrInsert(['token_hash' => hash('sha256', $data['token'])], ['ticket_id' => $ticket->id, 'token' => $data['token'], 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['ok' => true]);
    }

    public function display(Counter $counter, string $secret, QueueSnapshot $snapshot)
    {
        $this->authorizeDisplay($counter, $secret);
        return view('queue.display', ['counter' => $counter, 'secret' => $secret, 'snapshot' => $snapshot->counter($counter)]);
    }

    public function displayState(Counter $counter, string $secret, QueueSnapshot $snapshot)
    {
        $this->authorizeDisplay($counter, $secret);
        return response()->json($snapshot->counter($counter))->header('Cache-Control', 'no-store');
    }

    public function firebaseDisplay(Counter $counter, string $secret, FirebaseGateway $firebase)
    {
        $this->authorizeDisplay($counter, $secret);
        abort_unless(config('firebase.enabled'), 503);
        return response()->json(['token' => $firebase->customToken('counter-'.$counter->id, ['counter_id' => (string) $counter->id]), 'path' => 'counters/'.$counter->id])->header('Cache-Control', 'no-store');
    }

    private function ticket(string $secret): Ticket
    {
        abort_unless(strlen($secret) === 64, 404);
        return Ticket::where('token_hash', hash('sha256', $secret))->firstOrFail();
    }

    private function authorizeDisplay(Counter $counter, string $secret): void
    {
        abort_unless(hash_equals($counter->display_token_hash, hash('sha256', $secret)), 404);
    }
}
