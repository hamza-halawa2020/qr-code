<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Counter;
use App\Services\BranchQrCodeService;
use App\Services\CounterDisplayService;
use App\Services\CustomerQueueService;
use App\Services\TicketNotificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class QueueController extends Controller
{
    public function __construct(
        private readonly CustomerQueueService $customers,
        private readonly CounterDisplayService $displays,
        private readonly TicketNotificationService $notifications,
        private readonly BranchQrCodeService $qrCodes,
    ) {}

    public function home(): View
    {
        return view('queue.home', ['branches' => $this->customers->branches()]);
    }

    public function qr(Request $request, Branch $branch): Response
    {
        return response($this->qrCodes->generate($branch, $request->user()))
            ->header('Content-Type', 'image/svg+xml')
            ->header('Content-Disposition', 'attachment; filename="branch-'.$branch->id.'-qr.svg"');
    }

    public function branch(Request $request, Branch $branch): View
    {
        return view('queue.branch', $this->customers->branchData($branch, $request->session()));
    }

    public function book(Request $request, Branch $branch): RedirectResponse
    {
        return redirect()->route('ticket.show', $this->customers->book($branch, $request->session(), $request->only(['service_id', 'customer_phone'])));
    }

    public function show(string $secret): View
    {
        return view('queue.ticket', $this->customers->ticketData($secret));
    }

    public function state(string $secret): JsonResponse
    {
        return response()->json($this->customers->state($secret))->header('Cache-Control', 'no-store');
    }

    public function cancel(string $secret): RedirectResponse
    {
        $this->customers->cancel($secret);

        return redirect()->route('ticket.show', $secret);
    }

    public function firebaseTicket(string $secret): JsonResponse
    {
        try {
            $credentials = $this->notifications->firebaseCredentials($secret);
            Log::info('[ticket][firebase] credentials issued', [
                'ticket' => substr(hash('sha256', $secret), 0, 12),
                'path' => $credentials['path'],
                'token_length' => strlen($credentials['token']),
            ]);

            return response()->json($credentials)->header('Cache-Control', 'no-store');
        } catch (\Throwable $error) {
            Log::error('[ticket][firebase] credentials failed', [
                'ticket' => substr(hash('sha256', $secret), 0, 12),
                'message' => $error->getMessage(),
            ]);
            throw $error;
        }
    }

    public function subscribe(Request $request, string $secret): JsonResponse
    {
        $token = $request->input('token');
        Log::info('[ticket][firebase] subscribe request', [
            'ticket' => substr(hash('sha256', $secret), 0, 12),
            'token_length' => is_string($token) ? strlen($token) : 0,
        ]);
        try {
            $this->notifications->subscribe($secret, $token);
            Log::info('[ticket][firebase] subscribe stored');

            return response()->json(['ok' => true]);
        } catch (\Throwable $error) {
            Log::error('[ticket][firebase] subscribe failed', [
                'ticket' => substr(hash('sha256', $secret), 0, 12),
                'message' => $error->getMessage(),
            ]);
            throw $error;
        }
    }

    public function display(Counter $counter, string $secret): View
    {
        return view('queue.display', $this->displays->viewData($counter, $secret));
    }

    public function unsubscribe(Request $request, string $secret): JsonResponse
    {
        $this->notifications->unsubscribe($secret, $request->input('token'));

        return response()->json(['ok' => true]);
    }

    public function diag(Request $request, string $secret): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:900'],
            'name' => ['sometimes', 'string', 'max:120'],
            'code' => ['sometimes', 'string', 'max:120'],
        ]);
        Log::warning('[ticket][browser] '.$data['label'], [
            'ticket' => substr(hash('sha256', $secret), 0, 12),
            'name' => $data['name'] ?? '',
            'code' => $data['code'] ?? '',
            'message' => $data['message'],
        ]);

        return response()->json(['ok' => true]);
    }

    public function displayState(Counter $counter, string $secret): JsonResponse
    {
        return response()->json($this->displays->state($counter, $secret))->header('Cache-Control', 'no-store');
    }

    public function firebaseDisplay(Counter $counter, string $secret): JsonResponse
    {
        return response()->json($this->displays->firebaseCredentials($counter, $secret))->header('Cache-Control', 'no-store');
    }
}
