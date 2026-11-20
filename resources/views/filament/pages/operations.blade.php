<x-filament-panels::page>
<div class="queue-grid">
    @if($errors->any())<div role="alert">{{ $errors->first() }}</div>@endif
    <section class="queue-card">
        <div class="queue-title">{{ __('queue.ops_current_branch') }}</div>
        <select class="queue-input" wire:model.live="selectedBranch" aria-label="{{ __('queue.ops_current_branch') }}"><option value="">{{ __('queue.ops_select_branch') }}</option>@foreach($branches as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</select>
        @if($branch)
            <p style="margin:16px 0">{{ $branch->is_open ? __('queue.ops_booking_on') : __('queue.ops_booking_off') }} — {{ __('queue.hours_today') }}: {{ $branch->hoursLabelFor() }}</p>
            <div class="queue-row"><x-filament::button color="gray" wire:click="toggleBooking">{{ __('queue.ops_toggle_booking') }}</x-filament::button><a class="queue-link" href="{{ route('branch.show', $branch) }}" target="_blank" rel="noopener">{{ __('queue.ops_customer_page') }}</a><a class="queue-link" href="{{ route('branch.qr', $branch) }}">{{ __('queue.ops_qr') }}</a></div>

            <form wire:submit="saveHours" style="margin-top:20px">
                <div class="queue-title">{{ __('queue.ops_weekly_hours_title') }}</div>
                <p class="queue-muted">{{ __('queue.ops_hours_note') }}</p>
                <div class="queue-row" style="margin:12px 0">
                    <label>{{ __('queue.ops_work_start') }} <input class="queue-input" type="time" wire:model="opensAt" required></label>
                    <label>{{ __('queue.ops_work_end') }} <input class="queue-input" type="time" wire:model="closesAt" required></label>
                </div>
                <p class="queue-muted">{{ __('queue.ops_quick_hours_note') }}</p>
                @php($dayKeys = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'])
                <div style="overflow-x:auto">
                    <table class="queue-table weekly-hours-table">
                        <thead><tr>
                            <th>{{ __('queue.ops_hours_day') }}</th>
                            <th>{{ __('queue.ops_hours_closed') }}</th>
                            <th>{{ __('queue.ops_work_start') }}</th>
                            <th>{{ __('queue.ops_work_end') }}</th>
                        </tr></thead>
                        <tbody>
                        @foreach(range(0, 6) as $day)
                            <tr @class(['is-today' => $day === now($branch->timezone)->dayOfWeek])>
                                <td>{{ __('queue.day_'.$dayKeys[$day]) }}@if($day === now($branch->timezone)->dayOfWeek) <small>({{ __('queue.hours_today') }})</small>@endif</td>
                                <td><input type="checkbox" wire:model="weeklyHours.{{ $day }}.is_closed" aria-label="{{ __('queue.ops_hours_closed') }} — {{ __('queue.day_'.$dayKeys[$day]) }}"></td>
                                <td><input class="queue-input" type="time" wire:model="weeklyHours.{{ $day }}.opens_at" @disabled($weeklyHours[$day]['is_closed'] ?? false) @required(empty($weeklyHours[$day]['is_closed']))></td>
                                <td><input class="queue-input" type="time" wire:model="weeklyHours.{{ $day }}.closes_at" @disabled($weeklyHours[$day]['is_closed'] ?? false) @required(empty($weeklyHours[$day]['is_closed']))></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @error('weeklyHours')<p role="alert">{{ $message }}</p>@enderror
                @foreach(range(0, 6) as $day)
                    @error("weeklyHours.$day.opens_at")<p role="alert">{{ $message }}</p>@enderror
                    @error("weeklyHours.$day.closes_at")<p role="alert">{{ $message }}</p>@enderror
                @endforeach
                <x-filament::button type="submit" style="margin-top:16px">{{ __('queue.ops_save_hours') }}</x-filament::button>
            </form>
        @endif
    </section>
    @if(auth()->user()->role === 'admin')<section class="queue-card"><div class="queue-title">{{ __('queue.ops_add_branch') }}</div><form wire:submit="createBranch" class="queue-row"><input class="queue-input" wire:model="branchName" placeholder="{{ __('queue.ops_branch_name') }}" aria-label="{{ __('queue.ops_branch_name') }}" required><x-filament::button type="submit">{{ __('queue.ops_add_branch') }}</x-filament::button></form><p class="queue-muted">{{ __('queue.ops_branch_note') }}</p></section>@endif
    @if(auth()->user()->role === 'admin')
    <section class="queue-card">
        <div class="queue-title">{{ __('queue.ops_prune_schedule_title') }}</div>
        <p class="queue-muted">{{ __('queue.ops_prune_schedule_note') }}</p>
        <form wire:submit="savePruneTime" class="queue-row">
            <label>{{ __('queue.ops_prune_time_label') }} <input class="queue-input" type="time" wire:model="pruneTime" required></label>
            <x-filament::button type="submit">{{ __('queue.ops_save_prune_time') }}</x-filament::button>
        </form>
        @error('pruneTime')<p role="alert" style="color:#dc2626;margin-top:6px">{{ $message }}</p>@enderror
    </section>
    @endif
    @if($branch && auth()->user()->role === 'admin')
    <section class="queue-card"><div class="queue-title">{{ __('queue.ops_automation_title') }}</div><form wire:submit="saveAutomation" class="queue-row"><label>{{ __('queue.ops_queue_mode') }} <select class="queue-input" wire:model="automaticQueue"><option value="0">{{ __('queue.ops_mode_manual') }}</option><option value="1">{{ __('queue.ops_mode_auto') }}</option></select></label><label>{{ __('queue.ops_no_show_seconds') }} <input class="queue-input" type="number" wire:model="noShowSeconds" min="30" max="900" required></label><label>{{ __('queue.ops_missed_retries') }} <input class="queue-input" type="number" wire:model="missedTurnRetries" min="0" max="10" required></label>
    <label>{{ __('queue.ops_display_locale') }} <select class="queue-input" wire:model="displayLocale"><option value="ar">عربي</option><option value="en">English</option></select></label>
    <label>{{ __('queue.ops_panel_locale') }} <select class="queue-input" wire:model="panelLocale"><option value="ar">عربي</option><option value="en">English</option></select></label>
    <x-filament::button type="submit">{{ __('queue.ops_save_automation') }}</x-filament::button></form><p class="queue-muted">{{ __('queue.ops_automation_note') }}</p></section>
    @endif
    @if($branch)
    <section class="queue-card"><div class="queue-title">{{ __('queue.ops_board_title') }}</div><x-filament::button color="gray" wire:click="rotateBoard">{{ __('queue.ops_board_rotate') }}</x-filament::button>@if($boardUrl)<p><a class="queue-link" href="{{ $boardUrl }}" target="_blank" rel="noopener">{{ $boardUrl }}</a></p>@endif<p class="queue-muted">{{ __('queue.ops_board_note') }}</p></section>
    <section class="queue-card"><div class="queue-title">{{ __('queue.ops_services_title') }}</div><p class="queue-muted">{{ __('queue.ops_services_note') }}</p><form wire:submit="saveBranchServices"><div class="queue-row">@foreach($allServices as $service)<label><input type="checkbox" wire:model="branchServiceIds" value="{{ $service->id }}"> {{ $service->name }}</label>@endforeach</div>@if($allServices->isEmpty())<p class="queue-muted">{{ __('queue.ops_no_global_services') }}</p>@endif<div class="queue-row" style="margin-top:16px"><x-filament::button type="submit" wire:loading.attr="disabled">{{ __('queue.ops_save_branch_services') }}</x-filament::button></div></form><table class="queue-table"><thead><tr><th>{{ __('queue.ops_svc_col_name') }}</th><th>{{ __('queue.ops_svc_col_status') }}</th><th>{{ __('queue.ops_svc_col_action') }}</th></tr></thead><tbody>@foreach($branch->services()->orderBy('service_id')->get() as $service)<tr><td>{{ $service->name }}</td><td>{{ $service->is_active ? __('queue.ops_svc_active') : __('queue.ops_svc_inactive') }}</td><td><x-filament::button size="sm" color="gray" wire:click="toggleService({{ $service->id }})">{{ $service->is_active ? __('queue.ops_svc_disable') : __('queue.ops_svc_enable') }}</x-filament::button></td></tr>@endforeach</tbody></table></section>
    <section class="queue-card"><div class="queue-title">{{ __('queue.ops_add_employee') }}</div><form wire:submit="createEmployee" class="queue-row"><input class="queue-input" wire:model="employeeName" placeholder="{{ __('queue.ops_emp_name') }}" aria-label="{{ __('queue.ops_emp_name') }}" required><input class="queue-input" type="email" wire:model="employeeEmail" placeholder="{{ __('queue.ops_emp_email') }}" aria-label="{{ __('queue.ops_emp_email') }}" required><input class="queue-input" type="password" wire:model="employeePassword" placeholder="{{ __('queue.ops_emp_password') }}" aria-label="{{ __('queue.ops_emp_password') }}" autocomplete="new-password" required minlength="12">@if(auth()->user()->role === 'admin')<select class="queue-input" wire:model="employeeRole" aria-label="{{ __('queue.ops_emp_role') }}"><option value="agent">{{ __('queue.ops_role_agent') }}</option><option value="manager">{{ __('queue.ops_role_manager') }}</option></select>@endif<x-filament::button type="submit">{{ __('queue.ops_add_emp_btn') }}</x-filament::button></form></section>
    <section class="queue-card" style="overflow-x:auto"><div class="queue-title">{{ __('queue.ops_employees_title') }}</div><table class="queue-table"><thead><tr><th>{{ __('queue.ops_col_name') }}</th><th>{{ __('queue.ops_col_email') }}</th><th>{{ __('queue.ops_col_role') }}</th><th>{{ __('queue.ops_col_status') }}</th><th>{{ __('queue.ops_col_services') }}</th><th>{{ __('queue.ops_col_action') }}</th></tr></thead><tbody>@foreach($employees as $employee)<tr><td>{{ $employee->name }}</td><td>{{ $employee->email }}</td><td>{{ [
        'admin'   => __('queue.ops_role_admin'),
        'manager' => __('queue.ops_role_manager'),
        'agent'   => __('queue.ops_role_agent'),
    ][$employee->role] ?? $employee->role }}</td><td>{{ $employee->is_active ? __('queue.ops_emp_active') : __('queue.ops_emp_inactive') }}</td><td>{{ $employee->services->where('branch_id', $branch->id)->pluck('name')->join('، ') ?: __('queue.ops_no_services_assigned') }} @if(auth()->user()->role === 'admin')<x-filament::button size="sm" color="gray" wire:click="editEmployeeServices({{ $employee->id }})">{{ __('queue.ops_set_services') }}</x-filament::button>@endif</td><td>@if(auth()->user()->role === 'admin' || $employee->role === 'agent')<x-filament::button size="sm" color="gray" wire:click="editEmployee({{ $employee->id }})">{{ __('queue.ops_edit_account') }}</x-filament::button>@endif</td></tr>@endforeach</tbody></table></section>
    @if($serviceEmployeeId && auth()->user()->role === 'admin')
    <section class="queue-card"><div class="queue-title">{{ __('queue.ops_emp_services_title', ['name' => $employees->firstWhere('id', $serviceEmployeeId)?->name]) }}</div><p class="queue-muted">{{ __('queue.ops_emp_services_note') }}</p><form wire:submit="saveEmployeeServices"><div class="queue-row">@foreach($branch->services()->join('services', 'services.id', '=', 'branch_services.service_id')->orderBy('services.name')->select('branch_services.*')->get() as $item)<label><input type="checkbox" wire:model="employeeServiceIds" value="{{ $item->id }}"> {{ $item->name }} {{ $item->is_active ? '' : __('queue.ops_svc_closed_note') }}</label>@endforeach</div>@error('employeeServiceIds')<p role="alert">{{ $message }}</p>@enderror<div class="queue-row" style="margin-top:16px"><x-filament::button type="submit" wire:loading.attr="disabled">{{ __('queue.ops_save_emp_services') }}</x-filament::button><x-filament::button color="gray" wire:click="closeEmployeeServices">{{ __('queue.ops_cancel') }}</x-filament::button></div></form></section>
    @endif
    @if($editingEmployeeId)
    <section class="queue-card"><div class="queue-title">{{ __('queue.ops_edit_emp_title') }}</div><form wire:submit="saveEmployee" class="queue-grid"><div class="queue-row"><label>{{ __('queue.ops_col_name') }} <input class="queue-input" wire:model="editEmployeeName" required></label><label>{{ __('queue.ops_col_email') }} <input class="queue-input" type="email" wire:model="editEmployeeEmail" required></label>@if(auth()->user()->role === 'admin')<label>{{ __('queue.ops_emp_role') }} <select class="queue-input" wire:model="editEmployeeRole"><option value="agent">{{ __('queue.ops_role_agent') }}</option><option value="manager">{{ __('queue.ops_role_manager') }}</option><option value="admin">{{ __('queue.ops_role_admin') }}</option></select></label>@endif<label><input type="checkbox" wire:model="editEmployeeActive"> {{ __('queue.ops_emp_active_checkbox') }}</label></div><label>{{ __('queue.ops_emp_new_password') }} <input class="queue-input" type="password" wire:model="editEmployeePassword" autocomplete="new-password" minlength="12"></label><p class="queue-muted">{{ __('queue.ops_edit_emp_note') }}</p><div class="queue-row"><x-filament::button type="submit">{{ __('queue.ops_save_account') }}</x-filament::button><x-filament::button color="gray" type="button" wire:click="closeEmployee">{{ __('queue.ops_cancel_edit') }}</x-filament::button></div></form></section>
    @endif
    <section class="queue-card"><div class="queue-title">{{ __('queue.ops_add_counter') }}</div><form wire:submit="createCounter" class="queue-row"><input class="queue-input" wire:model="counterName" placeholder="{{ __('queue.ops_counter_name') }}" aria-label="{{ __('queue.ops_counter_name') }}" required><input class="queue-input" type="number" wire:model="counterNumber" min="1" aria-label="{{ __('queue.ops_counter_number') }}" required><select class="queue-input" wire:model="employeeId" aria-label="{{ __('queue.ops_col_employee') }}"><option value="">{{ __('queue.ops_no_employee') }}</option>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->name }}</option>@endforeach</select><x-filament::button type="submit">{{ __('queue.ops_add_counter_btn') }}</x-filament::button></form></section>
    @if($displayUrl)<section class="queue-card"><div class="queue-title">{{ __('queue.ops_new_display_title') }}</div><a class="queue-link" href="{{ $displayUrl }}" target="_blank" rel="noopener">{{ $displayUrl }}</a><p class="queue-muted">{{ __('queue.ops_display_note') }}</p></section>@endif
    <section class="queue-card" style="overflow-x:auto"><div class="queue-title">{{ __('queue.ops_counters_title') }}</div>
        @if($editingCounterId)
        <form wire:submit="saveCounter" class="queue-row" style="margin-bottom:20px"><label>{{ __('queue.ops_counter_name') }} <input class="queue-input" wire:model="editCounterName" required></label><label>{{ __('queue.ops_counter_number') }} <input class="queue-input" type="number" wire:model="editCounterNumber" min="1" required></label><x-filament::button type="submit">{{ __('queue.ops_save_counter') }}</x-filament::button><x-filament::button color="gray" type="button" wire:click="closeCounter">{{ __('queue.ops_cancel_counter') }}</x-filament::button></form>
        @endif
        <p class="queue-muted">{{ __('queue.ops_assign_note') }}</p>
        <select class="queue-input" wire:model="assignmentEmployeeId" aria-label="{{ __('queue.ops_col_assign') }}"><option value="">{{ __('queue.ops_no_employee') }}</option>@foreach($assignableEmployees as $employee)<option value="{{ $employee->id }}">{{ $employee->name }}</option>@endforeach</select>
        @error('assignmentEmployeeId')<p role="alert" style="color:#dc2626;margin-top:6px">{{ $message }}</p>@enderror
        <table class="queue-table"><thead><tr><th>{{ __('queue.ops_col_counter') }}</th><th>{{ __('queue.ops_col_employee') }}</th><th>{{ __('queue.ops_col_assign') }}</th><th>{{ __('queue.ops_col_display') }}</th><th>{{ __('queue.ops_col_data') }}</th></tr></thead><tbody>@foreach($counters as $counter)<tr><td>{{ $counter->name }}</td><td>{{ $counter->user?->name ?? __('queue.ops_unassigned') }}</td><td><x-filament::button size="sm" color="gray" wire:click="assignEmployee({{ $counter->id }})">{{ __('queue.ops_assign_btn') }}</x-filament::button></td><td><x-filament::button size="sm" color="gray" wire:click="rotateDisplay({{ $counter->id }})">{{ __('queue.ops_new_display_btn') }}</x-filament::button>@if($counter->displayUrl())<a class="queue-link" style="display:block;margin-top:6px;font-size:12px;word-break:break-all" href="{{ $counter->displayUrl() }}" target="_blank" rel="noopener">{{ $counter->displayUrl() }}</a>@endif</td><td><x-filament::button size="sm" color="gray" wire:click="editCounter({{ $counter->id }})">{{ __('queue.ops_edit_counter_btn') }}</x-filament::button></td></tr>@endforeach</tbody></table>
    </section>
    @endif
    @if($branch && $counterSessions->isNotEmpty())
    <section class="queue-card" style="overflow-x:auto"><div class="queue-title">{{ __('queue.ops_edit_counter_title') }}</div><p class="queue-muted">{{ __('queue.ops_sessions_note') }}</p><table class="queue-table"><thead><tr><th>{{ __('queue.ops_col_employee') }}</th><th>{{ __('queue.ops_col_counter') }}</th><th>{{ __('queue.ops_col_start') }}</th><th>{{ __('queue.ops_col_end') }}</th><th>{{ __('queue.ops_col_reason') }}</th></tr></thead><tbody>@foreach($counterSessions as $session)<tr><td>{{ $session->user?->name ?? __('queue.ops_no_account') }}</td><td>{{ $session->counter->name }}</td><td>{{ $session->started_at->timezone($branch->timezone)->format('Y-m-d H:i') }}</td><td>{{ $session->ended_at?->timezone($branch->timezone)->format('Y-m-d H:i') ?? '—' }}</td><td>{{ $session->endReasonLabel() }}</td></tr>@endforeach</tbody></table></section>
    @endif
</div>
</x-filament-panels::page>
