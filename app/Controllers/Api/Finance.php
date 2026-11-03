<?php

namespace App\Controllers\Api;

use App\Libraries\ApiException;
use App\Libraries\Audit;
use App\Libraries\Ctx;
use App\Libraries\Ledger;
use App\Libraries\Seq;

class Finance extends BaseApi
{
    /** Cash/bank/mobile accounts with live balances. */
    public function balances()
    {
        $this->need('finance.view');
        $b = $this->db->table('accounts a')->select('a.un_id, a.business_un_id, a.name, a.subtype, a.bank_name, a.account_no, a.currency, bz.name business_name')
            ->select("(SELECT COALESCE(SUM(debit - credit),0) FROM transaction_lines tl WHERE tl.account_un_id = a.un_id AND tl.status = 'posted') balance", false)
            ->join('businesses bz', 'bz.un_id = a.business_un_id')
            ->whereIn('a.subtype', ['cash', 'bank', 'mobile'])->where('a.deleted_at', null);
        $this->scope($b, 'a.business_un_id');
        $rows = $b->get()->getResultArray();

        return $this->ok(['total' => round(array_sum(array_column($rows, 'balance')), 2), 'accounts' => $rows]);
    }

    public function transactions()
    {
        $this->need('finance.view');
        $b = $this->db->table('transactions t')->select('t.*, p.name party_name, bz.name business_name')
            ->join('parties p', 'p.un_id = t.party_un_id', 'left')->join('businesses bz', 'bz.un_id = t.business_un_id')
            ->where(['t.deleted_at' => null]);
        $this->scope($b, 't.business_un_id');
        $g = $this->request->getGet();
        foreach (['type', 'status', 'party_un_id', 'entity_type', 'entity_un_id'] as $f) {
            if (! empty($g[$f])) {
                $b->where('t.' . $f, $g[$f]);
            }
        }
        if (! empty($g['account_un_id'])) {
            $b->whereIn('t.un_id', fn ($q) => $q->select('transaction_un_id')->from('transaction_lines')->where('account_un_id', $g['account_un_id']));
        }
        if (! empty($g['from'])) {
            $b->where('t.date >=', $g['from']);
        }
        if (! empty($g['to'])) {
            $b->where('t.date <=', $g['to']);
        }
        if ($q = trim($g['q'] ?? '')) {
            $b->groupStart()->like('t.no', $q)->orLike('t.note', $q)->orLike('t.ref_no', $q)->orLike('p.name', $q)->groupEnd();
        }
        $b->orderBy('t.date', 'DESC')->orderBy('t.id', 'DESC');

        return $this->page($b, function ($r) {
            unset($r['id']);
            $r['lines'] = $this->db->table('transaction_lines tl')->select('tl.debit, tl.credit, a.name account_name, a.code, a.subtype')
                ->join('accounts a', 'a.un_id = tl.account_un_id')->where('tl.transaction_un_id', $r['un_id'])->get()->getResultArray();

            return $r;
        });
    }

    /** body: direction in|out, party_un_id, account_un_id, amount, date, note, ref_no, currency, rate */
    public function partyPayment()
    {
        $in = $this->in();
        $this->need(($in['direction'] ?? null) === 'out' ? 'finance.pay' : 'finance.receive');
        $biz = $this->bizOfAccount($in['account_un_id'] ?? null);
        $this->validate_($in, ['party_un_id' => 'required', 'account_un_id' => 'required', 'amount' => 'required|decimal|greater_than[0]', 'direction' => 'required|in_list[in,out]']);
        $this->row('accounts', $in['account_un_id']);
        $amt = round($in['amount'] * ((float) ($in['rate'] ?? 1) ?: 1), 2);
        $lines = $in['direction'] === 'in'
            ? [[$in['account_un_id'], $amt, 0], ['@AR', 0, $amt, $in['party_un_id']]]
            : [['@AP', $amt, 0, $in['party_un_id']], [$in['account_un_id'], 0, $amt]];
        $tx = Ledger::post($biz, ['type' => $in['direction'] === 'in' ? 'receipt' : 'payment', 'date' => $in['date'] ?? today(), 'party_un_id' => $in['party_un_id'],
            'currency' => $in['currency'] ?? 'BDT', 'rate' => $in['rate'] ?? 1, 'ref_no' => $in['ref_no'] ?? null, 'note' => $in['note'] ?? null,
            'idem_key' => $this->request->getHeaderLine('Idempotency-Key') ?: null], $lines);

        $no = $this->db->table('transactions')->select('no')->where('un_id', $tx)->get()->getRowArray()['no'] ?? null;

        return $this->ok(['un_id' => $tx, 'no' => $no], 'Saved', 201);
    }

    /** body: from_account_un_id, to_account_un_id, amount, date, note. Cross-business creates inter-company entries. */
    public function transfer()
    {
        $this->need('finance.transfer');
        $in = $this->in();
        $this->validate_($in, ['from_account_un_id' => 'required', 'to_account_un_id' => 'required|differs[from_account_un_id]', 'amount' => 'required|decimal|greater_than[0]']);
        $from = $this->row('accounts', $in['from_account_un_id']);
        $to = $this->row('accounts', $in['to_account_un_id']);
        $amt = round((float) $in['amount'], 2);
        $h = ['type' => 'transfer', 'date' => $in['date'] ?? today(), 'note' => $in['note'] ?? null, 'ref_no' => $in['ref_no'] ?? null];
        $this->db->transStart();
        if ($from['business_un_id'] === $to['business_un_id']) {
            $tx = Ledger::post($from['business_un_id'], $h + ['idem_key' => $this->request->getHeaderLine('Idempotency-Key') ?: null], [[$to['un_id'], $amt, 0], [$from['un_id'], 0, $amt]]);
        } else {
            $tx = Ledger::post($from['business_un_id'], $h + ['entity_type' => 'business', 'entity_un_id' => $to['business_un_id']], [['@DUE_FROM', $amt, 0], [$from['un_id'], 0, $amt]]);
            Ledger::post($to['business_un_id'], $h + ['entity_type' => 'business', 'entity_un_id' => $from['business_un_id']], [[$to['un_id'], $amt, 0], ['@DUE_TO', 0, $amt]]);
        }
        $this->db->transComplete();

        return $this->ok(['un_id' => $tx, 'inter_business' => $from['business_un_id'] !== $to['business_un_id']], 'Transferred', 201);
    }

    public function void(string $un)
    {
        $this->need('finance.void');
        $t = $this->row('transactions', $un);
        // Only stand-alone entries: voiding a visa sale, expense, payroll or inter-business leg here
        // would leave its source record (installment paid, expense approved, ...) out of sync.
        if (! in_array($t['type'], ['receipt', 'payment', 'transfer'], true) || $t['entity_type'] !== null) {
            throw new ApiException('This entry belongs to a ' . str_replace('_', ' ', $t['entity_type'] ?? $t['type']) . '; change it from there.', 422);
        }
        if ($t['status'] === 'void') {
            throw new ApiException('Already void', 422);
        }
        Ledger::void($un);
        Audit::log('void', 'transactions', $un, $t, null, $t['business_un_id']);

        return $this->ok(null, 'Voided');
    }

    /** Statement for a party: running balance on AR/AP lines. */
    public function partyLedger(string $party)
    {
        $this->need('party.view');
        $b = $this->db->table('transaction_lines tl')->select('t.un_id, t.no, t.date, t.type, t.note, t.ref_no, tl.debit, tl.credit, bz.name business_name')
            ->join('transactions t', 't.un_id = tl.transaction_un_id')->join('accounts a', 'a.un_id = tl.account_un_id')->join('businesses bz', 'bz.un_id = t.business_un_id')
            ->whereIn('a.code', ['AR', 'AP'])->where(['tl.party_un_id' => $party, 'tl.status' => 'posted'])->orderBy('t.date')->orderBy('t.id');
        $this->scope($b, 'tl.business_un_id');
        $rows = $b->get()->getResultArray();
        $bal = 0;
        $sum = ['billed' => 0, 'received' => 0, 'paid' => 0, 'payable' => 0];
        foreach ($rows as &$r) {
            $bal += $r['debit'] - $r['credit'];
            $r['balance'] = round($bal, 2);
            match ($r['type']) {
                'receipt' => $sum['received'] += $r['credit'],
                'payment' => $sum['paid'] += $r['debit'],
                default   => $r['debit'] > 0 ? $sum['billed'] += $r['debit'] : $sum['payable'] += $r['credit'],
            };
        }
        $disc = (float) ($this->db->table('transaction_lines tl')->selectSum('tl.debit', 'd')->join('transactions t', 't.un_id = tl.transaction_un_id')->join('accounts a', 'a.un_id = tl.account_un_id')
            ->where(['t.party_un_id' => $party, 'a.code' => 'DISCOUNT', 'tl.status' => 'posted'])->get()->getRowArray()['d'] ?? 0);

        $p = $this->db->table('parties')->where('un_id', $party)->get()->getRowArray();
        foreach (['roles', 'meta'] as $k) {
            if ($p && is_string($p[$k] ?? null)) {
                $p[$k] = json_decode($p[$k], true);
            }
        }

        return $this->ok(['party' => $p, 'summary' => $sum + ['discount' => $disc, 'balance' => round($bal, 2)], 'entries' => $rows]);
    }

    public function accountLedger(string $acc)
    {
        $this->need('finance.view');
        $a = $this->row('accounts', $acc);
        $g = $this->request->getGet();
        $from = $g['from'] ?? date('Y-m-01');
        $to = $g['to'] ?? today();
        $open = Ledger::balance($acc, date('Y-m-d', strtotime($from . ' -1 day')));
        $rows = $this->db->table('transaction_lines tl')->select('t.un_id, t.no, t.date, t.type, t.note, t.ref_no, tl.debit, tl.credit, p.name party_name')
            ->join('transactions t', 't.un_id = tl.transaction_un_id')->join('parties p', 'p.un_id = t.party_un_id', 'left')
            ->where(['tl.account_un_id' => $acc, 'tl.status' => 'posted'])->where('tl.date >=', $from)->where('tl.date <=', $to)->orderBy('t.date')->orderBy('t.id')->get()->getResultArray();
        $sign = in_array($a['type'], ['asset', 'expense'], true) ? 1 : -1;
        $bal = $open;
        foreach ($rows as &$r) {
            $bal += $sign * ($r['debit'] - $r['credit']);
            $r['balance'] = round($bal, 2);
        }
        unset($a['id']);

        return $this->ok(['account' => $a, 'from' => $from, 'to' => $to, 'opening' => round($open, 2), 'closing' => round($bal, 2), 'entries' => $rows]);
    }

    // ---------- Expenses ----------

    public function expenses()
    {
        $this->need('expense.view');
        $b = $this->db->table('expenses e')->select('e.*, c.name category_name, a.name account_name, bz.name business_name, u.name created_by_name')
            ->join('categories c', 'c.un_id = e.category_un_id', 'left')->join('accounts a', 'a.un_id = e.account_un_id', 'left')
            ->join('businesses bz', 'bz.un_id = e.business_un_id')->join('users u', 'u.un_id = e.created_by', 'left')->where('e.deleted_at', null);
        $this->scope($b, 'e.business_un_id');
        $g = $this->request->getGet();
        foreach (['status', 'category_un_id', 'project_un_id', 'visa_file_un_id', 'is_recurring'] as $f) {
            if (isset($g[$f]) && $g[$f] !== '') {
                $b->where('e.' . $f, $g[$f]);
            }
        }
        if (! empty($g['from'])) {
            $b->where('e.date >=', $g['from']);
        }
        if (! empty($g['to'])) {
            $b->where('e.date <=', $g['to']);
        }

        return $this->page($b->orderBy('e.date', 'DESC')->orderBy('e.id', 'DESC'), static function ($r) {
            unset($r['id']);

            return $r;
        });
    }

    private function approvalLimit(string $biz): float
    {
        $s = $this->db->table('settings')->where('key', 'expense_approval_limit')->groupStart()->where('business_un_id', $biz)->orWhere('business_un_id', null)->groupEnd()
            ->orderBy('business_un_id', 'DESC')->get()->getRowArray();

        return (float) ($s['value'] ?? 25000);
    }

    public function postExpense(array $e): string
    {
        $amt = round($e['amount'] * ((float) $e['rate'] ?: 1), 2);
        $credit = $e['account_un_id'] ? [$e['account_un_id'], 0, $amt] : ['@AP', 0, $amt, $e['party_un_id']];
        $debit = $e['project_un_id'] ? '@PROJECT_COST' : ($e['visa_file_un_id'] ? '@VISA_COST' : '@EXPENSE');

        return Ledger::post($e['business_un_id'], ['type' => 'expense', 'date' => $e['date'], 'party_un_id' => $e['party_un_id'], 'entity_type' => 'expense', 'entity_un_id' => $e['un_id'],
            'currency' => $e['currency'], 'rate' => $e['rate'], 'note' => $e['title']], [[$debit, $amt, 0, null, $e['category_un_id'], $e['project_un_id']], $credit]);
    }

    public function createExpense()
    {
        $this->need('expense.create');
        $in = $this->in();
        $biz = $this->bizOfAccount($in['account_un_id'] ?? null);
        $this->validate_($in, ['title' => 'required', 'amount' => 'required|decimal|greater_than[0]', 'date' => 'required|valid_date[Y-m-d]']);
        if (empty($in['account_un_id']) && empty($in['party_un_id'])) {
            throw new ApiException('Choose paid-from account or payee', 422);
        }
        $e = array_intersect_key($in, array_flip(['date', 'title', 'category_un_id', 'amount', 'currency', 'rate', 'account_un_id', 'party_un_id', 'project_un_id', 'visa_file_un_id', 'note', 'is_recurring', 'recur_interval', 'recur_end_date']));
        $e += ['currency' => 'BDT', 'rate' => 1, 'account_un_id' => null, 'party_un_id' => null, 'project_un_id' => null, 'visa_file_un_id' => null, 'category_un_id' => null];
        $e += ['un_id' => uid(), 'business_un_id' => $biz, 'no' => Seq::next($biz, 'EXP', 'EXP'), 'created_by' => Ctx::$user['un_id'], 'created_at' => now(), 'updated_at' => now()];
        if (! empty($e['is_recurring'])) {
            $e['recur_next_date'] = $this->nextDate($e['date'], $e['recur_interval'] ?? 'monthly');
        }
        $needsApproval = ($e['amount'] * $e['rate']) > $this->approvalLimit($biz) && ! Ctx::can('expense.approve');
        $e['status'] = $needsApproval ? 'pending' : 'approved';
        $this->db->transStart();
        if (! $needsApproval) {
            $e['approved_by'] = Ctx::$user['un_id'];
            $e['transaction_un_id'] = $this->postExpense($e);
        }
        $this->db->table('expenses')->insert($e);
        $this->db->transComplete();
        Audit::log('create', 'expenses', $e['un_id'], null, $e);
        if ($needsApproval) {
            \App\Libraries\Notify::toPerm($biz, 'expense.approve', 'approval', 'Expense needs approval', $e['title'] . ' · ৳ ' . number_format($e['amount'] * $e['rate']), 'expense', $e['un_id']);
        }

        return $this->ok($e, $needsApproval ? 'Sent for approval' : 'Saved', 201);
    }

    public function nextDate(string $d, string $i): string
    {
        if (in_array($i, ['daily', 'weekly'], true)) {
            return date('Y-m-d', strtotime($d . ' +1 ' . ($i === 'daily' ? 'day' : 'week')));
        }
        // Clamp to month end so 31 Jan -> 28/29 Feb instead of overflowing into March.
        [$y, $m, $day] = array_map('intval', explode('-', $d));
        $i === 'yearly' ? $y++ : $m++;
        if ($m > 12) {
            [$m, $y] = [1, $y + 1];
        }

        return sprintf('%04d-%02d-%02d', $y, $m, min($day, (int) date('t', mktime(0, 0, 0, $m, 1, $y))));
    }

    public function decideExpense(string $un, string $decision)
    {
        $this->need('expense.approve');
        $e = $this->row('expenses', $un);
        if ($e['status'] !== 'pending') {
            throw new ApiException('Expense is not pending', 422);
        }
        $this->db->transStart();
        $upd = ['status' => $decision === 'approve' ? 'approved' : 'rejected', 'approved_by' => Ctx::$user['un_id'], 'updated_at' => now()];
        if ($decision === 'approve') {
            $upd['transaction_un_id'] = $this->postExpense($e);
        }
        $this->db->table('expenses')->where('un_id', $un)->update($upd);
        $this->db->transComplete();
        Audit::log($decision, 'expenses', $un, null, $upd, $e['business_un_id']);

        return $this->ok($upd, $decision === 'approve' ? 'Approved' : 'Rejected');
    }

    public function deleteExpense(string $un)
    {
        $this->need('expense.delete');
        $e = $this->row('expenses', $un);
        Ledger::void($e['transaction_un_id']);
        $this->db->table('expenses')->where('un_id', $un)->update(['deleted_at' => now(), 'is_recurring' => 0]);
        Audit::log('delete', 'expenses', $un, $e, null, $e['business_un_id']);

        return $this->ok(null, 'Deleted');
    }
}
