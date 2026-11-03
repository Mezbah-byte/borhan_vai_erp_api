<?php

namespace App\Controllers\Api;

use App\Libraries\ApiException;
use App\Libraries\Audit;
use App\Libraries\Ledger;

/** Generic project engine: inputs, labor, outputs, sales for any business type. */
class Projects extends BaseApi
{
    private function totals(string $un): array
    {
        $rows = $this->db->table('project_entries')->select('kind, SUM(amount) amount, SUM(quantity) qty')->where(['project_un_id' => $un, 'deleted_at' => null])->groupBy('kind')->get()->getResultArray();
        $t = ['input' => 0, 'labor' => 0, 'output' => 0, 'sale' => 0, 'output_qty' => 0, 'sale_qty' => 0];
        foreach ($rows as $r) {
            $t[$r['kind']] = round((float) $r['amount'], 2);
            $t[$r['kind'] . '_qty'] = round((float) $r['qty'], 3);
        }
        $t['expense'] = round((float) ($this->db->table('expenses')->selectSum('amount', 'a')->where(['project_un_id' => $un, 'status' => 'approved', 'deleted_at' => null])->get()->getRowArray()['a'] ?? 0), 2);
        $t['cost'] = round($t['input'] + $t['labor'] + $t['expense'], 2);
        $t['revenue'] = $t['sale'];
        $t['profit'] = round($t['revenue'] - $t['cost'], 2);
        $t['roi'] = $t['cost'] > 0 ? round($t['profit'] / $t['cost'] * 100, 1) : 0;
        $t['unit_cost'] = $t['output_qty'] > 0 ? round($t['cost'] / $t['output_qty'], 2) : null;

        return $t;
    }

    public function index()
    {
        $this->need('project.view');
        $b = $this->db->table('projects p')->select('p.*, t.name type_name, t.slug type_slug, t.icon, t.unit_label, bz.name business_name')
            ->join('project_types t', 't.un_id = p.project_type_un_id', 'left')->join('businesses bz', 'bz.un_id = p.business_un_id')->where('p.deleted_at', null);
        $this->scope($b, 'p.business_un_id');
        $g = $this->request->getGet();
        if (! empty($g['status'])) {
            $b->where('p.status', $g['status']);
        }
        if (! empty($g['type'])) {
            $b->where('t.slug', $g['type']);
        }
        if ($q = trim($g['q'] ?? '')) {
            $b->groupStart()->like('p.name', $q)->orLike('p.no', $q)->groupEnd();
        }

        return $this->page($b->orderBy('p.id', 'DESC'), function ($r) {
            $r['meta'] = jdec($r['meta']);
            $r['totals'] = $this->totals($r['un_id']);
            unset($r['id']);

            return $r;
        });
    }

    public function show(string $un)
    {
        $this->need('project.view');
        $p = $this->row('projects', $un);
        $p['meta'] = jdec($p['meta']);
        $p['type'] = $this->db->table('project_types')->where('un_id', $p['project_type_un_id'])->get()->getRowArray();
        $p['totals'] = $this->totals($un);
        $p['weekly'] = $this->db->query("SELECT YEARWEEK(date, 1) wk, MIN(date) start, SUM(CASE WHEN kind IN ('input','labor') THEN amount ELSE 0 END) cost, SUM(CASE WHEN kind = 'sale' THEN amount ELSE 0 END) sales
            FROM project_entries WHERE project_un_id = ? AND deleted_at IS NULL GROUP BY wk ORDER BY wk DESC LIMIT 8", [$un])->getResultArray();
        unset($p['id']);

        return $this->ok($p);
    }

    public function entries(string $un)
    {
        $this->need('project.view');
        $this->row('projects', $un);
        $b = $this->db->table('project_entries e')->select('e.*, i.name item_name, p.name party_name, em.name employee_name')
            ->join('items i', 'i.un_id = e.item_un_id', 'left')->join('parties p', 'p.un_id = e.party_un_id', 'left')->join('employees em', 'em.un_id = e.employee_un_id', 'left')
            ->where(['e.project_un_id' => $un, 'e.deleted_at' => null]);
        if ($k = $this->request->getGet('kind')) {
            $b->where('e.kind', $k);
        }

        return $this->page($b->orderBy('e.date', 'DESC')->orderBy('e.id', 'DESC'), static function ($r) {
            unset($r['id']);

            return $r;
        });
    }

    /** body: kind input|labor|output|sale, date, item_un_id|description, quantity, unit, rate, vat, party_un_id, account_un_id, employee_un_id */
    public function addEntry(string $un)
    {
        $this->need('project.edit');
        $p = $this->row('projects', $un);
        $this->useBiz($p['business_un_id']);
        if ($p['status'] !== 'running') {
            throw new ApiException('Project is ' . $p['status'] . ' — reopen it to add entries', 422);
        }
        $in = $this->in();
        $this->validate_($in, ['kind' => 'required|in_list[input,labor,output,sale]', 'date' => 'required|valid_date[Y-m-d]', 'quantity' => 'permit_empty|decimal', 'rate' => 'permit_empty|decimal']);
        if (empty($in['item_un_id']) && empty($in['description'])) {
            throw new ApiException('Item or description required', 422);
        }
        $e = array_intersect_key($in, array_flip(['kind', 'date', 'item_un_id', 'description', 'quantity', 'unit', 'rate', 'vat', 'party_un_id', 'account_un_id', 'employee_un_id', 'note']));
        $e['quantity'] = (float) ($e['quantity'] ?? 0);
        $e['rate'] = (float) ($e['rate'] ?? 0);
        $e['vat'] = (float) ($e['vat'] ?? 0);
        $e['amount'] = round($e['quantity'] * $e['rate'] + $e['vat'], 2);
        $e += ['un_id' => uid(), 'project_un_id' => $un, 'created_at' => now(), 'updated_at' => now()];
        $this->db->transStart();
        if ($e['kind'] !== 'output' && $e['amount'] > 0) {
            if (empty($e['account_un_id']) && empty($e['party_un_id'])) {
                throw new ApiException($e['kind'] === 'sale' ? 'Choose received-in account or buyer' : 'Choose paid-from account or supplier', 422);
            }
            $name = $e['description'] ?? ($this->db->table('items')->select('name')->where('un_id', $e['item_un_id'])->get()->getRowArray()['name'] ?? 'Entry');
            $h = ['type' => $e['kind'] === 'sale' ? 'sale' : 'purchase', 'date' => $e['date'], 'party_un_id' => $e['party_un_id'] ?? null, 'entity_type' => 'project', 'entity_un_id' => $un, 'note' => $name . ' · ' . $p['name']];
            $money = ! empty($e['account_un_id']) ? $e['account_un_id'] : ($e['kind'] === 'sale' ? '@AR' : '@AP');
            $lines = $e['kind'] === 'sale'
                ? [[$money, $e['amount'], 0, $e['party_un_id'] ?? null, null, $un], ['@PROJECT_SALES', 0, $e['amount'], null, null, $un]]
                : [['@PROJECT_COST', $e['amount'], 0, null, null, $un], [$money, 0, $e['amount'], $e['party_un_id'] ?? null, null, $un]];
            $e['transaction_un_id'] = Ledger::post($p['business_un_id'], $h, $lines);
        }
        $this->db->table('project_entries')->insert($e);
        $this->db->transComplete();
        Audit::log('create', 'project_entries', $e['un_id'], null, $e);

        return $this->ok($e, 'Saved', 201);
    }

    public function deleteEntry(string $un, string $entry)
    {
        $this->need('project.delete');
        $this->row('projects', $un);
        $e = $this->db->table('project_entries')->where(['un_id' => $entry, 'project_un_id' => $un])->get()->getRowArray() ?? throw new ApiException('Not found', 404);
        Ledger::void($e['transaction_un_id']);
        $this->db->table('project_entries')->where('un_id', $entry)->update(['deleted_at' => now()]);
        Audit::log('delete', 'project_entries', $entry, $e, null);

        return $this->ok(null, 'Deleted');
    }
}
