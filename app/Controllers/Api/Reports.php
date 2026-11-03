<?php

namespace App\Controllers\Api;

use App\Libraries\Ctx;

class Reports extends BaseApi
{
    private function bizIds(): array
    {
        return Ctx::$biz ? [Ctx::$biz] : (Ctx::bizIds() ?: ['-']);
    }

    private function range(): array
    {
        $g = $this->request->getGet();

        return [$g['from'] ?? date('Y-m-01'), $g['to'] ?? today()];
    }

    /** Sum of (debit-credit) per account code within range for given businesses. */
    private function flow(array $codes, string $from, string $to, ?array $biz = null): float
    {
        $r = $this->db->table('transaction_lines tl')->select('SUM(tl.debit - tl.credit) v', false)->join('accounts a', 'a.un_id = tl.account_un_id')
            ->whereIn('a.code', $codes)->whereIn('tl.business_un_id', $biz ?? $this->bizIds())->where('tl.status', 'posted')
            ->where('tl.date >=', $from)->where('tl.date <=', $to)->get()->getRowArray();

        return round((float) ($r['v'] ?? 0), 2);
    }

    private function balanceOf(array $codes, ?array $biz = null): float
    {
        $r = $this->db->table('transaction_lines tl')->select('SUM(tl.debit - tl.credit) v', false)->join('accounts a', 'a.un_id = tl.account_un_id')
            ->whereIn('a.code', $codes)->whereIn('tl.business_un_id', $biz ?? $this->bizIds())->where('tl.status', 'posted')->get()->getRowArray();

        return round((float) ($r['v'] ?? 0), 2);
    }

    /** Dashboard businesses that also grant $perm, so each card only sums what the user may see. */
    private function idsFor(string $perm): array
    {
        return array_values(array_filter($this->bizIds(), static fn ($b) => Ctx::can($perm, $b))) ?: ['-'];
    }

    public function dashboard()
    {
        $this->need('dashboard.view');
        $ids = $this->idsFor('finance.view');
        $visaIds = $this->idsFor('visa.view');
        $expIds = $this->idsFor('expense.view');
        $m0 = date('Y-m-01');
        $t = today();
        $cash = $this->db->table('transaction_lines tl')->select('SUM(tl.debit) i, SUM(tl.credit) o, SUM(tl.debit - tl.credit) b', false)
            ->join('accounts a', 'a.un_id = tl.account_un_id')->join('transactions tx', 'tx.un_id = tl.transaction_un_id')
            ->whereIn('a.subtype', ['cash', 'bank', 'mobile'])->whereIn('tl.business_un_id', $ids)->where('tl.status', 'posted');
        $bal = (clone $cash)->get()->getRowArray();
        $mon = $cash->where('tl.date >=', $m0)->where('tx.type <>', 'transfer')->get()->getRowArray();
        $visaProfit = -$this->flow(['VISA_SALES', 'VISA_COST', 'COMMISSION', 'DISCOUNT'], $m0, $t, $this->idsFor('visa.cost'));
        $projPl = -$this->flow(['PROJECT_SALES', 'PROJECT_COST'], $m0, $t, $this->idsFor('project.view'));
        $overdue = $this->db->table('payment_schedules ps')->select('COUNT(*) n, SUM(ps.amount - ps.paid_amount) amt', false)->join('visa_files vf', 'vf.un_id = ps.visa_file_un_id')
            ->where(['ps.deleted_at' => null, 'vf.status' => 'active', 'vf.deleted_at' => null])->whereIn('vf.business_un_id', $visaIds)->where('ps.due_date <', $t)->where('ps.paid_amount < ps.amount', null, false)->get()->getRowArray();
        $passports = $this->db->table('visa_files vf')->join('parties p', 'p.un_id = vf.candidate_un_id')->where(['vf.status' => 'active', 'vf.deleted_at' => null])
            ->whereIn('vf.business_un_id', $visaIds)->where('p.passport_expiry IS NOT NULL', null, false)->where('p.passport_expiry <=', date('Y-m-d', strtotime('+180 days')))->countAllResults();
        $recurring = $this->db->table('expenses')->select('title, amount, recur_next_date')->where(['is_recurring' => 1, 'deleted_at' => null])->whereIn('business_un_id', $expIds)
            ->where('recur_next_date <=', date('Y-m-d', strtotime('+7 days')))->orderBy('recur_next_date')->limit(3)->get()->getResultArray();
        $pending = $this->db->table('expenses')->where(['status' => 'pending', 'deleted_at' => null])->whereIn('business_un_id', $this->idsFor('expense.approve'))->countAllResults();

        return $this->ok([
            'balance'      => round((float) $bal['b'], 2),
            'month_in'     => round((float) $mon['i'], 2),
            'month_out'    => round((float) $mon['o'], 2),
            'visa_profit'  => Ctx::can('visa.cost') ? $visaProfit : null,
            'project_pl'   => $projPl,
            'receivable'   => $this->balanceOf(['AR'], $ids),
            'payable'      => -$this->balanceOf(['AP'], $ids),
            'attention'    => [
                'passports_expiring' => $passports,
                'overdue_count'      => (int) $overdue['n'],
                'overdue_amount'     => round((float) $overdue['amt'], 2),
                'pending_approvals'  => $pending,
                'recurring'          => $recurring,
            ],
            'unread_notifications' => $this->db->table('notifications')->where(['user_un_id' => Ctx::$user['un_id'], 'read_at' => null, 'deleted_at' => null])->countAllResults(),
            'cashflow'     => $this->db->query("SELECT DATE_FORMAT(tl.date, '%Y-%m') m, SUM(tl.debit) i, SUM(tl.credit) o FROM transaction_lines tl JOIN accounts a ON a.un_id = tl.account_un_id
                JOIN transactions tx ON tx.un_id = tl.transaction_un_id WHERE a.subtype IN ('cash','bank','mobile') AND tx.type <> 'transfer' AND tl.status = 'posted' AND tl.business_un_id IN ?
                AND tl.date >= ? GROUP BY m ORDER BY m", [$ids, date('Y-m-01', strtotime('-6 months'))])->getResultArray(),
        ]);
    }

    /** P&L by business (and by account inside each business). */
    public function pnl()
    {
        $this->need('report.view');
        [$from, $to] = $this->range();
        $rows = $this->db->table('transaction_lines tl')->select('tl.business_un_id, bz.name business_name, a.name account, a.code, a.type, SUM(tl.credit - tl.debit) amount', false)
            ->join('accounts a', 'a.un_id = tl.account_un_id')->join('businesses bz', 'bz.un_id = tl.business_un_id')
            ->whereIn('a.type', ['income', 'expense'])->whereIn('tl.business_un_id', $this->bizIds())->where('tl.status', 'posted')
            ->where('tl.date >=', $from)->where('tl.date <=', $to)->groupBy('tl.business_un_id, a.un_id')->get()->getResultArray();
        $out = [];
        foreach ($rows as $r) {
            $b = &$out[$r['business_un_id']];
            $b['business_name'] = $r['business_name'];
            $b['income'] = ($b['income'] ?? 0) + ($r['type'] === 'income' ? $r['amount'] : 0);
            $b['expense'] = ($b['expense'] ?? 0) - ($r['type'] === 'expense' ? $r['amount'] : 0);
            $b['lines'][] = ['account' => $r['account'], 'type' => $r['type'], 'amount' => round(abs($r['amount']), 2)];
        }
        foreach ($out as &$b) {
            $b['profit'] = round($b['income'] - $b['expense'], 2);
        }

        return $this->ok(['from' => $from, 'to' => $to, 'businesses' => array_values($out), 'total_profit' => round(array_sum(array_column($out, 'profit')), 2)]);
    }

    public function trialBalance()
    {
        $this->need('report.view');
        $to = $this->request->getGet('to') ?? today();
        $rows = $this->db->table('accounts a')->select('a.code, a.name, a.type, bz.name business_name, SUM(tl.debit) debit, SUM(tl.credit) credit', false)
            ->join('transaction_lines tl', "tl.account_un_id = a.un_id AND tl.status = 'posted' AND tl.date <= " . $this->db->escape($to))
            ->join('businesses bz', 'bz.un_id = a.business_un_id')->whereIn('a.business_un_id', $this->bizIds())->groupBy('a.un_id')->orderBy('a.type')->get()->getResultArray();

        return $this->ok(['to' => $to, 'rows' => $rows, 'debit' => round(array_sum(array_column($rows, 'debit')), 2), 'credit' => round(array_sum(array_column($rows, 'credit')), 2)]);
    }

    /** Receivables & payables by party. */
    public function dues()
    {
        $this->need('report.view');
        $rows = $this->db->table('transaction_lines tl')->select('p.un_id, p.name, p.phone, p.roles, SUM(tl.debit - tl.credit) balance', false)
            ->join('accounts a', 'a.un_id = tl.account_un_id')->join('parties p', 'p.un_id = tl.party_un_id')
            ->whereIn('a.code', ['AR', 'AP'])->whereIn('tl.business_un_id', $this->bizIds())->where('tl.status', 'posted')
            ->groupBy('p.un_id')->having('ABS(balance) >', 0.009, false)->orderBy('balance', 'DESC')->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['roles'] = jdec($r['roles']);
        }

        return $this->ok(['receivable' => array_values(array_filter($rows, fn ($r) => $r['balance'] > 0)), 'payable' => array_values(array_filter($rows, fn ($r) => $r['balance'] < 0))]);
    }

    public function visaSales()
    {
        $this->need('report.view');
        [$from, $to] = $this->range();
        $by = in_array($this->request->getGet('by'), ['country', 'sub_agent_un_id', 'status'], true) ? $this->request->getGet('by') : 'country';
        $cost = Ctx::can('visa.cost') ? ', SUM(vf.purchase_cost + COALESCE((SELECT SUM(c.amount) FROM visa_costs c WHERE c.visa_file_un_id = vf.un_id AND c.deleted_at IS NULL),0)) cost' : '';
        $label = $by === 'sub_agent_un_id' ? "COALESCE(MAX(sa.name), 'Direct')" : "vf.$by";
        $rows = $this->db->query("SELECT vf.$by grp, $label label, COUNT(*) files, SUM(vf.selling_price - vf.discount) billed $cost
            FROM visa_files vf LEFT JOIN parties sa ON sa.un_id = vf.sub_agent_un_id WHERE vf.deleted_at IS NULL AND vf.business_un_id IN ? AND DATE(vf.created_at) BETWEEN ? AND ? GROUP BY vf.$by ORDER BY billed DESC",
            [$this->bizIds(), $from, $to])->getResultArray();

        return $this->ok(['from' => $from, 'to' => $to, 'by' => $by, 'rows' => $rows]);
    }

    public function commissions()
    {
        $this->need('report.view');
        $rows = $this->db->query("SELECT p.un_id, p.name, COUNT(vf.id) files, SUM(vf.commission_accrued) earned_files,
            SUM(CASE WHEN vf.commission_type = 'percent' THEN (vf.selling_price - vf.discount) * vf.commission_value / 100 ELSE vf.commission_value END) total,
            SUM(CASE WHEN vf.commission_accrued = 1 THEN (CASE WHEN vf.commission_type = 'percent' THEN (vf.selling_price - vf.discount) * vf.commission_value / 100 ELSE vf.commission_value END) ELSE 0 END) earned
            FROM visa_files vf JOIN parties p ON p.un_id = vf.sub_agent_un_id WHERE vf.deleted_at IS NULL AND vf.business_un_id IN ? GROUP BY p.un_id", [$this->bizIds()])->getResultArray();
        foreach ($rows as &$r) {
            $r['balance_payable'] = -\App\Libraries\Ledger::partyBalance($r['un_id'], $this->bizIds());
        }

        return $this->ok($rows);
    }

    public function expenses()
    {
        $this->need('report.view');
        [$from, $to] = $this->range();
        $rows = $this->db->table('expenses e')->select('c.name category, bz.name business_name, COUNT(*) n, SUM(e.amount * e.rate) amount', false)
            ->join('categories c', 'c.un_id = e.category_un_id', 'left')->join('businesses bz', 'bz.un_id = e.business_un_id')
            ->where(['e.status' => 'approved', 'e.deleted_at' => null])->whereIn('e.business_un_id', $this->bizIds())->where('e.date >=', $from)->where('e.date <=', $to)
            ->groupBy('e.category_un_id, e.business_un_id')->orderBy('amount', 'DESC')->get()->getResultArray();

        return $this->ok(['from' => $from, 'to' => $to, 'rows' => $rows, 'total' => round(array_sum(array_column($rows, 'amount')), 2)]);
    }
}
