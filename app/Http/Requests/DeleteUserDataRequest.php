<?php

namespace App\Http\Requests;

use App\Enums\UserDataType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeleteUserDataRequest extends FormRequest
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
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'deleteOptions' => ['required', 'array'],
            'deleteOptions.*' => ['string', Rule::enum(UserDataType::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'deleteOptions.*.enum' => 'There is nothing to delete by that name.',
        ];
    }
}
