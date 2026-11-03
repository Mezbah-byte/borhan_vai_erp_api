<?php

namespace App\Libraries;

/**
 * Double-entry posting engine. Every money movement goes through post().
 * Lines reference accounts by un_id or by system code prefixed with '@' (e.g. '@AR').
 */
class Ledger
{
    public const SYSTEM = [
        'AR'            => ['Accounts receivable', 'asset', 'receivable'],
        'AP'            => ['Accounts payable', 'liability', 'payable'],
        'VISA_STOCK'    => ['Visa stock', 'asset', 'stock'],
        'VISA_SALES'    => ['Visa sales', 'income', null],
        'VISA_COST'     => ['Visa cost', 'expense', null],
        'COMMISSION'    => ['Sub-agent commission', 'expense', null],
        'PROJECT_SALES' => ['Project sales', 'income', null],
        'PROJECT_COST'  => ['Project cost', 'expense', null],
        'EXPENSE'       => ['General expenses', 'expense', null],
        'SALARY'        => ['Salary expense', 'expense', null],
        'ADVANCE'       => ['Employee advance', 'asset', 'advance'],
        'DUE_FROM'      => ['Due from other business', 'asset', 'interco'],
        'DUE_TO'        => ['Due to other business', 'liability', 'interco'],
        'DISCOUNT'      => ['Discount given', 'expense', null],
        'EQUITY'        => ['Owner equity', 'equity', null],
        'CASH'          => ['Cash in hand', 'asset', 'cash'],
    ];

    public static function ensureSystemAccounts(string $biz): void
    {
        $db = db_connect();
        foreach (self::SYSTEM as $code => [$name, $type, $sub]) {
            if (! $db->table('accounts')->where(['business_un_id' => $biz, 'code' => $code])->countAllResults()) {
                $db->table('accounts')->insert(['un_id' => uid(), 'business_un_id' => $biz, 'code' => $code, 'name' => $name, 'type' => $type, 'subtype' => $sub, 'is_system' => 1, 'created_at' => now()]);
            }
        }
    }

    public static function acc(string $biz, string $ref): string
    {
        if ($ref[0] !== '@') {
            return $ref;
        }
        $row = db_connect()->table('accounts')->select('un_id')->where(['business_un_id' => $biz, 'code' => substr($ref, 1)])->get()->getRowArray();
        if (! $row) {
            throw new ApiException("System account {$ref} missing", 500);
        }

        return $row['un_id'];
    }

    /**
     * @param array $h     date,type,party_un_id,entity_type,entity_un_id,currency,rate,ref_no,note,idem_key
     * @param array $lines [[account, debit, credit, party?, category?, project?], ...] amounts in base currency
     */
    public static function post(string $biz, array $h, array $lines): string
    {
        $db = db_connect();
        if (! empty($h['idem_key']) && ($dup = $db->table('transactions')->select('un_id')->where('idem_key', $h['idem_key'])->get()->getRowArray())) {
            return $dup['un_id'];
        }
        $dr = $cr = 0;
        foreach ($lines as $l) {
            $dr += round((float) $l[1], 2);
            $cr += round((float) $l[2], 2);
        }
        if (abs($dr - $cr) > 0.009 || $dr <= 0) {
            throw new ApiException('Unbalanced transaction', 422);
        }
        $un = uid();
        $date = $h['date'] ?? today();
        $db->table('transactions')->insert([
            'un_id' => $un, 'business_un_id' => $biz, 'no' => Seq::next($biz, 'TXN', 'TXN'), 'date' => $date,
            'type' => $h['type'], 'party_un_id' => $h['party_un_id'] ?? null, 'entity_type' => $h['entity_type'] ?? null,
            'entity_un_id' => $h['entity_un_id'] ?? null, 'currency' => $h['currency'] ?? 'BDT', 'rate' => $h['rate'] ?? 1,
            'amount' => $dr, 'ref_no' => $h['ref_no'] ?? null, 'note' => $h['note'] ?? null, 'status' => 'posted',
            'created_by' => Ctx::$user['un_id'] ?? null, 'idem_key' => $h['idem_key'] ?? null, 'created_at' => now(),
        ]);
        $rows = [];
        foreach ($lines as $l) {
            if ((float) $l[1] == 0 && (float) $l[2] == 0) {
                continue;
            }
            $rows[] = [
                'transaction_un_id' => $un, 'business_un_id' => $biz, 'account_un_id' => self::acc($biz, $l[0]),
                'debit' => round((float) $l[1], 2), 'credit' => round((float) $l[2], 2),
                'party_un_id' => $l[3] ?? null, 'category_un_id' => $l[4] ?? null, 'project_un_id' => $l[5] ?? null,
                'date' => $date, 'status' => 'posted',
            ];
        }
        $db->table('transaction_lines')->insertBatch($rows);

        return $un;
    }

    public static function void(?string $tx): void
    {
        if (! $tx) {
            return;
        }
        $db = db_connect();
        $db->table('transactions')->where('un_id', $tx)->update(['status' => 'void', 'updated_at' => now()]);
        $db->table('transaction_lines')->where('transaction_un_id', $tx)->update(['status' => 'void']);
    }

    /** Signed balance: assets/expenses debit-positive, others credit-positive. */
    public static function balance(string $account, ?string $to = null): float
    {
        $db = db_connect();
        $a = $db->table('accounts')->select('type')->where('un_id', $account)->get()->getRowArray();
        $b = $db->table('transaction_lines')->selectSum('debit')->selectSum('credit')->where(['account_un_id' => $account, 'status' => 'posted']);
        if ($to) {
            $b->where('date <=', $to);
        }
        $r = $b->get()->getRowArray();
        $v = (float) $r['debit'] - (float) $r['credit'];

        return in_array($a['type'] ?? 'asset', ['asset', 'expense'], true) ? $v : -$v;
    }

    /** Party net position: + means party owes us (receivable), - means we owe party. */
    public static function partyBalance(string $party, ?array $bizIds = null): float
    {
        $b = db_connect()->table('transaction_lines tl')->select('SUM(tl.debit - tl.credit) bal', false)
            ->join('accounts a', 'a.un_id = tl.account_un_id')
            ->whereIn('a.code', ['AR', 'AP'])->where(['tl.party_un_id' => $party, 'tl.status' => 'posted']);
        if ($bizIds) {
            $b->whereIn('tl.business_un_id', $bizIds);
        }

        return (float) ($b->get()->getRowArray()['bal'] ?? 0);
    }
}
