<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * C-1 予約照会（詳細設計10.1）。電話で聞いた番号を手で打つ画面のため、
 * 大文字化と全角→半角の正規化を先に通してから検証する。
 */
class LookupReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => $this->normalize($this->input('code')),
            'email' => $this->normalize($this->input('email')),
        ]);
    }

    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strtoupper(mb_convert_kana(trim($value), 'as'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'regex:/\A[A-Z0-9]{3}-[A-Z0-9]{5}-[A-Z0-9]{5}\z/'],
            'email' => ['required', 'email:rfc', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.required' => '予約番号を入力してください',
            'code.regex' => '予約番号の形式が正しくありません',
            'email.required' => 'メールアドレスを入力してください',
            'email.email' => 'メールアドレスの形式が正しくありません',
            'email.max' => 'メールアドレスの形式が正しくありません',
        ];
    }
}
