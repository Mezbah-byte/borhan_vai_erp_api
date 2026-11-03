<?php

namespace App\Libraries;

/** In-app notifications (+ optional FCM push when FCM_KEY is configured). */
class Notify
{
    public static function send(?string $user, ?string $biz, string $type, string $title, string $body, ?string $entity = null, ?string $entityUn = null, ?string $dedupe = null): void
    {
        $db = db_connect();
        $sql = 'INSERT IGNORE INTO notifications (un_id, user_un_id, business_un_id, type, title, body, entity_type, entity_un_id, dedupe_key, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)';
        $db->query($sql, [uid(), $user, $biz, $type, $title, $body, $entity, $entityUn, $dedupe ? substr($dedupe . ':' . $user, 0, 120) : null, now()]);
        if ($user && $db->affectedRows() && ($key = env('fcm.serverKey'))) {
            $tokens = array_column($db->table('device_tokens')->where('user_un_id', $user)->get()->getResultArray(), 'token');
            foreach ($tokens as $t) {
                service('curlrequest')->post('https://fcm.googleapis.com/fcm/send', [
                    'headers' => ['Authorization' => 'key=' . $key], 'json' => ['to' => $t, 'notification' => ['title' => $title, 'body' => $body], 'data' => ['entity' => $entity, 'id' => $entityUn]], 'http_errors' => false,
                ]);
            }
        }
    }

    /** Send to every user who holds $perm on $biz (super users included). */
    public static function toPerm(string $biz, string $perm, string $type, string $title, string $body, ?string $entity = null, ?string $entityUn = null, ?string $dedupe = null): void
    {
        $db = db_connect();
        $users = array_column($db->table('users')->select('un_id')->where(['is_super' => 1, 'deleted_at' => null])->get()->getResultArray(), 'un_id');
        [$mod] = explode('.', $perm);
        foreach ($db->table('user_business_access u')->select('u.user_un_id, r.permissions')->join('roles r', 'r.un_id = u.role_un_id')->where(['u.business_un_id' => $biz, 'u.deleted_at' => null])->get()->getResultArray() as $r) {
            $p = json_decode($r['permissions'] ?? '[]', true) ?: [];
            if (array_intersect(['*', $perm, $mod . '.*'], $p)) {
                $users[] = $r['user_un_id'];
            }
        }
        foreach (array_unique($users) as $u) {
            self::send($u, $biz, $type, $title, $body, $entity, $entityUn, $dedupe);
        }
    }
}
