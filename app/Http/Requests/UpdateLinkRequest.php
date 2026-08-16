<?php

namespace App\Http\Requests;

use App\Services\TargetUrlValidator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The slug is deliberately immutable: printed links and QR codes already point
 * at it. Re-targeting is done by changing target_url, which is exactly why the
 * default redirect status is 302 rather than a browser-cached 301.
 */
class UpdateLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_url' => ['sometimes', 'string', 'max:2048'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'redirect_status' => ['sometimes', 'integer', 'in:301,302,307,308'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'max_clicks' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'meta' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->has('target_url')) {
                return;
            }

            if ($error = app(TargetUrlValidator::class)->validate((string) $this->input('target_url'))) {
                $validator->errors()->add('target_url', $error);
            }
        });
    }
}
