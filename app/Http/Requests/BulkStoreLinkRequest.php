<?php

namespace App\Http\Requests;

use App\Services\TargetUrlValidator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class BulkStoreLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $max = (int) config('linkforge.api.max_bulk_links', 100);

        return [
            'links' => ['required', 'array', 'min:1', "max:{$max}"],
            'links.*.target_url' => ['required', 'string', 'max:2048'],
            'links.*.title' => ['nullable', 'string', 'max:255'],
            'links.*.expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $urls = app(TargetUrlValidator::class);

            foreach ((array) $this->input('links', []) as $index => $link) {
                $target = (string) ($link['target_url'] ?? '');

                if ($target !== '' && $error = $urls->validate($target)) {
                    $validator->errors()->add("links.{$index}.target_url", $error);
                }
            }
        });
    }
}
