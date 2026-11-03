<?php

namespace App\Commands;

use App\Controllers\Api\Finance;
use App\Libraries\Notify;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** Cron (daily 06:00): php spark erp:daily */
class Daily extends BaseCommand
{
    protected $group = 'ERP';
    protected $name = 'erp:daily';
    protected $description = 'Post recurring expenses and send reminders (passport/visa expiry, overdue installments, stage SLA).';

    public function run(array $params)
    {
        $db = db_connect();
        $today = today();

        // 1. Recurring expenses: clone due templates as pending/approved entries.
        $fin = new Finance();
        $n = 0;
        foreach ($db->table('expenses')->where(['is_recurring' => 1, 'deleted_at' => null])->where('recur_next_date <=', $today)->get()->getResultArray() as $e) {
            $db->transStart();
            $child = array_diff_key($e, array_flip(['id', 'transaction_un_id', 'approved_by']));
            $child = array_merge($child, ['un_id' => uid(), 'no' => \App\Libraries\Seq::next($e['business_un_id'], 'EXP', 'EXP'), 'date' => $e['recur_next_date'], 'is_recurring' => 0,
                'recur_interval' => null, 'recur_next_date' => null, 'recur_parent_un_id' => $e['un_id'], 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
            $child['transaction_un_id'] = $fin->postExpense($child);
            $db->table('expenses')->insert($child);
            $next = $fin->nextDate($e['recur_next_date'], $e['recur_interval'] ?: 'monthly');
            $done = $e['recur_end_date'] && $next > $e['recur_end_date'];
            $db->table('expenses')->where('id', $e['id'])->update(['recur_next_date' => $done ? null : $next, 'is_recurring' => $done ? 0 : 1]);
            $db->transComplete();
            Notify::toPerm($e['business_un_id'], 'expense.view', 'recurring', 'Recurring expense posted', $e['title'] . ' · ৳ ' . number_format($e['amount'] * $e['rate']), 'expense', $child['un_id']);
            $n++;
        }
        CLI::write("Recurring posted: {$n}");

        // 2. Passport expiring within 180 days for active files.
        $soon = date('Y-m-d', strtotime('+180 days'));
        foreach ($db->query('SELECT vf.un_id, vf.business_un_id, vf.no, p.name, p.passport_no, p.passport_expiry FROM visa_files vf JOIN parties p ON p.un_id = vf.candidate_un_id
            WHERE vf.status = "active" AND vf.deleted_at IS NULL AND p.passport_expiry IS NOT NULL AND p.passport_expiry <= ?', [$soon])->getResultArray() as $r) {
            $days = (int) ((strtotime($r['passport_expiry']) - time()) / 86400);
            Notify::toPerm($r['business_un_id'], 'visa.view', 'passport', 'Passport expiring soon', "{$r['name']} ({$r['passport_no']}) passport expires in {$days} days.", 'visa_file', $r['un_id'], 'pp:' . $r['un_id'] . ':' . date('Y-m'));
        }

        // 3. Overdue installments.
        foreach ($db->query('SELECT ps.un_id, ps.amount - ps.paid_amount due, ps.due_date, vf.un_id vf, vf.business_un_id, p.name FROM payment_schedules ps JOIN visa_files vf ON vf.un_id = ps.visa_file_un_id
            JOIN parties p ON p.un_id = vf.candidate_un_id WHERE ps.deleted_at IS NULL AND vf.status = "active" AND ps.due_date < ? AND ps.paid_amount < ps.amount', [$today])->getResultArray() as $r) {
            $late = (int) ((time() - strtotime($r['due_date'])) / 86400);
            Notify::toPerm($r['business_un_id'], 'visa.payment', 'installment', 'Installment overdue', "{$r['name']} · ৳ " . number_format($r['due']) . " · {$late} days late.", 'visa_file', $r['vf'], 'ps:' . $r['un_id'] . ':' . $today);
        }

        // 4. Stage SLA crossed.
        foreach ($db->query('SELECT vf.un_id, vf.business_un_id, p.name, s.name stage, DATEDIFF(NOW(), vf.stage_changed_at) d FROM visa_files vf JOIN parties p ON p.un_id = vf.candidate_un_id
            JOIN pipeline_stages s ON s.un_id = vf.stage_un_id WHERE vf.status = "active" AND vf.deleted_at IS NULL AND s.sla_days IS NOT NULL AND DATEDIFF(NOW(), vf.stage_changed_at) > s.sla_days')->getResultArray() as $r) {
            Notify::toPerm($r['business_un_id'], 'visa.view', 'sla', 'Stage SLA crossed', "{$r['name']} stuck in \"{$r['stage']}\" for {$r['d']} days.", 'visa_file', $r['un_id'], 'sla:' . $r['un_id'] . ':' . $r['stage']);
        }
        CLI::write('Reminders done.', 'green');
    }
}
