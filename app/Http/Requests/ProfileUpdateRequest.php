<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'nim' => preg_replace('/\s+/', '', (string) $this->input('nim')),
            'kelas' => strtoupper(trim((string) $this->input('kelas'))),
        ]);
    }

    public function rules(): array
    {
        $isMahasiswa = $this->user()?->isMahasiswa();

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($this->user()->id)],
        ];

        // Peran TIDAK dapat diubah dari form ini. NIM & kelas hanya untuk mahasiswa.
        if ($isMahasiswa) {
            $rules['nim'] = ['required', 'regex:/^[0-9]{6,20}$/', Rule::unique(User::class)->ignore($this->user()->id)];
            $rules['kelas'] = ['required', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9 \-\/]*$/'];
        }

        return $rules;
    }
}
