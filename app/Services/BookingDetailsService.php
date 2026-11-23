<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BookingDetailsService
{
    public function validate(Branch $branch, array $input, bool $phoneRequired = true, bool $allowInactive = false): array
    {
        if (is_string($input['customer_phone'] ?? null)) {
            $phone = strtr(trim($input['customer_phone']), array_combine(preg_split('//u', '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));
            $phone = preg_replace('/[\s()\-]/u', '', $phone);
            $input['customer_phone'] = str_starts_with($phone, '00') ? '+'.substr($phone, 2) : $phone;
        }
        $exists = Rule::exists('branch_services', 'id')->where('branch_id', $branch->id);
        if (! $allowInactive) {
            $exists->where('is_active', true);
        }
        $data = Validator::make($input, [
            'service_id' => ['required', 'integer', $exists],
            'customer_phone' => [$phoneRequired ? 'required' : 'nullable', 'string', 'max:20', 'regex:/^(?:01[0125][0-9]{8}|\+[1-9][0-9]{7,14})$/'],
        ], [
            'service_id.required' => __('queue.err_booking_details_service'),
            'service_id.exists'   => __('queue.err_booking_details_no_service'),
            'customer_phone.required' => __('queue.err_booking_details_phone'),
            'customer_phone.regex'    => __('queue.err_booking_details_phone_fmt'),
        ])->validate();
        $service = BranchService::where('branch_id', $branch->id)->when(! $allowInactive, fn ($q) => $q->where('is_active', true))->findOrFail($data['service_id']);

        return ['branch_service_id' => $service->id, 'service_name' => $service->name, 'customer_phone' => $data['customer_phone'] ?? null];
    }
}
