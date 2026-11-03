<?php

namespace App\Libraries;

class Audit
{
    public static function log(string $action, string $entity, ?string $un, $before = null, $after = null, ?string $biz = null): void
    {
        db_connect()->table('activity_logs')->insert([
            'user_un_id'     => Ctx::$user['un_id'] ?? null,
            'business_un_id' => $biz ?? Ctx::$biz,
            'action'         => $action,
            'entity_type'    => $entity,
            'entity_un_id'   => $un,
            'before'         => $before ? json_encode($before) : null,
            'after'          => $after ? json_encode($after) : null,
            'ip'             => service('request')->getIPAddress(),
            'created_at'     => now(),
        ]);
    }
}
