<?php

namespace App\Controllers\Api;

use App\Libraries\ApiException;
use App\Libraries\Ctx;
use CodeIgniter\Controller;
use CodeIgniter\Database\BaseBuilder;

abstract class BaseApi extends Controller
{
    protected $db;

    public function _remap($method, ...$params)
    {
        $this->db = db_connect();
        if (! method_exists($this, $method)) {
            return $this->ok(null, 'Not found', 404);
        }
        try {
            return $this->{$method}(...$params);
        } catch (ApiException $e) {
            if ($this->db->transDepth > 0) {
                $this->db->transRollback();
            }

            return $this->ok(null, $e->getMessage(), $e->getCode() ?: 400, $e->errors ?: null);
        }
    }

    protected function ok($data = null, string $msg = 'OK', int $code = 200, $errors = null, $meta = null)
    {
        return $this->response->setStatusCode($code)->setJSON([
            'success' => $code < 400, 'message' => $msg, 'data' => $data, 'meta' => $meta, 'errors' => $errors,
        ]);
    }

    protected function in(): array
    {
        $j = $this->request->getJSON(true);

        return is_array($j) ? $j : $this->request->getPost();
    }

    protected function need(string $perm): void
    {
        if (! Ctx::can($perm)) {
            throw new ApiException('Permission denied: ' . $perm, 403);
        }
        Ctx::$needed[] = $perm;
    }

    protected function biz(): string
    {
        if (! Ctx::$biz) {
            throw new ApiException('Select a business (X-Business-Id)', 422);
        }

        return Ctx::$biz;
    }

    /** Like biz(), but in "all businesses" mode takes the business of the given account. */
    protected function bizOfAccount(?string $accountUn): string
    {
        if (! Ctx::$biz && $accountUn && ($a = $this->db->table('accounts')->select('business_un_id')->where(['un_id' => $accountUn, 'deleted_at' => null])->get()->getRowArray())) {
            $this->useBiz($a['business_un_id']);
        }

        return $this->biz();
    }

    /** Restrict builder to current business, or all accessible businesses. */
    protected function scope(BaseBuilder $b, string $col = 'business_un_id'): BaseBuilder
    {
        return Ctx::$biz ? $b->where($col, Ctx::$biz) : $b->whereIn($col, Ctx::bizIds() ?: ['-']);
    }

    protected function validate_(array $data, array $rules): void
    {
        $v = service('validation')->setRules($rules);
        if (! $v->run($data)) {
            throw new ApiException('Validation failed', 422, $v->getErrors());
        }
    }

    protected function page(BaseBuilder $b, ?callable $map = null)
    {
        $per = min(100, max(1, (int) ($this->request->getGet('per_page') ?? 20)));
        $pg = max(1, (int) ($this->request->getGet('page') ?? 1));
        $total = (clone $b)->countAllResults(false);
        $rows = $b->limit($per, ($pg - 1) * $per)->get()->getResultArray();
        if ($map) {
            $rows = array_map($map, $rows);
        }

        return $this->ok($rows, 'OK', 200, null, ['page' => $pg, 'per_page' => $per, 'total' => $total, 'last_page' => (int) ceil($total / $per)]);
    }

    protected function row(string $table, string $un, bool $scoped = true): array
    {
        $b = $this->db->table($table)->where(['un_id' => $un, 'deleted_at' => null]);
        if ($scoped) {
            $b->whereIn('business_un_id', Ctx::bizIds() ?: ['-']);
        }
        $r = $b->get()->getRowArray();
        if (! $r) {
            throw new ApiException('Not found', 404);
        }

        return $r;
    }

    /** Use the record's own business for writes on existing rows. */
    protected function useBiz(string $biz): void
    {
        if (! isset(Ctx::$access[$biz])) {
            throw new ApiException('No access', 403);
        }
        // need() ran against all businesses; the record's own business must grant the same.
        if (! Ctx::canAll($biz)) {
            throw new ApiException('Permission denied for this business', 403);
        }
        Ctx::$biz = $biz;
    }
}
