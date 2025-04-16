<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EmptyRequestCheck extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'amount' => 'required|numeric',
            'bank_name' => 'required|string',
            'account_number' => 'required|string',
            'account_name' => 'required|string',
            'notes' => 'nullable|string'
        ];
    }

    /**
     * Check if the request body is empty
     */
    public function isEmpty(): bool
    {
        return empty($this->input());
    }
}
