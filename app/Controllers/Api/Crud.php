<?php

namespace App\Controllers\Api;

use App\Libraries\ApiException;
use App\Libraries\Audit;
use App\Libraries\Ctx;
use App\Libraries\Ledger;

/**
 * Config-driven CRUD for simple resources. Route: /api/v1/{resource}[/{un_id}]
 * biz: true = rows owned by a business; 'opt' = business_un_id nullable (global or per-business).
 */
class Crud extends BaseApi
{
    private const R = [
        'businesses'     => ['t' => 'businesses', 'perm' => 'settings', 'biz' => false, 'search' => ['name'], 'json' => ['meta']],
        'business-types' => ['t' => 'business_types', 'perm' => 'settings', 'biz' => false, 'json' => ['modules']],
        'roles'          => ['t' => 'roles', 'perm' => 'settings', 'biz' => false, 'json' => ['permissions']],
        'parties'        => ['t' => 'parties', 'perm' => 'party', 'biz' => false, 'search' => ['name', 'phone', 'passport_no', 'nid', 'company'], 'json' => ['roles', 'meta']],
        'accounts'       => ['t' => 'accounts', 'perm' => 'finance', 'biz' => true, 'search' => ['name', 'account_no'], 'filters' => ['type', 'subtype', 'code']],
        'categories'     => ['t' => 'categories', 'perm' => 'settings', 'biz' => 'opt', 'filters' => ['kind']],
        'items'          => ['t' => 'items', 'perm' => 'project', 'biz' => 'opt', 'search' => ['name'], 'filters' => ['kind']],
        'units'          => ['t' => 'units', 'perm' => 'settings', 'biz' => false],
        'currencies'     => ['t' => 'currencies', 'perm' => 'settings', 'biz' => false],
        'custom-fields'  => ['t' => 'custom_fields', 'perm' => 'settings', 'biz' => 'opt', 'filters' => ['entity', 'scope'], 'json' => ['options'], 'sort' => 'sort'],
        'pipelines'      => ['t' => 'pipelines', 'perm' => 'visa', 'biz' => true],
        'pipeline-stages' => ['t' => 'pipeline_stages', 'perm' => 'visa', 'biz' => false, 'parent' => ['pipeline_un_id', 'pipelines'], 'filters' => ['pipeline_un_id'], 'json' => ['required_docs'], 'sort' => 'sort'],
        'project-types'  => ['t' => 'project_types', 'perm' => 'project', 'biz' => false, 'json' => ['stages']],
        'projects'       => ['t' => 'projects', 'perm' => 'project', 'biz' => true, 'search' => ['name', 'no', 'location'], 'filters' => ['status', 'project_type_un_id'], 'json' => ['meta'], 'seq' => 'PRJ'],
        'project-logs'   => ['t' => 'project_logs', 'perm' => 'project', 'biz' => false, 'parent' => ['project_un_id', 'projects'], 'filters' => ['project_un_id'], 'json' => ['data'], 'sort' => '-date'],
        'employees'      => ['t' => 'employees', 'perm' => 'hr', 'biz' => true, 'search' => ['name', 'phone', 'code'], 'filters' => ['status', 'employment_type'], 'json' => ['allowances', 'meta']],
    ];

    private array $c;

    private function cfg(string $res): void
    {
        if (! isset(self::R[$res])) {
            throw new ApiException('Unknown resource', 404);
        }
        $this->c = self::R[$res];
    }

    /** Shared settings rows apply to every business, so only a super admin changes them (an owner may edit their own business). */
    private function guardShared(string $res, ?string $un = null): void
    {
        if ($this->c['biz'] !== false || $this->c['perm'] !== 'settings' || Ctx::$user['is_super']) {
            return;
        }
        if ($res === 'businesses' && $un && Ctx::can('settings.edit', $un)) {
            return;
        }
        throw new ApiException('Only super admin can change shared settings', 403);
    }

    private function fields(): array
    {
        return array_diff($this->db->getFieldNames($this->c['t']), ['id', 'un_id', 'created_at', 'updated_at', 'deleted_at']);
    }

    private function out(array $r): array
    {
        foreach ($this->c['json'] ?? [] as $k) {
            if (isset($r[$k])) {
                $r[$k] = jdec($r[$k]);
            }
        }
        unset($r['id']);

        return $r;
    }

    private function base()
    {
        $b = $this->db->table($this->c['t'])->where('deleted_at', null);
        if ($this->c['biz'] === true) {
            $this->scope($b);
        } elseif ($this->c['biz'] === 'opt') {
            $ids = Ctx::$biz ? [Ctx::$biz] : (Ctx::bizIds() ?: ['-']);
            $b->groupStart()->where('business_un_id', null)->orWhereIn('business_un_id', $ids)->groupEnd();
        }

        if (! empty($this->c['parent'])) {
            [$col, $pt] = $this->c['parent'];
            $ids = Ctx::$biz ? [Ctx::$biz] : (Ctx::bizIds() ?: ['-']);
            $b->whereIn($col, fn ($q) => $q->select('un_id')->from($pt)->whereIn('business_un_id', $ids));
        }

        return $b;
    }

    public function index(string $res)
    {
        $this->cfg($res);
        $this->need($this->c['perm'] . '.view');
        $b = $this->base();
        $g = $this->request->getGet();
        if (($q = trim($g['q'] ?? '')) && ! empty($this->c['search'])) {
            $b->groupStart();
            foreach ($this->c['search'] as $i => $f) {
                $i ? $b->orLike($f, $q) : $b->like($f, $q);
            }
            $b->groupEnd();
        }
        foreach ($this->c['filters'] ?? [] as $f) {
            if (isset($g[$f]) && $g[$f] !== '') {
                $b->where($f, $g[$f]);
            }
        }
        if ($res === 'parties' && ! empty($g['role'])) {
            $b->where('JSON_CONTAINS(roles, ' . $this->db->escape(json_encode($g['role'])) . ')', null, false);
        }
        if (! empty($g['updated_since'])) {
            $b->where('updated_at >=', $g['updated_since']);
        }
        $s = $g['sort'] ?? ($this->c['sort'] ?? '-id');
        if (! in_array(ltrim($s, '-'), [...$this->fields(), 'id'], true)) {
            $s = '-id';
        }
        $b->orderBy(ltrim($s, '-'), $s[0] === '-' ? 'DESC' : 'ASC');
        if (! empty($g['all'])) {
            return $this->ok(array_map(fn ($r) => $this->out($r), $b->get()->getResultArray()));
        }

        return $this->page($b, fn ($r) => $this->out($r));
    }

    public function show(string $res, string $un)
    {
        $this->cfg($res);
        $this->need($this->c['perm'] . '.view');
        $r = $this->base()->where('un_id', $un)->get()->getRowArray() ?? throw new ApiException('Not found', 404);
        $r = $this->out($r);
        if ($res === 'parties') {
            $r['balance'] = Ledger::partyBalance($un, Ctx::bizIds());
        }
        if ($res === 'accounts') {
            $r['balance'] = Ledger::balance($un);
        }

        return $this->ok($r);
    }

    private function clean(array $in): array
    {
        $d = array_intersect_key($in, array_flip($this->fields()));
        foreach ($this->c['json'] ?? [] as $k) {
            if (isset($d[$k]) && ! is_string($d[$k])) {
                $d[$k] = json_encode($d[$k]);
            }
        }
        unset($d['business_un_id']);

        return $d;
    }

    public function create(string $res)
    {
        $this->cfg($res);
        $this->need($this->c['perm'] . '.create');
        $this->guardShared($res);
        $d = $this->clean($this->in());
        if ($this->c['biz'] === true || ($this->c['biz'] === 'opt' && ! empty($this->in()['business_un_id']))) {
            $d['business_un_id'] = $this->biz();
        }
        if (! empty($this->c['parent'])) {
            [$col, $pt] = $this->c['parent'];
            $this->row($pt, (string) ($d[$col] ?? ''));
        }
        if (! empty($this->c['seq'])) {
            $d['no'] = \App\Libraries\Seq::next($d['business_un_id'], $this->c['seq'], $this->c['seq']);
        }
        if (in_array('name', $this->fields(), true) && empty($d['name']) && ! in_array($res, ['project-logs'], true)) {
            throw new ApiException('Validation failed', 422, ['name' => 'Name is required']);
        }
        $d['un_id'] = uid();
        $d['created_at'] = $d['updated_at'] = now();
        $this->db->table($this->c['t'])->insert($d);
        if ($res === 'businesses') {
            Ledger::ensureSystemAccounts($d['un_id']);
        }
        if ($res === 'accounts' && ($ob = (float) ($this->in()['opening_balance'] ?? 0)) > 0) {
            Ledger::post($d['business_un_id'], ['type' => 'journal', 'note' => 'Opening balance · ' . $d['name']], [[$d['un_id'], $ob, 0], ['@EQUITY', 0, $ob]]);
        }
        Audit::log('create', $this->c['t'], $d['un_id'], null, $d, $d['business_un_id'] ?? null);

        return $this->show($res, $d['un_id'])->setStatusCode(201);
    }

    public function update(string $res, string $un)
    {
        $this->cfg($res);
        $this->need($this->c['perm'] . '.edit');
        $this->guardShared($res, $un);
        $old =$this->base()->where('un_id', $un)->get()->getRowArray() ?? throw new ApiException('Not found', 404);
        $d = $this->clean($this->in());
        unset($d['no'], $d['code']);
        if (! $d) {
            throw new ApiException('Nothing to update', 422);
        }
        $d['updated_at'] = now();
        $this->db->table($this->c['t'])->where('un_id', $un)->update($d);
        Audit::log('update', $this->c['t'], $un, array_intersect_key($old, $d), $d, $old['business_un_id'] ?? null);

        return $this->show($res, $un);
    }

    public function delete(string $res, string $un)
    {
        $this->cfg($res);
        $this->need($this->c['perm'] . '.delete');
        $this->guardShared($res);
        $old =$this->base()->where('un_id', $un)->get()->getRowArray() ?? throw new ApiException('Not found', 404);
        if ($res === 'accounts' && $old['is_system']) {
            throw new ApiException('System account cannot be deleted', 422);
        }
        // Soft-deleting something the books point at would silently drop money from balances and reports.
        $inUse = match ($res) {
            'accounts'   => $this->db->table('transaction_lines')->where(['account_un_id' => $un, 'status' => 'posted'])->countAllResults(),
            'parties'    => $this->db->table('transaction_lines')->where(['party_un_id' => $un, 'status' => 'posted'])->countAllResults()
                + $this->db->table('visa_files')->where(['candidate_un_id' => $un, 'deleted_at' => null])->countAllResults(),
            'businesses' => $this->db->table('transactions')->where(['business_un_id' => $un, 'status' => 'posted'])->countAllResults(),
            'projects'   => $this->db->table('transaction_lines')->where(['project_un_id' => $un, 'status' => 'posted'])->countAllResults(),
            default      => 0,
        };
        if ($inUse) {
            throw new ApiException('In use by ' . $inUse . ' entr' . ($inUse === 1 ? 'y' : 'ies') . ' — it cannot be deleted.', 422);
        }
        $this->db->table($this->c['t'])->where('un_id', $un)->update(['deleted_at' => now()]);
        Audit::log('delete', $this->c['t'], $un, $old, null, $old['business_un_id'] ?? null);

        return $this->ok(null, 'Deleted');
    }
}
