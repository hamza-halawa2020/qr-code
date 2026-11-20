<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Services\AppointmentService;
use App\Services\BranchBoardService;
use App\Services\CustomerExperienceService;
use Illuminate\Http\Request;

class ExperienceController extends Controller
{
    public function board(Branch $branch, string $secret, BranchBoardService $boards)
    {
        return view('queue.board', ['branch' => $branch, 'secret' => $secret, 'snapshot' => $boards->state($branch, $secret)]);
    }

    public function boardState(Branch $branch, string $secret, BranchBoardService $boards)
    {
        return response()->json($boards->state($branch, $secret));
    }

    public function boardFirebase(Branch $branch, string $secret, BranchBoardService $boards)
    {
        return response()->json($boards->credentials($branch, $secret));
    }

    public function rate(Request $request, string $secret, CustomerExperienceService $experience)
    {
        $experience->rate($secret, $request->only(['rating', 'rating_comment']));

        return redirect()->route('ticket.show', $secret)->with('status', 'شكرًا لتقييمك.');
    }

    public function appointments(Request $request, Branch $branch, AppointmentService $appointments)
    {
        return view('queue.appointments', $appointments->slots($branch, $request->only(['date'])));
    }

    public function bookAppointment(Request $request, Branch $branch, AppointmentService $appointments)
    {
        return redirect()->route('appointment.show', $appointments->book($branch, $request->session(), $request->only(['date', 'time', 'service_id', 'customer_name', 'customer_phone', 'customer_email'])));
    }

    public function appointment(string $secret, AppointmentService $appointments)
    {
        return view('queue.appointment', ['appointment' => $appointments->find($secret), 'secret' => $secret]);
    }

    public function checkIn(Request $request, string $secret, AppointmentService $appointments)
    {
        return redirect()->route('ticket.show', $appointments->checkIn($secret, $request->session()));
    }

    public function cancelAppointment(string $secret, AppointmentService $appointments)
    {
        $appointments->cancel($secret);

        return redirect()->route('appointment.show', $secret);
    }
}
