<?php

namespace App\Http\Requests;

use App\Services\SlugGenerator;
use App\Services\TargetUrlValidator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_url' => ['required', 'string', 'max:2048'],
            'alias' => ['nullable', 'string', 'max:32'],
            'title' => ['nullable', 'string', 'max:255'],
            'redirect_status' => ['nullable', 'integer', 'in:301,302,307,308'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'max_clicks' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'meta' => ['nullable', 'array'],
        ];
    }

    /**
     * Scheme/host safety and alias availability are domain rules rather than
     * shape rules, so they run after the basic validation passes.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $target = (string) $this->input('target_url');

            if ($target !== '' && $error = app(TargetUrlValidator::class)->validate($target)) {
                $validator->errors()->add('target_url', $error);
            }

            $alias = $this->input('alias');

            if (is_string($alias) && $alias !== '' && $error = app(SlugGenerator::class)->validateAlias($alias)) {
                $validator->errors()->add('alias', $error);
            }
        });
    }
}
