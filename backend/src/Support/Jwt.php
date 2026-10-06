<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\AuthenticationException;
use JsonException;
use RuntimeException;

final class Jwt
{
    public function __construct(
        private readonly string $secret,
        private readonly int $ttl,
    ) {
        if (strlen($this->secret) < 32) {
            throw new RuntimeException('JWT_SECRET debe tener al menos 32 caracteres.');
        }
        if ($this->ttl < 60) {
            throw new RuntimeException('JWT_TTL debe ser al menos 60 segundos.');
        }
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    /** @return array{token:string,expires_in:int} */
    public function issue(int $userId): array
    {
        $now = time();
        $token = $this->sign([
            'sub' => $userId,
            'iat' => $now,
            'exp' => $now + $this->ttl,
            'jti' => bin2hex(random_bytes(16)),
        ]);

        return [
            'token' => $token,
            'expires_in' => $this->ttl,
        ];
    }

    /** @param array<string, int|string> $claims */
    public function sign(array $claims): string
    {
        try {
            $header = $this->base64UrlEncode(json_encode(
                ['typ' => 'JWT', 'alg' => 'HS256'],
                JSON_THROW_ON_ERROR
            ));
            $payload = $this->base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            throw new RuntimeException('No se pudo emitir el token.');
        }

        $signature = $this->base64UrlEncode(hash_hmac('sha256', $header . '.' . $payload, $this->secret, true));

        return $header . '.' . $payload . '.' . $signature;
    }

    /** @return array<string, mixed> */
    public function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] === '' || $parts[1] === '' || $parts[2] === '') {
            throw new AuthenticationException('AUTH_TOKEN_INVALID', 'El token no es válido.');
        }

        try {
            $header = json_decode($this->base64UrlDecode($parts[0]), true, 512, JSON_THROW_ON_ERROR);
            $payload = json_decode($this->base64UrlDecode($parts[1]), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AuthenticationException('AUTH_TOKEN_INVALID', 'El token no es válido.');
        }

        if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256' || ($header['typ'] ?? null) !== 'JWT') {
            throw new AuthenticationException('AUTH_TOKEN_INVALID', 'El token no es válido.');
        }

        $expected = $this->base64UrlEncode(hash_hmac('sha256', $parts[0] . '.' . $parts[1], $this->secret, true));
        if (!hash_equals($expected, $parts[2])) {
            throw new AuthenticationException('AUTH_TOKEN_INVALID', 'El token no es válido.');
        }

        if (!is_array($payload) || !isset($payload['sub'], $payload['iat'], $payload['exp'], $payload['jti'])) {
            throw new AuthenticationException('AUTH_TOKEN_INVALID', 'El token no es válido.');
        }
        if (!is_numeric($payload['sub']) || (int) $payload['sub'] <= 0) {
            throw new AuthenticationException('AUTH_TOKEN_INVALID', 'El token no es válido.');
        }
        if (!is_numeric($payload['exp'])) {
            throw new AuthenticationException('AUTH_TOKEN_INVALID', 'El token no es válido.');
        }
        if ((int) $payload['exp'] < time()) {
            throw new AuthenticationException('AUTH_TOKEN_EXPIRED', 'El token ha expirado.');
        }

        $payload['sub'] = (int) $payload['sub'];

        return $payload;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new AuthenticationException('AUTH_TOKEN_INVALID', 'El token no es válido.');
        }

        return $decoded;
    }
}
