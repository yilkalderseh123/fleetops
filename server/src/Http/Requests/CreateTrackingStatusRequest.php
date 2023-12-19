<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Rules\ResolvablePoint;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Requests\FleetbaseRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CreateTrackingStatusRequest extends FleetbaseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return request()->session()->has('api_credential');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $validations = [
            'tracking_number' => array_filter([
                'required',
                $this->isMethod('POST') ? Rule::exists('tracking_numbers', 'public_id') : null,
                $this->isMethod('POST') ? static::uniqueStatus($this) : null,
            ]),
            'location' => [new ResolvablePoint()],
            'code'    => 'nullable|string|min:3',
            'status'  => ['required', 'string', 'min:3'],
            'details' => 'nullable|string'
        ];

        if ($this->isMethod('POST') && $this->missing('tracking_number') && $this->has('order')) {
            $validations['tracking_number'] = 'nullable';
            $validations['order'] = [
                'required',
                Rule::exists('orders', 'public_id'),
                static::uniqueStatus($this)
            ];
        }

        if ($this->missing(['latitude', 'longitude'])) {
            $validations['location'] = ['required', new ResolvablePoint()];
        } else if ($this->missing('location')) {
            $validations['latitude'] = 'required';
            $validations['longitude'] = 'required';
        }

        return $validations;
    }

    /**
     * Custom rule to ensure status is unique for an order or tracking number.
     *
     * @return \Closure
     */
    private static function uniqueStatus($request)
    {
        return function ($attribute, $value, $fail) use ($request) {
            $trackingNumber = $request->input('tracking_number');
            $orderId = $request->input('order');
            $status = $request->input('status');
            $exists = false;

            // if duplicate status enabled skip
            if ($request->boolean('duplicate')) {
                return;
            }

            if ($trackingNumber) {
                $exists = TrackingNumber::where('public_id', $trackingNumber)->whereHas('statuses', function ($query) use ($status) {
                    $query->where('status', $status);
                    $query->orWhere('status', Str::title($status));
                })->exists();
            } else if ($orderId) {
                $exists = Order::where('public_id', $orderId)->whereHas('trackingNumber', function ($query) use ($status) {
                    $query->whereHas('statuses', function ($query) use ($status) {
                        $query->where('status', $status);
                        $query->orWhere('status', Str::title($status));
                    });
                })->exists();
            }

            if ($exists) {
                $fail('The status "' . Str::title($status) . '" has already been applied to the order.');
            }
        };
    }
}
