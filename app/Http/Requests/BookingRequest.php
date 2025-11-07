<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BookingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'user_id' => 'integer',
            'event_id' => 'required|integer',
            'first_name' => 'string',
            'last_name' => 'string',
            'email' => 'required|email',
            'phone_no' => 'required|string',
            'address_line_one' => 'string',
            'city' => 'string',
            'state' => 'string',
            'country' => 'string',
            'zip_code' => 'string',
        ];
    }
}
