<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;

final class LoginValidator
{
    /**
     * @param array<string, mixed> $input
     * @return array{email:string,password:string}
     */
    public function validate(array $input): array
    {
        $errors = [];
        $email = isset($input['email']) ? trim((string) $input['email']) : '';
        $password = array_key_exists('password', $input) ? (string) $input['password'] : '';

        if ($email === '') {
            $errors['email'][] = 'El correo es obligatorio.';
        } elseif (strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'][] = 'El correo no es válido.';
        }

        if (!array_key_exists('password', $input) || $password === '') {
            $errors['password'][] = 'La contraseña es obligatoria.';
        } elseif (strlen($password) > 200) {
            $errors['password'][] = 'La contraseña no es válida.';
        }

        if ($errors !== []) {
            throw new ValidationException('Los datos enviados no son válidos.', $errors);
        }

        return [
            'email' => $email,
            'password' => $password,
        ];
    }
}
