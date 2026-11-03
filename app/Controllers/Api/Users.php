<?php

namespace App\Controllers\Api;

use App\Libraries\ApiException;
use App\Libraries\Audit;
use App\Libraries\Ctx;

class Users extends BaseApi
{
    public function index()
    {
        $this->need('settings.view');
        $rows = $this->db->table('users')->select('un_id, name, phone, email, is_super, status, last_login_at')->where('deleted_at', null)->get()->getResultArray();
        $acc = $this->db->table('user_business_access')->where('deleted_at', null)->get()->getResultArray();
        $mine = Ctx::bizIds();
        $out = [];
        foreach ($rows as $r) {
            $own = array_filter($acc, static fn ($a) => $a['user_un_id'] === $r['un_id']);
            if (! Ctx::$user['is_super'] && ! $this->manages($r, $own, $mine)) {
                continue;
            }
            $r['access'] = array_values(array_map(
                static fn ($a) => ['business_un_id' => $a['business_un_id'], 'role_un_id' => $a['role_un_id']],
                $own
            ));
            $out[] = $r;
        }

        return $this->ok($out);
    }

    /** A non-super admin manages a user only when every business that user is in is one they administer. */
    private function manages(array $user, array $access, array $mine): bool
    {
        if ($user['is_super']) {
            return false;
        }
        foreach ($access as $a) {
            if (! in_array($a['business_un_id'], $mine, true)) {
                return false;
            }
        }

        return true;
    }

    private function guard(?string $un): void
    {
        if (Ctx::$user['is_super'] || ! $un) {
            return;
        }
        $u = $this->db->table('users')->where(['un_id' => $un, 'deleted_at' => null])->get()->getRowArray() ?? throw new ApiException('Not found', 404);
        $acc = $this->db->table('user_business_access')->where(['user_un_id' => $un, 'deleted_at' => null])->get()->getResultArray();
        if (! $this->manages($u, $acc, Ctx::bizIds())) {
            throw new ApiException('User belongs to a business you do not manage', 403);
        }
    }

    /** body: name, phone, email, password?, status, access: [{business_un_id, role_un_id}] */
    public function save(?string $un = null)
    {
        $this->need('settings.edit');
        $this->guard($un);
        $in = $this->in();
        foreach ($in['access'] ?? [] as $a) {
            if (! in_array($a['business_un_id'] ?? null, Ctx::bizIds(), true)) {
                throw new ApiException('Cannot grant access to a business you do not manage', 403);
            }
        }
        $this->validate_($in, ['name' => 'required', 'phone' => 'required']);
        $d = array_intersect_key($in, array_flip(['name', 'phone', 'email', 'status', 'lang']));
        // email is unique: blank must be NULL, not '', or the second user without email collides.
        if (array_key_exists('email', $d)) {
            $d['email'] = trim((string) $d['email']) ?: null;
        }
        $d['phone'] = trim((string) $d['phone']);
        foreach (['phone', 'email'] as $k) {
            if (! empty($d[$k]) && $this->db->table('users')->where($k, $d[$k])->where('un_id !=', $un ?? '-')->countAllResults()) {
                throw new ApiException('Another user already has this ' . $k, 422);
            }
        }
        if ($un === Ctx::$user['un_id'] && ($d['status'] ?? 'active') !== 'active') {
            throw new ApiException('You cannot deactivate your own account', 422);
        }
        if (! empty($in['password'])) {
            if (strlen($in['password']) < 8) {
                throw new ApiException('Password min 8 characters', 422);
            }
            $d['password_hash'] = password_hash($in['password'], PASSWORD_DEFAULT);
        } elseif (! $un) {
            throw new ApiException('Password required', 422);
        }
        $this->db->transStart();
        if ($un) {
            if (isset($d['password_hash'])) {
                // An admin reset signs the user out of every device.
                $d['password_changed_at'] = now();
                $this->db->table('refresh_tokens')->where(['user_un_id' => $un, 'revoked_at' => null])->update(['revoked_at' => now()]);
            }
            $this->db->table('users')->where('un_id', $un)->update($d + ['updated_at' => now()]);
        } else {
            $un = uid();
            $this->db->table('users')->insert($d + ['un_id' => $un, 'created_at' => now()]);
        }
        if (isset($in['access'])) {
            $this->db->table('user_business_access')->where('user_un_id', $un)->delete();
            foreach ($in['access'] as $a) {
                $this->db->table('user_business_access')->insert(['un_id' => uid(), 'user_un_id' => $un, 'business_un_id' => $a['business_un_id'], 'role_un_id' => $a['role_un_id'], 'created_at' => now()]);
            }
        }
        $this->db->transComplete();
        unset($d['password_hash']);
        Audit::log('save', 'users', $un, null, $d);

        return $this->ok(['un_id' => $un], 'Saved');
    }

    public function delete(string $un)
    {
        $this->need('settings.delete');
        $this->guard($un);
        $this->db->table('users')->where('un_id', $un)->update(['deleted_at' => now(), 'status' => 'inactive']);
        $this->db->table('refresh_tokens')->where('user_un_id', $un)->update(['revoked_at' => now()]);
        Audit::log('delete', 'users', $un);

        return $this->ok(null, 'Deleted');
    }
}
