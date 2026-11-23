<?php

namespace Database\Seeders;

use App\Enums\TicketStatus;
use App\Models\Branch;
use App\Models\BranchSchedule;
use App\Models\BranchService;
use App\Models\Counter;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new \RuntimeException('Demo seeding is only allowed locally.');
        }

        if (User::where('email', 'admin@example.com')->exists()) {
            $this->command?->warn('Demo already seeded; skipping.');

            return;
        }

        $today     = now('Africa/Cairo')->toDateString();
        $yesterday = now('Africa/Cairo')->subDay()->toDateString();
        $lines     = ['# Local Demo Access', '', 'Generated credentials — do not deploy.', ''];

        // ----------------------------------------------------------------
        // Branch
        // ----------------------------------------------------------------
        $branch = Branch::create([
            'name'                  => 'الفرع الرئيسي',
            'timezone'              => 'Africa/Cairo',
            'is_open'               => true,
            'opens_at'              => '01:00',
            'closes_at'             => '23:59',
            'no_show_after_seconds' => 120,
            'automatic_queue'       => false,
            'missed_turn_retries'   => 2,
            'board_token_hash'      => hash('sha256', $boardSecret = Str::random(64)),
        ]);

        // A varied weekly schedule so the customer-facing hours are easy to see.
        $weeklyHours = [
            0 => ['is_closed' => false, 'opens_at' => '01:00', 'closes_at' => '23:59'], // Sunday
            1 => ['is_closed' => false, 'opens_at' => '01:00', 'closes_at' => '23:59'], // Monday
            2 => ['is_closed' => false, 'opens_at' => '01:00', 'closes_at' => '23:59'], // Tuesday
            3 => ['is_closed' => false, 'opens_at' => '01:00', 'closes_at' => '23:59'], // Wednesday
            4 => ['is_closed' => false, 'opens_at' => '01:00', 'closes_at' => '23:59'], // Thursday
            5 => ['is_closed' => false, 'opens_at' => '01:00', 'closes_at' => '23:59'], // Friday
            6 => ['is_closed' => false, 'opens_at' => '01:00', 'closes_at' => '23:59'], // Saturday
        ];
        foreach ($weeklyHours as $day => $row) {
            BranchSchedule::create(['branch_id' => $branch->id, 'day_of_week' => $day] + $row);
        }

        // ----------------------------------------------------------------
        // Services
        // ----------------------------------------------------------------
        // Services are managed globally, then linked to this branch. Keeping
        // the link rows (and their IDs) is what tickets and agents reference.
        $globalGeneral    = Service::firstOrCreate(['name' => 'خدمة عامة']);
        $globalAccounts   = Service::firstOrCreate(['name' => 'فتح حسابات']);
        $globalLoans      = Service::firstOrCreate(['name' => 'القروض والتمويل']);
        $globalComplaints = Service::firstOrCreate(['name' => 'الشكاوى والاستفسارات']);
        $globalCards      = Service::firstOrCreate(['name' => 'بطاقات ائتمانية']);

        $link = fn (Service $service, bool $active) => BranchService::create([
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'is_active' => $active,
        ]);
        $svcGeneral    = $link($globalGeneral, true);
        $svcAccounts   = $link($globalAccounts, true);
        $svcLoans      = $link($globalLoans, true);
        $svcComplaints = $link($globalComplaints, true);
        $svcCards      = $link($globalCards, false); // inactive in this branch

        // ── Users  (admin + manager + 4 agents) ──────────────────────────
        $makeUser = function (string $name, string $phone, string $role) use ($branch, &$lines): User {
            $user = User::create([
                'name'      => $name,
                'phone'     => $phone,
                'password'  => Hash::make('12345678'),
                'role'      => $role,
                'branch_id' => $branch->id,
                'is_active' => true,
            ]);
            $lines[] = "- {$phone} / `12345678`  ({$role})";

            return $user;
        };

        $admin   = $makeUser('مدير النظام',    '01000000001', 'admin');
        $manager = $makeUser('مدير الفرع',     '01000000002', 'manager');
        $agent1  = $makeUser('أحمد محمد',      '01000000003', 'agent');
        $agent2  = $makeUser('سارة خالد',      '01000000004', 'agent');
        $agent3  = $makeUser('محمود عبد الله', '01000000005', 'agent');
        $agent4  = $makeUser('فاطمة حسن',      '01000000006', 'agent'); // inactive employee
        $agent4->update(['is_active' => false]);

        // ----------------------------------------------------------------
        // Employee ↔ Service assignments
        // ----------------------------------------------------------------
        // admin & manager can handle everything
        foreach ([$admin, $manager] as $u) {
            DB::table('employee_services')->insert(
                collect([$svcGeneral, $svcAccounts, $svcLoans, $svcComplaints, $svcCards])
                    ->map(fn ($s) => ['user_id' => $u->id, 'branch_service_id' => $s->id])
                    ->all()
            );
        }
        // agent1 → general + accounts + complaints
        DB::table('employee_services')->insert([
            ['user_id' => $agent1->id, 'branch_service_id' => $svcGeneral->id],
            ['user_id' => $agent1->id, 'branch_service_id' => $svcAccounts->id],
            ['user_id' => $agent1->id, 'branch_service_id' => $svcComplaints->id],
        ]);
        // agent2 → general + loans
        DB::table('employee_services')->insert([
            ['user_id' => $agent2->id, 'branch_service_id' => $svcGeneral->id],
            ['user_id' => $agent2->id, 'branch_service_id' => $svcLoans->id],
        ]);
        // agent3 → all active services
        DB::table('employee_services')->insert([
            ['user_id' => $agent3->id, 'branch_service_id' => $svcGeneral->id],
            ['user_id' => $agent3->id, 'branch_service_id' => $svcAccounts->id],
            ['user_id' => $agent3->id, 'branch_service_id' => $svcLoans->id],
            ['user_id' => $agent3->id, 'branch_service_id' => $svcComplaints->id],
        ]);
        // agent4 (inactive) keeps only general
        DB::table('employee_services')->insert([
            ['user_id' => $agent4->id, 'branch_service_id' => $svcGeneral->id],
        ]);

        // ----------------------------------------------------------------
        // Counters  (4 counters)
        // ----------------------------------------------------------------
        $makeCounter = function (string $name, int $number, User $user) use ($branch, &$lines): Counter {
            $secret  = Str::random(64);
            $counter = Counter::create([
                'branch_id'          => $branch->id,
                'name'               => $name,
                'number'             => $number,
                'user_id'            => $user->id,
                'is_paused'          => false,
                'display_token_hash' => hash('sha256', $secret),
            ]);
            $lines[] = '- Display ' . $name . ': ' . url("/display/{$counter->id}/{$secret}");

            return $counter;
        };

        $counter1 = $makeCounter('شباك 1', 1, $agent1);
        $counter2 = $makeCounter('شباك 2', 2, $agent2);
        $counter3 = $makeCounter('شباك 3', 3, $agent3);
        // counter4 is unassigned & paused (no agent logged in)
        $counter4Secret = Str::random(64);
        $counter4 = Counter::create([
            'branch_id'          => $branch->id,
            'name'               => 'شباك 4',
            'number'             => 4,
            'user_id'            => null,
            'is_paused'          => true,
            'display_token_hash' => hash('sha256', $counter4Secret),
        ]);
        $lines[] = '- Display شباك 4 (unassigned): ' . url("/display/{$counter4->id}/{$counter4Secret}");

        // ----------------------------------------------------------------
        // Counter sessions  (active sessions for agents 1-3, closed for agent4)
        // ----------------------------------------------------------------
        $sessionStartedAt = now('Africa/Cairo')->subHours(3);

        DB::table('counter_sessions')->insert([
            [   // agent1 — active session today
                'branch_id'        => $branch->id,
                'counter_id'       => $counter1->id,
                'user_id'          => $agent1->id,
                'active_counter_id'=> $counter1->id,
                'active_user_id'   => $agent1->id,
                'business_date'    => $today,
                'started_at'       => $sessionStartedAt,
                'ended_at'         => null,
                'end_reason'       => null,
            ],
            [   // agent2 — active session today
                'branch_id'        => $branch->id,
                'counter_id'       => $counter2->id,
                'user_id'          => $agent2->id,
                'active_counter_id'=> $counter2->id,
                'active_user_id'   => $agent2->id,
                'business_date'    => $today,
                'started_at'       => $sessionStartedAt,
                'ended_at'         => null,
                'end_reason'       => null,
            ],
            [   // agent3 — active session today
                'branch_id'        => $branch->id,
                'counter_id'       => $counter3->id,
                'user_id'          => $agent3->id,
                'active_counter_id'=> $counter3->id,
                'active_user_id'   => $agent3->id,
                'business_date'    => $today,
                'started_at'       => $sessionStartedAt,
                'ended_at'         => null,
                'end_reason'       => null,
            ],
            [   // agent4 — session closed yesterday (day_closed)
                'branch_id'        => $branch->id,
                'counter_id'       => $counter4->id,
                'user_id'          => $agent4->id,
                'active_counter_id'=> null,
                'active_user_id'   => null,
                'business_date'    => $yesterday,
                'started_at'       => now('Africa/Cairo')->subDay()->setTime(9, 0),
                'ended_at'         => now('Africa/Cairo')->subDay()->setTime(17, 0),
                'end_reason'       => 'day_closed',
            ],
        ]);

        // ----------------------------------------------------------------
        // Appointment slots — one shared slot per branch time (capacity 1)
        // ----------------------------------------------------------------
        $makeSlot = fn (string $time, bool $tomorrow = true) => DB::table('appointment_slots')->insertGetId([
            'branch_id'  => $branch->id,
            'starts_at'  => now('Africa/Cairo')->setTime(...array_map('intval', explode(':', $time)))->when($tomorrow, fn ($moment) => $moment->addDay()),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $slot1 = $makeSlot('10:00');
        $slot2 = $makeSlot('11:00');
        $slot3 = $makeSlot('14:00');
        $slot4 = $makeSlot('13:00');
        // A past slot (today morning) used for the checked-in appointment
        $slotPast = $makeSlot('09:00', false);

        // ----------------------------------------------------------------
        // Tickets — build today's queue across all lifecycle stages
        // ----------------------------------------------------------------

        // Helper: insert a ticket + its first "booked" event + outbox message
        // Returns the created Ticket model.
        $seq = 0;
        $version = 0;

        $bookTicket = function (
            int $serviceId,
            string $serviceName,
            ?string $phone,
            ?string $customerName,
            ?string $appointmentAt = null
        ) use ($branch, &$seq, &$version, $today): Ticket {
            $seq++;
            $version++;
            $secret     = Str::random(32);
            $bookingKey = Str::random(32);
            $ticket = Ticket::create([
                'public_id'          => (string) Str::uuid(),
                'token_hash'         => hash('sha256', $secret),
                'booking_key'        => hash('sha256', $bookingKey),
                'branch_id'          => $branch->id,
                'business_date'      => $today,
                'number'             => $seq,
                'status'             => TicketStatus::Waiting,
                'branch_service_id'  => $serviceId,
                'service_name'       => $serviceName,
                'customer_phone'     => $phone,
                'customer_name'      => $customerName,
                'appointment_at'     => $appointmentAt,
                'retry_limit'        => null,
                'retry_attempts'     => 0,
            ]);
            DB::table('ticket_events')->insert([
                'ticket_id'   => $ticket->id,
                'user_id'     => null,
                'type'        => 'booked',
                'from_status' => null,
                'to_status'   => 'waiting',
                'created_at'  => now()->subMinutes(90 - $seq * 3),
            ]);
            DB::table('outbox_messages')->insert([
                'branch_id'      => $branch->id,
                'ticket_id'      => $ticket->id,
                'ticket_public_id' => $ticket->public_id,
                'version'        => $version,
                'type'           => 'booked',
                'processed_at'   => now(),
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            return $ticket;
        };

        // ── 1. COMPLETED tickets (served earlier today) ──────────────────
        $t1 = $bookTicket($svcGeneral->id, 'خدمة عامة', '01001234567', 'خالد عبد الرحمن');
        $this->serveTicket($t1, $counter1, $agent1, $branch, $today, -80, -75, -65, $version);

        $t2 = $bookTicket($svcAccounts->id, 'فتح حسابات', '01112345678', 'منى سعيد');
        $this->serveTicket($t2, $counter2, $agent2, $branch, $today, -70, -64, -50, $version);

        $t3 = $bookTicket($svcLoans->id, 'القروض والتمويل', '01223456789', 'عمر إبراهيم');
        $this->serveTicket($t3, $counter3, $agent3, $branch, $today, -60, -55, -40, $version);

        $t4 = $bookTicket($svcComplaints->id, 'الشكاوى والاستفسارات', '01234567890', 'نور أحمد');
        $this->serveTicket($t4, $counter1, $agent1, $branch, $today, -50, -44, -32, $version);

        $t5 = $bookTicket($svcGeneral->id, 'خدمة عامة', '01001111111', 'ياسر محمود');
        $this->serveTicket($t5, $counter2, $agent2, $branch, $today, -45, -38, -25, $version);

        // ── 2. RATED completed ticket ────────────────────────────────────
        $t6 = $bookTicket($svcAccounts->id, 'فتح حسابات', '01119999999', 'ريم عصام');
        $this->serveTicket($t6, $counter3, $agent3, $branch, $today, -38, -32, -20, $version);
        $t6->update([
            'rating'         => 5,
            'rating_comment' => 'خدمة ممتازة وسريعة، شكراً جزيلاً',
            'rated_at'       => now()->subMinutes(15),
        ]);

        // ── 3. NO-SHOW ticket ────────────────────────────────────────────
        $t7 = $bookTicket($svcGeneral->id, 'خدمة عامة', '01022222222', 'سعد الغامدي');
        $calledAt7 = now()->subMinutes(30);
        $t7->update([
            'status'          => TicketStatus::NoShow,
            'counter_id'      => $counter1->id,
            'user_id'         => $agent1->id,
            'called_at'       => $calledAt7,
            'call_expires_at' => $calledAt7->copy()->addSeconds(120),
            'finished_at'     => now()->subMinutes(27),
        ]);
        $version++;
        DB::table('ticket_events')->insert([
            ['ticket_id' => $t7->id, 'user_id' => $agent1->id, 'type' => 'called',  'from_status' => 'waiting',  'to_status' => 'called',   'created_at' => $calledAt7],
            ['ticket_id' => $t7->id, 'user_id' => $agent1->id, 'type' => 'absent',  'from_status' => 'called',   'to_status' => 'no_show',  'created_at' => now()->subMinutes(27)],
        ]);

        // ── 4. CANCELLED ticket ──────────────────────────────────────────
        $t8 = $bookTicket($svcLoans->id, 'القروض والتمويل', '01233333333', 'ليلى حسين');
        $t8->update([
            'status'     => TicketStatus::Cancelled,
            'finished_at'=> now()->subMinutes(20),
        ]);
        $version++;
        DB::table('ticket_events')->insert([
            'ticket_id' => $t8->id, 'user_id' => null, 'type' => 'cancel',
            'from_status' => 'waiting', 'to_status' => 'cancelled',
            'created_at' => now()->subMinutes(20),
        ]);

        // ── 5. DEFERRED (missed-turn / retry) ticket ────────────────────
        // This customer missed their call; has 1 retry left (retry_attempts=1, retry_limit=2)
        $t9 = $bookTicket($svcGeneral->id, 'خدمة عامة', '01044444444', 'طارق نبيل');
        $calledAt9 = now()->subMinutes(18);
        $t9->update([
            'status'         => TicketStatus::Deferred,
            'retry_limit'    => 2,
            'retry_attempts' => 1,
            'deferred_at'    => now()->subMinutes(15),
            'retry_ready_at' => null,
        ]);
        $version++;
        DB::table('ticket_events')->insert([
            ['ticket_id' => $t9->id, 'user_id' => $agent2->id, 'type' => 'called',   'from_status' => 'waiting',  'to_status' => 'called',   'created_at' => $calledAt9],
            ['ticket_id' => $t9->id, 'user_id' => $agent2->id, 'type' => 'deferred', 'from_status' => 'called',   'to_status' => 'deferred', 'created_at' => now()->subMinutes(15)],
        ]);

        // ── 6. CURRENTLY CALLED ticket (at counter1) ─────────────────────
        $t10 = $bookTicket($svcGeneral->id, 'خدمة عامة', '01055555555', 'وليد فاروق');
        $calledAt10 = now()->subMinutes(1);
        $t10->update([
            'status'          => TicketStatus::Called,
            'counter_id'      => $counter1->id,
            'user_id'         => $agent1->id,
            'called_at'       => $calledAt10,
            'call_expires_at' => $calledAt10->copy()->addSeconds(120),
        ]);
        $version++;
        DB::table('ticket_events')->insert([
            'ticket_id' => $t10->id, 'user_id' => $agent1->id, 'type' => 'called',
            'from_status' => 'waiting', 'to_status' => 'called',
            'created_at' => $calledAt10,
        ]);

        // ── 7. CURRENTLY SERVING ticket (at counter2) ───────────────────
        $t11 = $bookTicket($svcLoans->id, 'القروض والتمويل', '01066666666', 'هند يوسف');
        $calledAt11 = now()->subMinutes(8);
        $t11->update([
            'status'          => TicketStatus::Serving,
            'counter_id'      => $counter2->id,
            'user_id'         => $agent2->id,
            'called_at'       => $calledAt11,
            'call_expires_at' => $calledAt11->copy()->addSeconds(120),
            'started_at'      => $calledAt11->copy()->addMinutes(1),
        ]);
        $version++;
        DB::table('ticket_events')->insert([
            ['ticket_id' => $t11->id, 'user_id' => $agent2->id, 'type' => 'called', 'from_status' => 'waiting', 'to_status' => 'called',  'created_at' => $calledAt11],
            ['ticket_id' => $t11->id, 'user_id' => $agent2->id, 'type' => 'start',  'from_status' => 'called',  'to_status' => 'serving', 'created_at' => $calledAt11->copy()->addMinutes(1)],
        ]);

        // ── 8. CURRENTLY SERVING at counter3 ────────────────────────────
        $t12 = $bookTicket($svcAccounts->id, 'فتح حسابات', '01077777777', 'دينا رامي');
        $calledAt12 = now()->subMinutes(5);
        $t12->update([
            'status'          => TicketStatus::Serving,
            'counter_id'      => $counter3->id,
            'user_id'         => $agent3->id,
            'called_at'       => $calledAt12,
            'call_expires_at' => $calledAt12->copy()->addSeconds(120),
            'started_at'      => $calledAt12->copy()->addMinutes(1),
        ]);
        $version++;
        DB::table('ticket_events')->insert([
            ['ticket_id' => $t12->id, 'user_id' => $agent3->id, 'type' => 'called', 'from_status' => 'waiting', 'to_status' => 'called',  'created_at' => $calledAt12],
            ['ticket_id' => $t12->id, 'user_id' => $agent3->id, 'type' => 'start',  'from_status' => 'called',  'to_status' => 'serving', 'created_at' => $calledAt12->copy()->addMinutes(1)],
        ]);

        // ── 9. WAITING tickets (the live queue) ──────────────────────────
        $waiting = [
            [$svcGeneral->id,    'خدمة عامة',           '01088888881', 'آدم سامي',       null],
            [$svcAccounts->id,   'فتح حسابات',          '01088888882', 'سلمى إسماعيل',   null],
            [$svcComplaints->id, 'الشكاوى والاستفسارات','01088888883', 'باسم عبد العزيز', null],
            [$svcLoans->id,      'القروض والتمويل',     '01088888884', 'نادية كريم',      null],
            [$svcGeneral->id,    'خدمة عامة',           '01088888885', 'إبراهيم حاتم',    null],
            [$svcGeneral->id,    'خدمة عامة',           '01088888886', 'شيرين طاهر',      null],
            [$svcAccounts->id,   'فتح حسابات',          '01088888887', 'زياد وليد',        null],
        ];
        foreach ($waiting as [$svcId, $svcName, $phone, $name, $appt]) {
            $bookTicket($svcId, $svcName, $phone, $name, $appt);
        }

        // ── 10. APPOINTMENT ticket (checked in, now in waiting queue) ────
        // The customer booked a slot for 09:00 today, checked in, and joined the waiting queue
        // with appointment_at so they get priority.
        $tAppt = $bookTicket($svcAccounts->id, 'فتح حسابات', '01099999999', 'ميس الرومي', now('Africa/Cairo')->setTime(9, 0)->toDateTimeString());
        // Link it to the past appointment
        $apptToken = Str::random(32);
        $apptId = DB::table('appointments')->insertGetId([
            'appointment_slot_id' => $slotPast,
            'branch_service_id'   => $svcAccounts->id,
            'ticket_id'           => $tAppt->id,
            'token_hash'          => hash('sha256', $apptToken),
            'customer_phone'      => Crypt::encryptString('01099999999'),
            'customer_name'       => Crypt::encryptString('ميس الرومي'),
            'customer_email'      => Crypt::encryptString('mays@example.com'),
            'status'              => 'checked_in',
            'created_at'          => now()->subHours(1),
            'updated_at'          => now()->subMinutes(30),
        ]);

        // ── 11. Future appointments (booked, not yet checked in) ─────────
        DB::table('appointments')->insert([
            [
                'appointment_slot_id' => $slot1,
                'branch_service_id'   => $svcAccounts->id,
                'ticket_id'           => null,
                'token_hash'          => hash('sha256', Str::random(32)),
                'customer_phone'      => Crypt::encryptString('01011110001'),
                'customer_name'       => Crypt::encryptString('رامي الشافعي'),
                'customer_email'      => null,
                'status'              => 'booked',
                'created_at'          => now()->subHours(2),
                'updated_at'          => now()->subHours(2),
            ],
            [
                'appointment_slot_id' => $slot4,
                'branch_service_id'   => $svcAccounts->id,
                'ticket_id'           => null,
                'token_hash'          => hash('sha256', Str::random(32)),
                'customer_phone'      => Crypt::encryptString('01011110002'),
                'customer_name'       => Crypt::encryptString('علياء منصور'),
                'customer_email'      => Crypt::encryptString('alya@example.com'),
                'status'              => 'booked',
                'created_at'          => now()->subHours(2),
                'updated_at'          => now()->subHours(2),
            ],
            [
                'appointment_slot_id' => $slot2,
                'branch_service_id'   => $svcAccounts->id,
                'ticket_id'           => null,
                'token_hash'          => hash('sha256', Str::random(32)),
                'customer_phone'      => Crypt::encryptString('01011110003'),
                'customer_name'       => Crypt::encryptString('كريم سلطان'),
                'customer_email'      => null,
                'status'              => 'booked',
                'created_at'          => now()->subHours(1),
                'updated_at'          => now()->subHours(1),
            ],
            [
                'appointment_slot_id' => $slot3,
                'branch_service_id'   => $svcLoans->id,
                'ticket_id'           => null,
                'token_hash'          => hash('sha256', Str::random(32)),
                'customer_phone'      => Crypt::encryptString('01011110004'),
                'customer_name'       => Crypt::encryptString('دلال عمر'),
                'customer_email'      => Crypt::encryptString('dalal@example.com'),
                'status'              => 'cancelled',
                'created_at'          => now()->subHours(3),
                'updated_at'          => now()->subMinutes(45),
            ],
        ]);

        // ── 12. Yesterday's completed tickets (historical data) ──────────
        $this->seedYesterdayTickets($branch, $svcGeneral, $svcAccounts, $svcLoans, $yesterday);

        // ----------------------------------------------------------------
        // Daily sequence — set last_number to match the highest ticket today
        // ----------------------------------------------------------------
        DB::table('daily_sequences')->insertOrIgnore([
            'branch_id'     => $branch->id,
            'business_date' => $today,
            'last_number'   => $seq,
        ]);
        // Update in case insertOrIgnore was a no-op
        DB::table('daily_sequences')
            ->where('branch_id', $branch->id)
            ->where('business_date', $today)
            ->update(['last_number' => $seq]);

        // Update branch version
        $branch->update(['version' => $version]);

        // ----------------------------------------------------------------
        // Output credentials file
        // ----------------------------------------------------------------
        $lines[] = '';
        $lines[] = '## Board';
        $lines[] = '- Branch board: ' . url("/board/{$branch->id}/{$boardSecret}");
        File::ensureDirectoryExists(storage_path('app/private'));
        File::put(storage_path('app/private/demo-access.md'), implode(PHP_EOL, $lines));

        $this->command?->info('Demo seeded successfully!');
        $this->command?->info('Credentials saved to: storage/app/private/demo-access.md');
        $this->command?->table(
            ['Role', 'Email', 'Password'],
            [
                ['admin',   'admin@example.com',   '12345678'],
                ['manager', 'manager@example.com', '12345678'],
                ['agent',   'agent1@example.com',  '12345678'],
                ['agent',   'agent2@example.com',  '12345678'],
                ['agent',   'agent3@example.com',  '12345678'],
                ['agent (inactive)', 'agent4@example.com', '12345678'],
            ]
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Take a booked (waiting) ticket through call → serve → complete
     * and write all events + outbox messages.
     */
    private function serveTicket(
        Ticket $ticket,
        Counter $counter,
        User $user,
        Branch $branch,
        string $today,
        int $calledMinsAgo,
        int $startedMinsAgo,
        int $finishedMinsAgo,
        int &$version
    ): void {
        $calledAt   = now()->subMinutes(abs($calledMinsAgo));
        $startedAt  = now()->subMinutes(abs($startedMinsAgo));
        $finishedAt = now()->subMinutes(abs($finishedMinsAgo));

        $ticket->update([
            'status'          => TicketStatus::Completed,
            'counter_id'      => $counter->id,
            'user_id'         => $user->id,
            'called_at'       => $calledAt,
            'call_expires_at' => $calledAt->copy()->addSeconds(120),
            'started_at'      => $startedAt,
            'finished_at'     => $finishedAt,
        ]);

        $version += 3;

        DB::table('ticket_events')->insert([
            ['ticket_id' => $ticket->id, 'user_id' => $user->id, 'type' => 'called',   'from_status' => 'waiting',  'to_status' => 'called',    'created_at' => $calledAt],
            ['ticket_id' => $ticket->id, 'user_id' => $user->id, 'type' => 'start',    'from_status' => 'called',   'to_status' => 'serving',   'created_at' => $startedAt],
            ['ticket_id' => $ticket->id, 'user_id' => $user->id, 'type' => 'complete', 'from_status' => 'serving',  'to_status' => 'completed', 'created_at' => $finishedAt],
        ]);

        DB::table('outbox_messages')->insert([
            ['branch_id' => $branch->id, 'ticket_id' => $ticket->id, 'ticket_public_id' => $ticket->public_id, 'version' => $version - 2, 'type' => 'called',   'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['branch_id' => $branch->id, 'ticket_id' => $ticket->id, 'ticket_public_id' => $ticket->public_id, 'version' => $version - 1, 'type' => 'start',    'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['branch_id' => $branch->id, 'ticket_id' => $ticket->id, 'ticket_public_id' => $ticket->public_id, 'version' => $version,     'type' => 'complete', 'processed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Seed a batch of fully-completed tickets for yesterday (historical reporting data).
     */
    private function seedYesterdayTickets(
        Branch $branch,
        BranchService $svcGeneral,
        BranchService $svcAccounts,
        BranchService $svcLoans,
        string $yesterday
    ): void {
        $rows = [
            [$svcGeneral->id,  'خدمة عامة',       '01001000001', 'حمزة رضا'],
            [$svcGeneral->id,  'خدمة عامة',       '01001000002', 'جمال سرور'],
            [$svcAccounts->id, 'فتح حسابات',      '01001000003', 'سوسن الطيب'],
            [$svcLoans->id,    'القروض والتمويل', '01001000004', 'بشار نورالدين'],
            [$svcGeneral->id,  'خدمة عامة',       '01001000005', 'عبير قاسم'],
            [$svcAccounts->id, 'فتح حسابات',      '01001000006', 'فادي منير'],
            [$svcGeneral->id,  'خدمة عامة',       '01001000007', 'ماجدة سالم'],
            [$svcLoans->id,    'القروض والتمويل', '01001000008', 'نزار علاء'],
        ];

        // Yesterday's daily sequence
        DB::table('daily_sequences')->insertOrIgnore([
            'branch_id'     => $branch->id,
            'business_date' => $yesterday,
            'last_number'   => count($rows),
        ]);

        $version = 0;
        foreach ($rows as $i => [$svcId, $svcName, $phone, $name]) {
            $num        = $i + 1;
            $offset     = (count($rows) - $i) * 20; // tickets were processed throughout the day
            $calledAt   = now()->subDay()->subMinutes($offset + 10);
            $startedAt  = $calledAt->copy()->addMinutes(1);
            $finishedAt = $calledAt->copy()->addMinutes(rand(5, 20));
            $version++;

            $ticket = Ticket::create([
                'public_id'         => (string) Str::uuid(),
                'token_hash'        => hash('sha256', Str::random(32)),
                'booking_key'       => hash('sha256', Str::random(32)),
                'branch_id'         => $branch->id,
                'business_date'     => $yesterday,
                'number'            => $num,
                'status'            => TicketStatus::Completed,
                'branch_service_id' => $svcId,
                'service_name'      => $svcName,
                'customer_phone'    => $phone,
                'customer_name'     => $name,
                'called_at'         => $calledAt,
                'call_expires_at'   => $calledAt->copy()->addSeconds(120),
                'started_at'        => $startedAt,
                'finished_at'       => $finishedAt,
            ]);

            DB::table('ticket_events')->insert([
                ['ticket_id' => $ticket->id, 'user_id' => null, 'type' => 'booked',   'from_status' => null,      'to_status' => 'waiting',   'created_at' => $calledAt->copy()->subMinutes(rand(5, 30))],
                ['ticket_id' => $ticket->id, 'user_id' => null, 'type' => 'called',   'from_status' => 'waiting', 'to_status' => 'called',    'created_at' => $calledAt],
                ['ticket_id' => $ticket->id, 'user_id' => null, 'type' => 'start',    'from_status' => 'called',  'to_status' => 'serving',   'created_at' => $startedAt],
                ['ticket_id' => $ticket->id, 'user_id' => null, 'type' => 'complete', 'from_status' => 'serving', 'to_status' => 'completed', 'created_at' => $finishedAt],
            ]);
        }
    }
}
