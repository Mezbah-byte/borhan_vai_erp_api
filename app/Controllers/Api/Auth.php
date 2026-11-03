<?php

namespace App\Controllers\Api;

use App\Libraries\ApiException;
use App\Libraries\Audit;
use App\Libraries\Ctx;
use App\Libraries\Jwt;

class Auth extends BaseApi
{
    public function login()
    {
        $in = $this->in();
        $this->validate_($in, ['login' => 'required', 'password' => 'required']);
        $cache = cache();
        $key = 'login_' . md5($this->request->getIPAddress() . strtolower($in['login']));
        if (($cache->get($key) ?? 0) >= 5) {
            throw new ApiException('Too many attempts. Try again in 10 minutes.', 429);
        }
        $u = $this->db->table('users')->groupStart()->where('phone', $in['login'])->orWhere('email', $in['login'])->groupEnd()
            ->where(['deleted_at' => null, 'status' => 'active'])->get()->getRowArray();
        if (! $u || ! password_verify($in['password'], $u['password_hash'])) {
            $cache->save($key, ($cache->get($key) ?? 0) + 1, 600);
            throw new ApiException('Invalid credentials', 401);
        }
        $cache->delete($key);
        $this->db->table('users')->where('id', $u['id'])->update(['last_login_at' => now()]);
        Ctx::$user = $u;
        Audit::log('login', 'users', $u['un_id']);

        return $this->ok(Jwt::issue($u['un_id']));
    }

    public function refresh()
    {
        $t = (string) ($this->in()['refresh_token'] ?? '');
        $r = $this->db->table('refresh_tokens')->where(['token_hash' => hash('sha256', $t), 'revoked_at' => null])->where('expires_at >', now())->get()->getRowArray();
        if (! $r) {
            throw new ApiException('Invalid refresh token', 401);
        }
        $this->db->table('refresh_tokens')->where('id', $r['id'])->update(['revoked_at' => now()]);

        return $this->ok(Jwt::issue($r['user_un_id']));
    }

    public function logout()
    {
        $t = (string) ($this->in()['refresh_token'] ?? '');
        $this->db->table('refresh_tokens')->where('token_hash', hash('sha256', $t))->update(['revoked_at' => now()]);
        if ($dt = $this->in()['device_token'] ?? null) {
            $this->db->table('device_tokens')->where('token', $dt)->delete();
        }

        return $this->ok(null, 'Logged out');
    }

    public function password()
    {
        $in = $this->in();
        $this->validate_($in, ['current' => 'required', 'new' => 'required|min_length[8]']);
        $u = $this->db->table('users')->where('un_id', Ctx::$user['un_id'])->get()->getRowArray();
        if (! password_verify($in['current'], $u['password_hash'])) {
            throw new ApiException('Current password is wrong', 422);
        }
        $this->db->table('users')->where('id', $u['id'])->update(['password_hash' => password_hash($in['new'], PASSWORD_DEFAULT), 'password_changed_at' => now(), 'updated_at' => now()]);
        $this->db->table('refresh_tokens')->where(['user_un_id' => $u['un_id'], 'revoked_at' => null])->update(['revoked_at' => now()]);
        Audit::log('password', 'users', $u['un_id']);

        // Every other device is now signed out; this one continues with fresh tokens.
        return $this->ok(Jwt::issue($u['un_id']), 'Password changed');
    }

    /** body: name?, phone?, email?, lang? — the signed-in user's own profile only. */
    public function updateMe()
    {
        $in = $this->in();
        $d = array_intersect_key($in, array_flip(['name', 'phone', 'email', 'lang']));
        $d = array_map(static fn ($v) => is_string($v) ? trim($v) : $v, $d);
        if (isset($d['email']) && $d['email'] === '') {
            $d['email'] = null;
        }
        $this->validate_($d, ['name' => 'permit_empty|max_length[120]', 'phone' => 'permit_empty|max_length[30]', 'email' => 'permit_empty|valid_email', 'lang' => 'permit_empty|in_list[en,bn]']);
        foreach (['name', 'phone'] as $f) {
            if (array_key_exists($f, $d) && ! $d[$f]) {
                throw new ApiException('Validation failed', 422, [$f => ucfirst($f) . ' is required']);
            }
        }
        foreach (['phone', 'email'] as $f) {
            if (! empty($d[$f]) && $this->db->table('users')->where([$f => $d[$f], 'un_id !=' => Ctx::$user['un_id'], 'deleted_at' => null])->countAllResults()) {
                throw new ApiException('Validation failed', 422, [$f => ucfirst($f) . ' already used by another user']);
            }
        }
        if (! $d) {
            throw new ApiException('Nothing to update', 422);
        }
        $old = array_intersect_key(Ctx::$user, $d);
        $this->db->table('users')->where('un_id', Ctx::$user['un_id'])->update($d + ['updated_at' => now()]);
        Audit::log('update', 'users', Ctx::$user['un_id'], $old, $d);

        return $this->ok(null, 'Profile saved');
    }

    public function deviceToken()
    {
        $t = (string) ($this->in()['token'] ?? '');
        if ($t) {
            $this->db->query('REPLACE INTO device_tokens (user_un_id, token, platform, created_at) VALUES (?,?,?,?)', [Ctx::$user['un_id'], $t, $this->in()['platform'] ?? null, now()]);
        }

        return $this->ok();
    }

    /** Bootstrap payload: user, businesses + permissions, all config lists the app caches. */
    public function me()
    {
        $ids = Ctx::bizIds() ?: ['-'];
        $all = fn ($t, $w = []) => $this->db->table($t)->where('deleted_at', null)->where($w)->get()->getResultArray();
        $biz = $this->db->table('businesses b')->select('b.*, t.slug type_slug, t.name type_name, t.modules')
            ->join('business_types t', 't.un_id = b.business_type_un_id', 'left')->whereIn('b.un_id', $ids)->where('b.deleted_at', null)->get()->getResultArray();
        foreach ($biz as &$b) {
            $b['permissions'] = Ctx::$access[$b['un_id']];
            $b['modules'] = jdec($b['modules']);
            unset($b['id']);
        }
        $j = fn ($rows, $keys) => array_map(function ($r) use ($keys) {
            foreach ($keys as $k) {
                $r[$k] = jdec($r[$k] ?? null);
            }
            unset($r['id']);

            return $r;
        }, $rows);
        $optBiz = fn ($t) => $this->db->table($t)->where('deleted_at', null)->groupStart()->where('business_un_id', null)->orWhereIn('business_un_id', $ids)->groupEnd()->get()->getResultArray();

        return $this->ok([
            'user'          => Ctx::$user,
            'businesses'    => $biz,
            'business_types' => $j($all('business_types'), ['modules']),
            'currencies'    => $j($all('currencies'), []),
            'units'         => $j($all('units'), []),
            'categories'    => $j($optBiz('categories'), []),
            'custom_fields' => $j($optBiz('custom_fields'), ['options']),
            'project_types' => $j($all('project_types'), ['stages']),
            'pipelines'     => $j($this->db->table('pipelines')->where('deleted_at', null)->whereIn('business_un_id', $ids)->get()->getResultArray(), []),
            'stages'        => $j($this->db->table('pipeline_stages')->where('deleted_at', null)->orderBy('sort')->get()->getResultArray(), ['required_docs']),
            'accounts'      => $j($this->db->table('accounts')->where(['deleted_at' => null, 'status' => 'active'])->whereIn('business_un_id', $ids)->whereIn('subtype', ['cash', 'bank', 'mobile'])->get()->getResultArray(), []),
            'server_time'   => now(),
        ]);
    }
}
