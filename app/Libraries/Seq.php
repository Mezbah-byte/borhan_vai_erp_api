<?php

namespace App\Libraries;

class Seq
{
    public static function next(string $biz, string $key, string $prefix): string
    {
        $db = db_connect();
        $row = $db->query('SELECT id, next_no FROM number_sequences WHERE business_un_id = ? AND `key` = ? FOR UPDATE', [$biz, $key])->getRowArray();
        if (! $row) {
            $db->table('number_sequences')->insert(['business_un_id' => $biz, 'key' => $key, 'prefix' => $prefix, 'next_no' => 2]);
            $n = 1;
        } else {
            $n = (int) $row['next_no'];
            $db->table('number_sequences')->where('id', $row['id'])->update(['next_no' => $n + 1]);
        }

        return sprintf('%s-%s-%04d', $prefix, date('Y'), $n);
    }
}
