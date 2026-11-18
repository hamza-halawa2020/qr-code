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
        return response()->json($this->notifications->firebaseCredentials($secret))->header('Cache-Control', 'no-store');
    }

    public function subscribe(Request $request, string $secret): JsonResponse
    {
        $this->notifications->subscribe($secret, $request->input('token'));

        return response()->json(['ok' => true]);
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

    public function displayState(Counter $counter, string $secret): JsonResponse
    {
        return response()->json($this->displays->state($counter, $secret))->header('Cache-Control', 'no-store');
    }

    public function firebaseDisplay(Counter $counter, string $secret): JsonResponse
    {
        return response()->json($this->displays->firebaseCredentials($counter, $secret))->header('Cache-Control', 'no-store');
    }
}
