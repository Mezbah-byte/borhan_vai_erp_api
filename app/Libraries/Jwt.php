<?php

namespace App\Libraries;

use Firebase\JWT\JWT as FJwt;
use Firebase\JWT\Key;

class Jwt
{
    public static function issue(string $userUn): array
    {
        $ttl = (int) env('jwt.ttl', 900);
        $access = FJwt::encode(['sub' => $userUn, 'iat' => time(), 'exp' => time() + $ttl], env('jwt.secret'), 'HS256');
        $refresh = bin2hex(random_bytes(32));
        db_connect()->table('refresh_tokens')->insert([
            'user_un_id' => $userUn,
            'token_hash' => hash('sha256', $refresh),
            'device'     => substr((string) service('request')->getUserAgent(), 0, 120),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+' . env('jwt.refreshDays', 30) . ' days')),
            'created_at' => now(),
        ]);

        return ['access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => $ttl];
    }

    /** Verified claims (sub, iat, exp) or null. */
    public static function decode(string $token): ?object
    {
        try {
            return FJwt::decode($token, new Key(env('jwt.secret'), 'HS256'));
        } catch (\Throwable) {
            return null;
        }
    }
}
