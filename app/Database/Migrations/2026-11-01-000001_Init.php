<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Init extends Migration
{
    public function up()
    {
        $sql = file_get_contents(APPPATH . 'Database/schema.sql');
        $sql = preg_replace('/^--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
            $this->db->query(rtrim($stmt, ';') . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }
    }

    public function down()
    {
        preg_match_all('/CREATE TABLE (\w+)/', file_get_contents(APPPATH . 'Database/schema.sql'), $m);
        foreach (array_reverse($m[1]) as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}
