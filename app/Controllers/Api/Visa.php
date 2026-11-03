<?php

namespace App\Controllers\Api;

use App\Libraries\ApiException;
use App\Libraries\Audit;
use App\Libraries\Ctx;
use App\Libraries\Ledger;
use App\Libraries\Seq;

class Visa extends BaseApi
{
    private function paidSql(): string
    {
        return "(SELECT COALESCE(SUM(tl.credit - tl.debit),0) FROM transaction_lines tl JOIN transactions t ON t.un_id = tl.transaction_un_id
            JOIN accounts a ON a.un_id = tl.account_un_id WHERE t.entity_type = 'visa_file' AND t.entity_un_id = vf.un_id AND t.type = 'receipt' AND a.code = 'AR' AND tl.status = 'posted')";
    }

    private function list()
    {
        $b = $this->db->table('visa_files vf')
            ->select('vf.*, p.name candidate_name, p.phone candidate_phone, p.passport_no, p.passport_expiry, p.photo, s.name stage_name, s.color stage_color, s.sort stage_sort, sa.name sub_agent_name')
            ->select($this->paidSql() . ' paid', false)
            ->join('parties p', 'p.un_id = vf.candidate_un_id')
            ->join('pipeline_stages s', 's.un_id = vf.stage_un_id', 'left')
            ->join('parties sa', 'sa.un_id = vf.sub_agent_un_id', 'left')
            ->where('vf.deleted_at', null);
        $this->scope($b, 'vf.business_un_id');
        $g = $this->request->getGet();
        foreach (['stage_un_id', 'pipeline_un_id', 'country', 'status', 'sub_agent_un_id', 'batch_un_id', 'candidate_un_id'] as $f) {
            if (! empty($g[$f])) {
                $b->where('vf.' . $f, $g[$f]);
            }
        }
        if (! empty($g['stage_name'])) {
            $b->where('s.name', $g['stage_name']);
        }
        // Dashboard "Needs attention" drill-downs; rules must match Reports::dashboard.
        $overdueSql = '(SELECT COALESCE(SUM(ps.amount - ps.paid_amount), 0) FROM payment_schedules ps WHERE ps.visa_file_un_id = vf.un_id AND ps.deleted_at IS NULL AND ps.due_date < ' . $this->db->escape(today()) . ' AND ps.paid_amount < ps.amount)';
        if (($g['attention'] ?? '') === 'passport') {
            $b->where('vf.status', 'active')->where('p.passport_expiry IS NOT NULL', null, false)
                ->where('p.passport_expiry <=', date('Y-m-d', strtotime('+180 days')))->orderBy('p.passport_expiry', 'ASC');
        } elseif (($g['attention'] ?? '') === 'overdue') {
            $b->select($overdueSql . ' overdue_amount', false)->where('vf.status', 'active')->where($overdueSql . ' > 0', null, false)->orderBy('overdue_amount', 'DESC');
        }
        if ($q = trim($g['q'] ?? '')) {
            $b->groupStart()->like('p.name', $q)->orLike('p.passport_no', $q)->orLike('p.phone', $q)->orLike('vf.no', $q)->orLike('vf.visa_no', $q)->groupEnd();
        }

        return $b;
    }

    private function shape(array $r): array
    {
        $r['billed'] = round($r['selling_price'] - $r['discount'], 2);
        $r['paid'] = round((float) $r['paid'], 2);
        // Cancelled/void files have their sale reversed: nothing is due.
        $r['due'] = in_array($r['status'], ['cancelled', 'void'], true) ? 0 : round($r['billed'] - $r['paid'], 2);
        $r['meta'] = jdec($r['meta']);
        if (! Ctx::can('visa.cost', $r['business_un_id'])) {
            unset($r['purchase_cost']);
        }
        unset($r['id']);

        return $r;
    }

    public function index()
    {
        $this->need('visa.view');
        $b = $this->list()->orderBy('vf.id', 'DESC');

        return $this->page($b, fn ($r) => $this->shape($r));
    }

    public function summary()
    {
        $this->need('visa.view');
        $b = $this->db->table('visa_files vf')->where('vf.deleted_at', null);
        $this->scope($b, 'vf.business_un_id');
        $active = (clone $b)->where('vf.status', 'active')->countAllResults();
        $month = (clone $b)->where('vf.created_at >=', date('Y-m-01'))->countAllResults();
        $overdue = $this->db->table('payment_schedules ps')->join('visa_files vf', 'vf.un_id = ps.visa_file_un_id')
            ->where(['ps.deleted_at' => null, 'vf.status' => 'active'])->where('ps.due_date <', today())->where('ps.paid_amount < ps.amount', null, false);
        $this->scope($overdue, 'vf.business_un_id');
        $stages = $this->db->table('visa_files vf')->select('vf.stage_un_id, COUNT(*) n')->where(['vf.deleted_at' => null, 'vf.status' => 'active'])->groupBy('vf.stage_un_id');
        $this->scope($stages, 'vf.business_un_id');

        return $this->ok(['active' => $active, 'this_month' => $month, 'overdue' => $overdue->countAllResults(), 'by_stage' => array_column($stages->get()->getResultArray(), 'n', 'stage_un_id')]);
    }

    public function show(string $un)
    {
        $this->need('visa.view');
        $r = $this->list()->where('vf.un_id', $un)->get()->getRowArray() ?? throw new ApiException('Not found', 404);
        $r = $this->shape($r);
        $r['candidate'] = $this->db->table('parties')->where('un_id', $r['candidate_un_id'])->get()->getRowArray();
        $r['stages'] = $this->db->table('pipeline_stages')->where(['pipeline_un_id' => $r['pipeline_un_id'], 'deleted_at' => null])->orderBy('sort')->get()->getResultArray();
        $r['history'] = $this->db->table('visa_stage_history h')->select('h.*, s.name to_stage, u.name user_name')
            ->join('pipeline_stages s', 's.un_id = h.to_stage_un_id', 'left')->join('users u', 'u.un_id = h.user_un_id', 'left')
            ->where('h.visa_file_un_id', $un)->orderBy('h.id')->get()->getResultArray();
        $r['schedules'] = $this->db->table('payment_schedules')->where(['visa_file_un_id' => $un, 'deleted_at' => null])->orderBy('due_date')->get()->getResultArray();
        $r['documents'] = $this->db->table('visa_documents d')->select('d.*, a.path, a.name file_name, a.mime')
            ->join('attachments a', 'a.un_id = d.attachment_un_id', 'left')->where(['d.visa_file_un_id' => $un, 'd.deleted_at' => null])->get()->getResultArray();
        $r['payments'] = $this->db->table('transactions t')->select('t.un_id, t.no, t.date, t.amount, t.ref_no, t.note, a.name account_name')
            ->join('transaction_lines tl', 'tl.transaction_un_id = t.un_id AND tl.debit > 0')->join('accounts a', 'a.un_id = tl.account_un_id')
            ->where(['t.entity_type' => 'visa_file', 't.entity_un_id' => $un, 't.type' => 'receipt', 't.status' => 'posted'])->orderBy('t.date')->get()->getResultArray();
        $costs = $this->db->table('visa_costs')->where(['visa_file_un_id' => $un, 'deleted_at' => null])->get()->getResultArray();
        $closed = in_array($r['status'], ['cancelled', 'void'], true);
        $r['commission'] = $closed && ! $r['commission_accrued'] ? 0 : $this->commission($r);
        // Refunds are payments that debit AR (cost payments debit an expense instead).
        $r['refunded'] = round((float) $this->db->table('transactions t')->selectSum('tl.debit', 'amt')
            ->join('transaction_lines tl', 'tl.transaction_un_id = t.un_id')->join('accounts a', 'a.un_id = tl.account_un_id')
            ->where(['t.entity_type' => 'visa_file', 't.entity_un_id' => $un, 't.type' => 'payment', 't.status' => 'posted', 'a.code' => 'AR'])->get()->getRow()->amt, 2);
        if (Ctx::can('visa.cost', $r['business_un_id'])) {
            $r['costs'] = $costs;
            // A cancelled file with a batch had its slot released back to stock (see cancel()).
            $purchase = $r['status'] === 'cancelled' && $r['batch_un_id'] ? 0 : $r['purchase_cost'];
            $revenue = $closed ? $r['paid'] - $r['refunded'] : $r['billed'];
            $r['total_cost'] = round($purchase + array_sum(array_column($costs, 'amount')) + $r['commission'], 2);
            $r['profit'] = round($revenue - $r['total_cost'], 2);
        }

        return $this->ok($r);
    }

    private function open(array $vf): void
    {
        if (in_array($vf['status'], ['cancelled', 'void'], true)) {
            throw new ApiException('File is ' . $vf['status'], 422);
        }
    }

    private function commission(array $vf): float
    {
        if (! $vf['sub_agent_un_id']) {
            return 0;
        }

        return round($vf['commission_type'] === 'percent' ? ($vf['selling_price'] - $vf['discount']) * $vf['commission_value'] / 100 : (float) $vf['commission_value'], 2);
    }

    private function postSale(array $vf): void
    {
        $billed = $vf['selling_price'] - $vf['discount'];
        if ($vf['selling_price'] <= 0) {
            return;
        }
        Ledger::post($vf['business_un_id'], ['type' => 'sale', 'party_un_id' => $vf['candidate_un_id'], 'entity_type' => 'visa_file', 'entity_un_id' => $vf['un_id'], 'note' => 'Visa sale ' . $vf['no']], [
            ['@AR', $billed, 0, $vf['candidate_un_id']],
            ['@DISCOUNT', $vf['discount'], 0],
            ['@VISA_SALES', 0, $vf['selling_price']],
        ]);
    }

    public function create()
    {
        $this->need('visa.create');
        $biz = $this->biz();
        $in = $this->in();
        if (empty($in['candidate_un_id']) && ! empty($in['candidate']['name'])) {
            $c = array_intersect_key($in['candidate'], array_flip(['name', 'phone', 'email', 'nid', 'passport_no', 'passport_expiry', 'dob', 'address', 'photo']));
            $in['candidate_un_id'] = uid();
            $this->db->table('parties')->insert($c + ['un_id' => $in['candidate_un_id'], 'roles' => '["candidate"]', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->validate_($in, ['candidate_un_id' => 'required', 'selling_price' => 'permit_empty|decimal']);
        $this->db->transStart();
        $vf = array_intersect_key($in, array_flip(['candidate_un_id', 'batch_un_id', 'pipeline_un_id', 'country', 'job_title', 'sponsor', 'visa_no', 'work_permit_no', 'visa_expiry', 'sub_agent_un_id', 'commission_type', 'commission_value', 'purchase_cost', 'selling_price', 'discount', 'currency', 'rate', 'note']));
        $vf['meta'] = isset($in['meta']) ? json_encode($in['meta']) : null;
        $rate = (float) ($vf['rate'] ?? 1) ?: 1;
        $vf['selling_price'] = round((float) ($vf['selling_price'] ?? 0) * $rate, 2);
        $vf['discount'] = round((float) ($vf['discount'] ?? 0) * $rate, 2);
        if (! empty($vf['batch_un_id'])) {
            $bt = $this->row('visa_batches', $vf['batch_un_id']);
            $used = $this->db->table('visa_files')->where(['batch_un_id' => $bt['un_id'], 'deleted_at' => null])->whereNotIn('status', ['cancelled'])->countAllResults();
            if ($used >= $bt['quantity']) {
                throw new ApiException('No visa left in this batch', 422);
            }
            $vf += ['country' => $bt['country'], 'sponsor' => $bt['sponsor'], 'job_title' => $bt['job_title']];
            $vf['purchase_cost'] = round($bt['unit_cost'] * $bt['rate'], 2);
        }
        if (empty($vf['pipeline_un_id'])) {
            $p = $this->db->table('pipelines')->where(['business_un_id' => $biz, 'deleted_at' => null])
                ->orderBy("country = " . $this->db->escape($vf['country'] ?? ''), 'DESC', false)->orderBy('id')->get()->getRowArray();
            $vf['pipeline_un_id'] = $p['un_id'] ?? null;
        }
        $first = $vf['pipeline_un_id'] ? $this->db->table('pipeline_stages')->where(['pipeline_un_id' => $vf['pipeline_un_id'], 'deleted_at' => null])->orderBy('sort')->get()->getRowArray() : null;
        $vf += ['un_id' => uid(), 'business_un_id' => $biz, 'no' => Seq::next($biz, 'VISA', 'VS'), 'stage_un_id' => $first['un_id'] ?? null,
            'stage_changed_at' => now(), 'created_by' => Ctx::$user['un_id'], 'created_at' => now(), 'updated_at' => now()];
        $vf['purchase_cost'] = (float) ($vf['purchase_cost'] ?? 0);
        $vf['discount'] ??= 0;
        $this->db->table('visa_files')->insert($vf);
        if ($first) {
            $this->db->table('visa_stage_history')->insert(['visa_file_un_id' => $vf['un_id'], 'to_stage_un_id' => $first['un_id'], 'user_un_id' => Ctx::$user['un_id'], 'created_at' => now()]);
        }
        $this->postSale($vf);
        if ($vf['purchase_cost'] > 0) {
            $credit = ! empty($vf['batch_un_id']) ? ['@VISA_STOCK', 0, $vf['purchase_cost']] : ['@AP', 0, $vf['purchase_cost'], $in['supplier_un_id'] ?? null];
            Ledger::post($biz, ['type' => 'journal', 'entity_type' => 'visa_file', 'entity_un_id' => $vf['un_id'], 'note' => 'Visa purchase cost ' . $vf['no']], [['@VISA_COST', $vf['purchase_cost'], 0], $credit]);
        }
        foreach ($in['schedules'] ?? [] as $s) {
            $this->db->table('payment_schedules')->insert(['un_id' => uid(), 'visa_file_un_id' => $vf['un_id'], 'due_date' => $s['due_date'], 'amount' => $s['amount'], 'created_at' => now()]);
        }
        $this->db->transComplete();
        Audit::log('create', 'visa_files', $vf['un_id'], null, $vf);

        return $this->show($vf['un_id'])->setStatusCode(201);
    }

    public function update(string $un)
    {
        $this->need('visa.edit');
        $old = $this->row('visa_files', $un);
        $this->useBiz($old['business_un_id']);
        $in = $this->in();
        $d = array_intersect_key($in, array_flip(['country', 'job_title', 'sponsor', 'visa_no', 'work_permit_no', 'visa_expiry', 'sub_agent_un_id', 'commission_type', 'commission_value', 'selling_price', 'discount', 'note', 'status']));
        if (isset($in['meta'])) {
            $d['meta'] = json_encode($in['meta']);
        }
        $this->db->transStart();
        $this->db->table('visa_files')->where('un_id', $un)->update($d + ['updated_at' => now()]);
        if ((isset($d['selling_price']) && (float) $d['selling_price'] != (float) $old['selling_price']) || (isset($d['discount']) && (float) $d['discount'] != (float) $old['discount'])) {
            foreach ($this->db->table('transactions')->where(['entity_type' => 'visa_file', 'entity_un_id' => $un, 'type' => 'sale', 'status' => 'posted'])->get()->getResultArray() as $t) {
                Ledger::void($t['un_id']);
            }
            $this->postSale(array_merge($old, $d));
        }
        $this->db->transComplete();
        Audit::log('update', 'visa_files', $un, array_intersect_key($old, $d), $d);

        return $this->show($un);
    }

    public function stage(string $un)
    {
        $this->need('visa.edit');
        $vf = $this->row('visa_files', $un);
        $this->useBiz($vf['business_un_id']);
        $this->open($vf);
        $in = $this->in();
        $st = $this->db->table('pipeline_stages')->where(['un_id' => $in['stage_un_id'] ?? '', 'pipeline_un_id' => $vf['pipeline_un_id']])->get()->getRowArray() ?? throw new ApiException('Invalid stage', 422);
        $pl = $this->db->table('pipelines')->where('un_id', $vf['pipeline_un_id'])->get()->getRowArray();
        if ($pl['strict']) {
            $cur = $this->db->table('pipeline_stages')->where('un_id', $vf['stage_un_id'])->get()->getRowArray();
            $need = jdec($cur['required_docs'] ?? '[]') ?: [];
            $have = array_column($this->db->table('visa_documents')->where(['visa_file_un_id' => $un, 'status' => 'verified', 'deleted_at' => null])->get()->getResultArray(), 'doc_type');
            if ($st['sort'] > $cur['sort'] && ($miss = array_diff($need, $have))) {
                throw new ApiException('Required documents missing: ' . implode(', ', $miss), 422, ['missing' => array_values($miss)]);
            }
        }
        $this->db->transStart();
        $upd = ['stage_un_id' => $st['un_id'], 'stage_changed_at' => now(), 'updated_at' => now()];
        if ($st['is_terminal']) {
            $upd['status'] = 'completed';
        }
        $this->db->table('visa_files')->where('un_id', $un)->update($upd);
        $this->db->table('visa_stage_history')->insert(['visa_file_un_id' => $un, 'from_stage_un_id' => $vf['stage_un_id'], 'to_stage_un_id' => $st['un_id'], 'note' => $in['note'] ?? null, 'user_un_id' => Ctx::$user['un_id'], 'created_at' => now()]);
        if ($st['triggers_commission'] && ! $vf['commission_accrued'] && ($amt = $this->commission($vf)) > 0) {
            Ledger::post($vf['business_un_id'], ['type' => 'journal', 'party_un_id' => $vf['sub_agent_un_id'], 'entity_type' => 'visa_file', 'entity_un_id' => $un, 'note' => 'Commission ' . $vf['no']], [
                ['@COMMISSION', $amt, 0], ['@AP', 0, $amt, $vf['sub_agent_un_id']],
            ]);
            $this->db->table('visa_files')->where('un_id', $un)->update(['commission_accrued' => 1]);
        }
        $this->db->transComplete();
        Audit::log('stage', 'visa_files', $un, ['stage' => $vf['stage_un_id']], ['stage' => $st['un_id']]);

        return $this->show($un);
    }

    public function payment(string $un)
    {
        $this->need('visa.payment');
        $vf = $this->row('visa_files', $un);
        $this->useBiz($vf['business_un_id']);
        $this->open($vf);
        $in = $this->in();
        $this->validate_($in, ['amount' => 'required|decimal|greater_than[0]', 'account_un_id' => 'required']);
        $this->row('accounts', $in['account_un_id']);
        $amt = round($in['amount'] * ((float) ($in['rate'] ?? 1) ?: 1), 2);
        $this->db->transStart();
        $tx = Ledger::post($vf['business_un_id'], ['type' => 'receipt', 'date' => $in['date'] ?? today(), 'party_un_id' => $vf['candidate_un_id'], 'entity_type' => 'visa_file', 'entity_un_id' => $un,
            'currency' => $in['currency'] ?? 'BDT', 'rate' => $in['rate'] ?? 1, 'ref_no' => $in['ref_no'] ?? null, 'note' => $in['note'] ?? 'Visa payment ' . $vf['no'], 'idem_key' => $this->request->getHeaderLine('Idempotency-Key') ?: null],
            [[$in['account_un_id'], $amt, 0], ['@AR', 0, $amt, $vf['candidate_un_id']]]);
        $left = $amt;
        $q = $this->db->table('payment_schedules')->where(['visa_file_un_id' => $un, 'deleted_at' => null])->where('paid_amount < amount', null, false);
        // The chosen installment is filled first, then the rest oldest-due first.
        if (! empty($in['schedule_un_id'])) {
            $q->orderBy('un_id = ' . $this->db->escape($in['schedule_un_id']), 'DESC', false);
        }
        $q->orderBy('due_date');
        foreach ($q->get()->getResultArray() as $s) {
            if ($left <= 0) {
                break;
            }
            $put = min($left, $s['amount'] - $s['paid_amount']);
            $left -= $put;
            $paid = $s['paid_amount'] + $put;
            $this->db->table('payment_schedules')->where('id', $s['id'])->update(['paid_amount' => $paid, 'status' => $paid >= $s['amount'] ? 'paid' : 'partial', 'updated_at' => now()]);
        }
        $this->db->transComplete();
        $t = $this->db->table('transactions')->where('un_id', $tx)->get()->getRowArray();
        unset($t['id']);

        return $this->ok(['transaction' => $t], 'Payment received', 201);
    }

    public function cost(string $un)
    {
        $this->need('visa.cost');
        $vf = $this->row('visa_files', $un);
        $this->useBiz($vf['business_un_id']);
        $in = $this->in();
        $this->validate_($in, ['cost_type' => 'required', 'amount' => 'required|decimal|greater_than[0]']);
        if (empty($in['account_un_id']) && empty($in['party_un_id'])) {
            throw new ApiException('Choose paid-from account or supplier', 422);
        }
        $this->db->transStart();
        $credit = ! empty($in['account_un_id']) ? [$in['account_un_id'], 0, $in['amount']] : ['@AP', 0, $in['amount'], $in['party_un_id']];
        $tx = Ledger::post($vf['business_un_id'], ['type' => 'payment', 'date' => $in['date'] ?? today(), 'party_un_id' => $in['party_un_id'] ?? null, 'entity_type' => 'visa_file', 'entity_un_id' => $un, 'note' => $in['cost_type'] . ' · ' . $vf['no']],
            [['@VISA_COST', $in['amount'], 0], $credit]);
        $this->db->table('visa_costs')->insert(['un_id' => uid(), 'visa_file_un_id' => $un, 'cost_type' => $in['cost_type'], 'amount' => $in['amount'], 'date' => $in['date'] ?? today(),
            'party_un_id' => $in['party_un_id'] ?? null, 'account_un_id' => $in['account_un_id'] ?? null, 'transaction_un_id' => $tx, 'note' => $in['note'] ?? null, 'created_at' => now()]);
        $this->db->transComplete();

        return $this->show($un);
    }

    /** body: refund_amount, account_un_id (if refund), release_slot (bool) */
    public function cancel(string $un)
    {
        $this->need('visa.delete');
        $vf = $this->row('visa_files', $un);
        $this->useBiz($vf['business_un_id']);
        $this->open($vf);
        $in = $this->in();
        $billed = $vf['selling_price'] - $vf['discount'];
        $paid = (float) $this->list()->where('vf.un_id', $un)->get()->getRowArray()['paid'];
        $refund = (float) ($in['refund_amount'] ?? 0);
        if ($refund > $paid) {
            throw new ApiException('Refund exceeds amount paid', 422);
        }
        $h = ['type' => 'journal', 'party_un_id' => $vf['candidate_un_id'], 'entity_type' => 'visa_file', 'entity_un_id' => $un];
        $this->db->transStart();
        if ($billed > 0) {
            Ledger::post($vf['business_un_id'], $h + ['note' => 'Cancel sale ' . $vf['no']], [['@VISA_SALES', $vf['selling_price'], 0], ['@DISCOUNT', 0, $vf['discount']], ['@AR', 0, $billed, $vf['candidate_un_id']]]);
        }
        if ($refund > 0) {
            $this->validate_($in, ['account_un_id' => 'required']);
            Ledger::post($vf['business_un_id'], array_merge($h, ['type' => 'payment', 'note' => 'Refund ' . $vf['no']]), [['@AR', $refund, 0, $vf['candidate_un_id']], [$in['account_un_id'], 0, $refund]]);
        }
        if ($paid - $refund > 0) {
            Ledger::post($vf['business_un_id'], $h + ['note' => 'Non-refundable ' . $vf['no']], [['@AR', $paid - $refund, 0, $vf['candidate_un_id']], ['@VISA_SALES', 0, $paid - $refund]]);
        }
        if (! empty($in['release_slot']) && $vf['batch_un_id'] && $vf['purchase_cost'] > 0) {
            Ledger::post($vf['business_un_id'], $h + ['note' => 'Slot released ' . $vf['no']], [['@VISA_STOCK', $vf['purchase_cost'], 0], ['@VISA_COST', 0, $vf['purchase_cost']]]);
        } elseif (empty($in['release_slot']) && $vf['batch_un_id']) {
            // slot is consumed: keep it counted against the batch
            $this->db->table('visa_files')->where('un_id', $un)->update(['note' => trim(($vf['note'] ?? '') . "\nSlot consumed on cancel")]);
        }
        $this->db->table('visa_files')->where('un_id', $un)->update(['status' => empty($in['release_slot']) && $vf['batch_un_id'] ? 'void' : 'cancelled', 'updated_at' => now()]);
        $this->db->table('payment_schedules')->where(['visa_file_un_id' => $un])->where('paid_amount < amount', null, false)->update(['deleted_at' => now()]);
        $this->db->transComplete();
        Audit::log('cancel', 'visa_files', $un, null, $in);

        return $this->show($un);
    }

    /** PUT body: schedules: [{due_date, amount}] — replaces unpaid installments. */
    public function schedules(string $un)
    {
        $this->need('visa.edit');
        $this->row('visa_files', $un);
        $this->db->transStart();
        $this->db->table('payment_schedules')->where(['visa_file_un_id' => $un, 'paid_amount' => 0])->update(['deleted_at' => now()]);
        foreach ($this->in()['schedules'] ?? [] as $s) {
            $this->db->table('payment_schedules')->insert(['un_id' => uid(), 'visa_file_un_id' => $un, 'due_date' => $s['due_date'], 'amount' => $s['amount'], 'created_at' => now()]);
        }
        $this->db->transComplete();

        return $this->show($un);
    }

    /** POST body: doc_type, attachment_un_id?, issue_date?, expiry_date?, status? ; with doc_un_id updates existing. */
    public function document(string $un, ?string $doc = null)
    {
        $this->need('visa.edit');
        $this->row('visa_files', $un);
        $in = $this->in();
        $d = array_intersect_key($in, array_flip(['doc_type', 'attachment_un_id', 'issue_date', 'expiry_date', 'status']));
        if (($d['status'] ?? '') === 'verified') {
            $d['verified_by'] = Ctx::$user['un_id'];
        }
        if ($this->request->getMethod() === 'DELETE' && $doc) {
            $this->db->table('visa_documents')->where(['un_id' => $doc, 'visa_file_un_id' => $un])->update(['deleted_at' => now()]);
        } elseif ($doc) {
            $this->db->table('visa_documents')->where(['un_id' => $doc, 'visa_file_un_id' => $un])->update($d + ['updated_at' => now()]);
        } else {
            $this->validate_($in, ['doc_type' => 'required']);
            $this->db->table('visa_documents')->insert($d + ['un_id' => uid(), 'visa_file_un_id' => $un, 'created_at' => now()]);
        }

        return $this->show($un);
    }

    public function batches()
    {
        $this->need('visa.view');
        $b = $this->db->table('visa_batches b')->select('b.*, p.name supplier_name')
            ->select("(SELECT COUNT(*) FROM visa_files v WHERE v.batch_un_id = b.un_id AND v.deleted_at IS NULL AND v.status <> 'cancelled') assigned", false)
            ->join('parties p', 'p.un_id = b.supplier_un_id', 'left')->where('b.deleted_at', null)->orderBy('b.id', 'DESC');
        $this->scope($b, 'b.business_un_id');
        if ($c = $this->request->getGet('country')) {
            $b->where('b.country', $c);
        }

        return $this->page($b, function ($r) {
            $r['available'] = $r['quantity'] - $r['assigned'];
            if (! Ctx::can('visa.cost', $r['business_un_id'])) {
                unset($r['unit_cost']);
            }
            unset($r['id']);

            return $r;
        });
    }

    public function createBatch()
    {
        $this->need('visa.cost');
        $biz = $this->biz();
        $in = $this->in();
        $this->validate_($in, ['country' => 'required', 'quantity' => 'required|is_natural_no_zero', 'unit_cost' => 'required|decimal', 'supplier_un_id' => 'required']);
        $d = array_intersect_key($in, array_flip(['supplier_un_id', 'country', 'sponsor', 'job_title', 'quantity', 'unit_cost', 'currency', 'rate', 'purchase_date', 'expiry_date']));
        $d += ['un_id' => uid(), 'business_un_id' => $biz, 'no' => Seq::next($biz, 'BATCH', 'VB'), 'created_at' => now(), 'updated_at' => now()];
        $total = round($d['quantity'] * $d['unit_cost'] * ((float) ($d['rate'] ?? 1) ?: 1), 2);
        $this->db->transStart();
        $d['transaction_un_id'] = Ledger::post($biz, ['type' => 'purchase', 'date' => $d['purchase_date'] ?? today(), 'party_un_id' => $d['supplier_un_id'], 'entity_type' => 'visa_batch', 'entity_un_id' => $d['un_id'],
            'currency' => $d['currency'] ?? 'BDT', 'rate' => $d['rate'] ?? 1, 'note' => 'Visa batch ' . $d['no']], [['@VISA_STOCK', $total, 0], ['@AP', 0, $total, $d['supplier_un_id']]]);
        $this->db->table('visa_batches')->insert($d);
        $this->db->transComplete();
        Audit::log('create', 'visa_batches', $d['un_id'], null, $d);

        return $this->ok($d, 'Created', 201);
    }

    /** Kanban: stages of a pipeline with up to 20 cards each. */
    public function board()
    {
        $this->need('visa.view');
        $pl = $this->request->getGet('pipeline_un_id') ?? throw new ApiException('pipeline_un_id required', 422);
        $out = [];
        foreach ($this->db->table('pipeline_stages')->where(['pipeline_un_id' => $pl, 'deleted_at' => null])->orderBy('sort')->get()->getResultArray() as $s) {
            $b = $this->list()->where(['vf.stage_un_id' => $s['un_id'], 'vf.status' => 'active']);
            $n = (clone $b)->countAllResults(false);
            $cards = array_map(function ($r) use ($s) {
                $r = $this->shape($r);
                $r['days_in_stage'] = (int) floor((time() - strtotime($r['stage_changed_at'] ?? 'now')) / 86400);
                $r['late'] = $s['sla_days'] && $r['days_in_stage'] > $s['sla_days'];

                return $r;
            }, $b->orderBy('vf.stage_changed_at')->limit(20)->get()->getResultArray());
            unset($s['id']);
            $out[] = ['stage' => $s, 'count' => $n, 'cards' => $cards];
        }

        return $this->ok($out);
    }
}
