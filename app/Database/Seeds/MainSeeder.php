<?php

namespace App\Database\Seeds;

use App\Libraries\Ledger;
use CodeIgniter\Database\Seeder;

/** Base config + demo businesses. Admin login: 01700000000 / admin1234 (change after first login). */
class MainSeeder extends Seeder
{
    private function ins(string $t, array $rows): array
    {
        $ids = [];
        foreach ($rows as $k => $r) {
            $r['un_id'] ??= uid();
            $r['created_at'] = $r['updated_at'] = now();
            $this->db->table($t)->insert($r);
            $ids[$k] = $r['un_id'];
        }

        return $ids;
    }

    public function run()
    {
        if ($this->db->table('users')->countAllResults()) {
            echo "Already seeded\n";

            return;
        }
        $this->ins('currencies', [['code' => 'BDT', 'name' => 'Bangladeshi Taka', 'symbol' => '৳', 'rate' => 1], ['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'rate' => 32.5],
            ['code' => 'AED', 'name' => 'UAE Dirham', 'symbol' => 'AED', 'rate' => 33.2], ['code' => 'MYR', 'name' => 'Malaysian Ringgit', 'symbol' => 'RM', 'rate' => 27.4], ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'rate' => 122]]);
        $this->ins('units', array_map(fn ($u) => ['name' => $u[0], 'symbol' => $u[1]], [['Kilogram', 'kg'], ['Maund', 'mon'], ['Piece', 'pcs'], ['Bag', 'bag'], ['Litre', 'L'], ['Dozen', 'dz'], ['Bigha', 'bigha'], ['Decimal', 'dec'], ['Bird', 'bird'], ['Day', 'day']]));

        $all = ['visa', 'project', 'finance', 'expense', 'hr', 'party', 'report', 'dashboard', 'settings'];
        $bt = $this->ins('business_types', [
            'visa' => ['name' => 'Visa & manpower', 'slug' => 'visa', 'icon' => 'plane', 'modules' => json_encode(['visa', 'finance', 'expense', 'hr', 'party', 'report'])],
            'agro' => ['name' => 'Agro / farm', 'slug' => 'agro', 'icon' => 'sprout', 'modules' => json_encode(['project', 'finance', 'expense', 'hr', 'party', 'report'])],
            'gen'  => ['name' => 'General / trading', 'slug' => 'general', 'icon' => 'box', 'modules' => json_encode(['project', 'finance', 'expense', 'hr', 'party', 'report'])],
        ]);
        $roles = $this->ins('roles', [
            'owner'   => ['name' => 'Owner', 'slug' => 'owner', 'is_system' => 1, 'permissions' => json_encode(['*'])],
            'manager' => ['name' => 'Manager', 'slug' => 'manager', 'is_system' => 1, 'permissions' => json_encode(array_map(fn ($m) => $m . '.*', array_diff($all, ['settings'])))],
            'visa'    => ['name' => 'Visa officer', 'slug' => 'visa-officer', 'permissions' => json_encode(['dashboard.view', 'visa.view', 'visa.create', 'visa.edit', 'visa.payment', 'party.view', 'party.create', 'party.edit', 'expense.view', 'expense.create', 'finance.view'])],
            'farm'    => ['name' => 'Farm supervisor', 'slug' => 'farm-supervisor', 'permissions' => json_encode(['dashboard.view', 'project.view', 'project.create', 'project.edit', 'party.view', 'expense.view', 'expense.create', 'hr.view', 'hr.edit'])],
            'acct'    => ['name' => 'Accountant', 'slug' => 'accountant', 'permissions' => json_encode(['dashboard.view', 'finance.*', 'expense.*', 'party.*', 'report.view', 'visa.view', 'visa.payment', 'visa.cost', 'project.view', 'hr.view'])],
        ]);
        $this->ins('users', [['name' => 'Borhan Uddin', 'phone' => '01700000000', 'email' => 'admin@borhan.local', 'password_hash' => password_hash('admin1234', PASSWORD_DEFAULT), 'is_super' => 1]]);

        $biz = $this->ins('businesses', [
            'visa'  => ['name' => 'Borhan Overseas', 'business_type_un_id' => $bt['visa'], 'license_no' => 'RL-0000'],
            'poult' => ['name' => 'Borhan Agro — Poultry', 'business_type_un_id' => $bt['agro']],
            'crop'  => ['name' => 'Borhan Agro — Crop & Fish', 'business_type_un_id' => $bt['agro']],
        ]);
        foreach ($biz as $b) {
            Ledger::ensureSystemAccounts($b);
        }
        $this->ins('accounts', [['business_un_id' => $biz['visa'], 'name' => 'DBBL · Current', 'type' => 'asset', 'subtype' => 'bank', 'bank_name' => 'Dutch-Bangla Bank', 'account_no' => '1011100004821'],
            ['business_un_id' => $biz['visa'], 'name' => 'bKash Merchant', 'type' => 'asset', 'subtype' => 'mobile', 'account_no' => '01700000990']]);
        $this->db->table('settings')->insert(['business_un_id' => null, 'key' => 'expense_approval_limit', 'value' => '25000']);

        $cats = [];
        foreach (['Rent', 'Utilities', 'Salary', 'Transport', 'Office', 'Medical fee', 'Ticket', 'Entertainment', 'Repair', 'Misc'] as $c) {
            $cats[] = ['kind' => 'expense', 'name' => $c];
        }
        foreach (['Seed', 'Feed', 'Fertilizer', 'Medicine', 'Chick / fry', 'Equipment'] as $c) {
            $cats[] = ['kind' => 'item', 'name' => $c];
        }
        $cat = $this->ins('categories', $cats);
        $this->ins('items', [['name' => 'Day-old chick (Cobb 500)', 'unit' => 'pcs', 'kind' => 'input', 'category_un_id' => $cat[14]], ['name' => 'Broiler feed — Starter', 'unit' => 'bag', 'kind' => 'input', 'category_un_id' => $cat[11]],
            ['name' => 'Broiler feed — Grower', 'unit' => 'bag', 'kind' => 'input', 'category_un_id' => $cat[11]], ['name' => 'Vaccine (ND + IB)', 'unit' => 'pcs', 'kind' => 'input', 'category_un_id' => $cat[13]],
            ['name' => 'Live broiler', 'unit' => 'kg', 'kind' => 'output'], ['name' => 'Rice (Boro)', 'unit' => 'mon', 'kind' => 'output'], ['name' => 'Pangas fish', 'unit' => 'kg', 'kind' => 'output'],
            ['name' => 'Urea', 'unit' => 'kg', 'kind' => 'input', 'category_un_id' => $cat[12]], ['name' => 'Fish feed', 'unit' => 'bag', 'kind' => 'input', 'category_un_id' => $cat[11]]]);

        $this->ins('project_types', [
            ['name' => 'Poultry batch', 'slug' => 'poultry', 'icon' => 'egg', 'unit_label' => 'kg', 'stages' => json_encode(['Chick in', 'Brooding', 'Growing', 'Selling', 'Closed'])],
            ['name' => 'Crop season', 'slug' => 'crop', 'icon' => 'sprout', 'unit_label' => 'mon', 'stages' => json_encode(['Land prep', 'Sowing', 'Irrigation', 'Harvest', 'Closed'])],
            ['name' => 'Fish pond', 'slug' => 'fish', 'icon' => 'fish', 'unit_label' => 'kg', 'stages' => json_encode(['Pond prep', 'Stocking', 'Feeding', 'Harvest', 'Closed'])],
            ['name' => 'Dairy', 'slug' => 'dairy', 'icon' => 'box', 'unit_label' => 'L', 'stages' => json_encode(['Running', 'Closed'])],
            ['name' => 'Trading lot', 'slug' => 'trading', 'icon' => 'box', 'unit_label' => 'pcs', 'stages' => json_encode(['Buying', 'Selling', 'Closed'])],
        ]);
        $cf = fn ($e, $s, $k, $l, $t = 'text', $o = null, $req = 0) => ['entity' => $e, 'scope' => $s, 'key' => $k, 'label' => $l, 'type' => $t, 'options' => $o ? json_encode($o) : null, 'required' => $req];
        $this->ins('custom_fields', [$cf('project', 'poultry', 'bird_count', 'Bird count', 'number', null, 1), $cf('project', 'poultry', 'breed', 'Breed', 'select', ['Cobb 500', 'Ross 308', 'Sonali', 'Layer']),
            $cf('project', 'poultry', 'shed', 'Shed'), $cf('project', 'crop', 'land_size', 'Land size (bigha)', 'number', null, 1), $cf('project', 'crop', 'variety', 'Variety'),
            $cf('project', 'fish', 'pond_size', 'Pond size (acre)', 'number'), $cf('project', 'fish', 'species', 'Species', 'select', ['Pangas', 'Tilapia', 'Rui', 'Koi']),
            $cf('visa_file', 'Saudi Arabia', 'iqama_profession', 'Iqama profession', 'text', null, 1), $cf('visa_file', 'Saudi Arabia', 'musaned_no', 'Musaned no.'), $cf('party', null, 'religion', 'Religion', 'select', ['Muslim', 'Hindu', 'Buddhist', 'Christian', 'Other'])]);

        $stageSet = [['Passport collected', 1, ['passport', 'photo']], ['Visa purchased', 3, []], ['Medical', 7, ['medical']], ['Visa stamping', 15, ['visa_copy']], ['Police clearance', 7, ['pcc']],
            ['BMET / Fingerprint', 5, ['bmet_card']], ['Ticket & flight', 7, ['ticket']], ['Completed', null, []]];
        $colors = ['#2563EB', '#7C3AED', '#EA580C', '#16A34A', '#2563EB', '#7C3AED', '#0F766E', '#64748B'];
        foreach (['Saudi Arabia', 'Malaysia', 'UAE', 'Qatar'] as $country) {
            [$pl] = $this->ins('pipelines', [['business_un_id' => $biz['visa'], 'name' => $country . ' standard', 'country' => $country]]);
            $rows = [];
            foreach ($stageSet as $i => [$n, $sla, $docs]) {
                $rows[] = ['pipeline_un_id' => $pl, 'name' => $n, 'sort' => $i + 1, 'color' => $colors[$i], 'sla_days' => $sla, 'required_docs' => json_encode($docs),
                    'is_terminal' => (int) ($n === 'Completed'), 'triggers_commission' => (int) ($n === 'Visa stamping')];
            }
            $this->ins('pipeline_stages', $rows);
        }
        echo "Seeded. Login 01700000000 / admin1234\n";
    }
}
