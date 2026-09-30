<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
        ];
    }

    /**
     * A project must never be left with nobody to run it.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $projects = $this->user()->projectsNeedingAnOwner();

                if ($projects->isNotEmpty()) {
                    $validator->errors()->add('projects', trans_choice(
                        'You alone run :names, and others are in it. Make one of them an owner first.|You alone run :names, and others are in them. Make one of their members an owner first.',
                        $projects->count(),
                        ['names' => $projects->pluck('name')->map(fn (string $name) => "“{$name}”")->join(', ', ' and ')],
                    ));
                }
            },
        ];
    }
}
