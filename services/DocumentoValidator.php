<?php

class DocumentoValidator
{
    public static function onlyDigits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    public static function isCpf(string $cpf): bool
    {
        $cpf = self::onlyDigits($cpf);

        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;

            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $cpf[$i] * (($t + 1) - $i);
            }

            $digit = ((10 * $sum) % 11) % 10;

            if ((int) $cpf[$t] !== $digit) {
                return false;
            }
        }

        return true;
    }

    public static function isCnpj(string $cnpj): bool
    {
        $cnpj = self::onlyDigits($cnpj);

        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        $weights = [
            [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2],
            [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2],
        ];

        for ($digitIndex = 0; $digitIndex < 2; $digitIndex++) {
            $sum = 0;

            foreach ($weights[$digitIndex] as $index => $weight) {
                $sum += (int) $cnpj[$index] * $weight;
            }

            $remainder = $sum % 11;
            $digit = $remainder < 2 ? 0 : 11 - $remainder;

            if ((int) $cnpj[12 + $digitIndex] !== $digit) {
                return false;
            }
        }

        return true;
    }

    public static function detectType(string $documento): ?string
    {
        $digits = self::onlyDigits($documento);

        if (strlen($digits) === 11 && self::isCpf($digits)) {
            return 'cpf';
        }

        if (strlen($digits) === 14 && self::isCnpj($digits)) {
            return 'cnpj';
        }

        return null;
    }
}
