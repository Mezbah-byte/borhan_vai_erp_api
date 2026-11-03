<?php

namespace App\Filters;

use App\Libraries\Ctx;
use App\Libraries\Jwt;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class ApiAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $h = $request->getHeaderLine('Authorization');
        $claims = str_starts_with($h, 'Bearer ') ? Jwt::decode(substr($h, 7)) : null;
        $un = $claims->sub ?? null;
        $db = db_connect();
        $user = $un ? $db->table('users')->where(['un_id' => $un, 'status' => 'active', 'deleted_at' => null])->get()->getRowArray() : null;
        // Tokens issued before the last password change belong to signed-out devices.
        if ($user && $user['password_changed_at'] && ($claims->iat ?? 0) < strtotime($user['password_changed_at'])) {
            $user = null;
        }
        if (! $user) {
            return service('response')->setStatusCode(401)->setJSON(['success' => false, 'message' => 'Unauthenticated', 'data' => null, 'meta' => null, 'errors' => null]);
        }
        unset($user['password_hash'], $user['password_changed_at']);
        Ctx::$user = $user;
        if ($user['is_super']) {
            foreach ($db->table('businesses')->select('un_id')->where('deleted_at', null)->get()->getResultArray() as $b) {
                Ctx::$access[$b['un_id']] = ['*'];
            }
        } else {
            $rows = $db->table('user_business_access u')->select('u.business_un_id, r.permissions')
                ->join('roles r', 'r.un_id = u.role_un_id')->where(['u.user_un_id' => $un, 'u.deleted_at' => null])->get()->getResultArray();
            foreach ($rows as $r) {
                Ctx::$access[$r['business_un_id']] = json_decode($r['permissions'] ?? '[]', true) ?: [];
            }
        }
        $types = $db->table('businesses b')->select('b.un_id, t.modules')->join('business_types t', 't.un_id = b.business_type_un_id', 'left')
            ->whereIn('b.un_id', array_keys(Ctx::$access) ?: ['-'])->get()->getResultArray();
        foreach ($types as $t) {
            $m = $t['modules'] ? json_decode($t['modules'], true) : null;
            Ctx::$modules[$t['un_id']] = is_array($m) ? $m : null;
        }
        $biz = $request->getHeaderLine('X-Business-Id');
        if ($biz && $biz !== 'all') {
            if (! isset(Ctx::$access[$biz])) {
                return service('response')->setStatusCode(403)->setJSON(['success' => false, 'message' => 'No access to business', 'data' => null, 'meta' => null, 'errors' => null]);
            }
            Ctx::$biz = $biz;
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
