<?php

namespace App\Controllers\Api;

use App\Libraries\ApiException;
use App\Libraries\Audit;
use App\Libraries\Ctx;
use App\Libraries\Ledger;

class Hr extends BaseApi
{
    public function attendance()
    {
        $this->need('hr.view');
        $date = $this->request->getGet('date') ?? today();
        $b = $this->db->table('employees e')->select('e.un_id, e.name, e.designation, e.employment_type, e.daily_rate, e.photo, a.status, a.note')
            ->join('attendance a', 'a.employee_un_id = e.un_id AND a.date = ' . $this->db->escape($date), 'left')
            ->where(['e.deleted_at' => null, 'e.status' => 'active'])->orderBy('e.name');
        $this->scope($b, 'e.business_un_id');
        $rows = $b->get()->getResultArray();
        $c = array_count_values(array_filter(array_column($rows, 'status')));

        return $this->ok(['date' => $date, 'counts' => ['P' => $c['P'] ?? 0, 'A' => $c['A'] ?? 0, 'L' => $c['L'] ?? 0, 'H' => $c['H'] ?? 0], 'rows' => $rows]);
    }

    /** body: date, marks: [{employee_un_id, status P|A|L|H, note}] */
    public function markAttendance()
    {
        $this->need('hr.edit');
        $in = $this->in();
        $this->validate_($in, ['date' => 'required|valid_date[Y-m-d]']);
        $this->db->transStart();
        foreach ($in['marks'] ?? [] as $m) {
            if (! in_array($m['status'] ?? '', ['P', 'A', 'L', 'H'], true)) {
                continue;
            }
            $this->row('employees', $m['employee_un_id']);
            $this->db->query('INSERT INTO attendance (employee_un_id, date, status, note, created_at, updated_at) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note), updated_at = VALUES(updated_at)',
                [$m['employee_un_id'], $in['date'], $m['status'], $m['note'] ?? null, now(), now()]);
        }
        $this->db->transComplete();

        return $this->ok(null, 'Saved');
    }

    public function advances()
    {
        $this->need('hr.view');
        $b = $this->db->table('advances a')->select('a.*, e.name employee_name')->join('employees e', 'e.un_id = a.employee_un_id')->where('a.deleted_at', null);
        $this->scope($b, 'a.business_un_id');
        if ($e = $this->request->getGet('employee_un_id')) {
            $b->where('a.employee_un_id', $e);
        }

        return $this->page($b->orderBy('a.date', 'DESC'), static function ($r) {
            unset($r['id']);

            return $r;
        });
    }

    public function createAdvance()
    {
        $this->need('hr.edit');
        $in = $this->in();
        $biz = $this->bizOfAccount($in['account_un_id'] ?? null);
        $this->validate_($in, ['employee_un_id' => 'required', 'amount' => 'required|decimal|greater_than[0]', 'account_un_id' => 'required']);
        $emp = $this->row('employees', $in['employee_un_id']);
        if ($emp['business_un_id'] !== $biz) {
            throw new ApiException('Pay the advance from an account of the employee\'s own business', 422);
        }
        $d = ['un_id' => uid(), 'business_un_id' => $biz, 'employee_un_id' => $emp['un_id'], 'date' => $in['date'] ?? today(), 'amount' => $in['amount'], 'installment' => $in['installment'] ?? $in['amount'],
            'account_un_id' => $in['account_un_id'], 'note' => $in['note'] ?? null, 'created_at' => now(), 'updated_at' => now()];
        $this->db->transStart();
        $d['transaction_un_id'] = Ledger::post($biz, ['type' => 'payment', 'date' => $d['date'], 'entity_type' => 'advance', 'entity_un_id' => $d['un_id'], 'note' => 'Advance · ' . $emp['name']],
            [['@ADVANCE', $d['amount'], 0], [$d['account_un_id'], 0, $d['amount']]]);
        $this->db->table('advances')->insert($d);
        $this->db->transComplete();

        return $this->ok($d, 'Saved', 201);
    }

    public function runs()
    {
        $this->need('hr.view');
        $b = $this->db->table('payroll_runs r')->select('r.*, bz.name business_name, (SELECT COUNT(*) FROM payroll_items i WHERE i.run_un_id = r.un_id AND i.deleted_at IS NULL) employees', false)
            ->join('businesses bz', 'bz.un_id = r.business_un_id')->where('r.deleted_at', null);
        $this->scope($b, 'r.business_un_id');

        return $this->page($b->orderBy('r.period', 'DESC'), static function ($r) {
            unset($r['id']);

            return $r;
        });
    }

    public function run(string $un)
    {
        $this->need('hr.view');
        $r = $this->row('payroll_runs', $un);
        $r['items'] = $this->db->table('payroll_items i')->select('i.*, e.name, e.designation, e.employment_type, e.daily_rate, e.photo')
            ->join('employees e', 'e.un_id = i.employee_un_id')->where(['i.run_un_id' => $un, 'i.deleted_at' => null])->orderBy('e.name')->get()->getResultArray();
        $r['advance_total'] = round(array_sum(array_column($r['items'], 'advance_deduction')), 2);
        unset($r['id']);

        return $this->ok($r);
    }

    /** body: period YYYY-MM. Builds/rebuilds a draft from salaries, attendance and open advances. */
    public function generate()
    {
        $this->need('hr.edit');
        $biz = $this->biz();
        $period = $this->in()['period'] ?? date('Y-m');
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new ApiException('period must be YYYY-MM', 422);
        }
        $run = $this->db->table('payroll_runs')->where(['business_un_id' => $biz, 'period' => $period, 'deleted_at' => null])->get()->getRowArray();
        if ($run && $run['status'] !== 'draft') {
            throw new ApiException('Payroll already ' . $run['status'], 422);
        }
        $from = $period . '-01';
        $to = date('Y-m-t', strtotime($from));
        $days = (int) date('t', strtotime($from));
        $this->db->transStart();
        if (! $run) {
            $run = ['un_id' => uid(), 'business_un_id' => $biz, 'period' => $period, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()];
            $this->db->table('payroll_runs')->insert($run);
        }
        $this->db->table('payroll_items')->where('run_un_id', $run['un_id'])->delete();
        $total = 0;
        foreach ($this->db->table('employees')->where(['business_un_id' => $biz, 'status' => 'active', 'deleted_at' => null])->get()->getResultArray() as $e) {
            $att = $this->db->table('attendance')->select('status, COUNT(*) n')->where('employee_un_id', $e['un_id'])->where('date >=', $from)->where('date <=', $to)->groupBy('status')->get()->getResultArray();
            $a = array_column($att, 'n', 'status');
            $present = ($a['P'] ?? 0) + ($a['L'] ?? 0) + 0.5 * ($a['H'] ?? 0);
            $marked = array_sum($a);
            $allow = array_sum(array_map('floatval', jdec($e['allowances']) ?: []));
            if ($e['employment_type'] === 'daily') {
                $basic = round($present * $e['daily_rate'], 2);
                $allow = 0;
                $ded = 0;
            } else {
                $basic = (float) $e['salary'];
                // Deduct unpaid absence only when attendance is tracked for this employee.
                $ded = $marked ? round(($a['A'] ?? 0) * ($basic / $days), 2) : 0;
                $present = $marked ? $present : $days;
            }
            $adv = 0;
            foreach ($this->db->table('advances')->where(['employee_un_id' => $e['un_id'], 'status' => 'open', 'deleted_at' => null])->get()->getResultArray() as $ad) {
                $adv += min($ad['installment'] ?: $ad['amount'], $ad['amount'] - $ad['repaid']);
            }
            $gross = $basic + $allow - $ded;
            $adv = min($adv, max(0, $gross));
            $net = round($gross - $adv, 2);
            $total += $net;
            $this->db->table('payroll_items')->insert(['un_id' => uid(), 'run_un_id' => $run['un_id'], 'employee_un_id' => $e['un_id'], 'days_present' => $present, 'days_total' => $days,
                'basic' => $basic, 'allowances' => $allow, 'deductions' => $ded, 'advance_deduction' => $adv, 'net' => $net, 'created_at' => now()]);
        }
        $this->db->table('payroll_runs')->where('un_id', $run['un_id'])->update(['total' => $total, 'updated_at' => now()]);
        $this->db->transComplete();

        return $this->run($run['un_id']);
    }

    /** PUT body: overtime, bonus, deductions, advance_deduction, note */
    public function updateItem(string $item)
    {
        $this->need('hr.edit');
        $i = $this->db->table('payroll_items')->where('un_id', $item)->get()->getRowArray() ?? throw new ApiException('Not found', 404);
        $run = $this->row('payroll_runs', $i['run_un_id']);
        if ($run['status'] !== 'draft') {
            throw new ApiException('Payroll is locked', 422);
        }
        $d = array_intersect_key($this->in(), array_flip(['overtime', 'bonus', 'deductions', 'advance_deduction', 'note']));
        $m = array_merge($i, $d);
        $d['net'] = round($m['basic'] + $m['allowances'] + $m['overtime'] + $m['bonus'] - $m['deductions'] - $m['advance_deduction'], 2);
        $this->db->table('payroll_items')->where('un_id', $item)->update($d + ['updated_at' => now()]);
        $this->db->query('UPDATE payroll_runs SET total = (SELECT SUM(net) FROM payroll_items WHERE run_un_id = ? AND deleted_at IS NULL) WHERE un_id = ?', [$run['un_id'], $run['un_id']]);

        return $this->run($run['un_id']);
    }

    public function approve(string $un)
    {
        $this->need('hr.approve');
        $r = $this->row('payroll_runs', $un);
        if ($r['status'] !== 'draft') {
            throw new ApiException('Only draft can be approved', 422);
        }
        $this->db->table('payroll_runs')->where('un_id', $un)->update(['status' => 'approved', 'approved_by' => Ctx::$user['un_id'], 'updated_at' => now()]);
        Audit::log('approve', 'payroll_runs', $un, null, null, $r['business_un_id']);

        return $this->run($un);
    }

    /** body: account_un_id, date */
    public function pay(string $un)
    {
        $this->need('hr.approve');
        $r = $this->row('payroll_runs', $un);
        if ($r['status'] !== 'approved') {
            throw new ApiException('Approve payroll first', 422);
        }
        $in = $this->in();
        $this->validate_($in, ['account_un_id' => 'required']);
        $items = $this->db->table('payroll_items')->where(['run_un_id' => $un, 'deleted_at' => null])->get()->getResultArray();
        $net = round(array_sum(array_column($items, 'net')), 2);
        $adv = round(array_sum(array_column($items, 'advance_deduction')), 2);
        $this->db->transStart();
        $tx = Ledger::post($r['business_un_id'], ['type' => 'payroll', 'date' => $in['date'] ?? today(), 'entity_type' => 'payroll', 'entity_un_id' => $un, 'note' => 'Payroll ' . $r['period']],
            [['@SALARY', $net + $adv, 0], [$in['account_un_id'], 0, $net], ['@ADVANCE', 0, $adv]]);
        foreach ($items as $i) {
            $left = (float) $i['advance_deduction'];
            foreach ($this->db->table('advances')->where(['employee_un_id' => $i['employee_un_id'], 'status' => 'open', 'deleted_at' => null])->orderBy('date')->get()->getResultArray() as $ad) {
                if ($left <= 0) {
                    break;
                }
                $put = min($left, $ad['amount'] - $ad['repaid']);
                $left -= $put;
                $rep = $ad['repaid'] + $put;
                $this->db->table('advances')->where('id', $ad['id'])->update(['repaid' => $rep, 'status' => $rep >= $ad['amount'] ? 'closed' : 'open']);
            }
        }
        $this->db->table('payroll_runs')->where('un_id', $un)->update(['status' => 'paid', 'paid_at' => now(), 'account_un_id' => $in['account_un_id'], 'transaction_un_id' => $tx, 'updated_at' => now()]);
        $this->db->transComplete();
        Audit::log('pay', 'payroll_runs', $un, null, ['total' => $net], $r['business_un_id']);

        return $this->run($un);
    }
}
