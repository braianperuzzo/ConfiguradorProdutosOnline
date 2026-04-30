<?php
class JWTHelper {
    public static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    public static function encode(array $payload, string $secret): string {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $segments = [];
        $segments[] = self::base64UrlEncode(json_encode($header));
        $segments[] = self::base64UrlEncode(json_encode($payload));
        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $secret, true);
        $segments[] = self::base64UrlEncode($signature);
        return implode('.', $segments);
    }

    public static function decode(string $jwt, string $secret): ?array {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        list($header64, $payload64, $signature64) = $parts;
        $header = json_decode(self::base64UrlDecode($header64), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
            return null;
        }
        $payload = json_decode(self::base64UrlDecode($payload64), true);
        if (!is_array($payload)) {
            return null;
        }
        $signature = self::base64UrlDecode($signature64);
        $expected = hash_hmac('sha256', "$header64.$payload64", $secret, true);
        if (!hash_equals($expected, $signature)) {
            return null;
        }
        if (isset($payload['exp']) && time() >= $payload['exp']) {
            return null;
        }
        return $payload;
    }
}
?>