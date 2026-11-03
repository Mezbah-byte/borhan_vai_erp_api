<?php

namespace App\Controllers\Api;

use App\Libraries\ApiException;
use App\Libraries\Ctx;

class Misc extends BaseApi
{
    public function notifications()
    {
        $b = $this->db->table('notifications')->where(['user_un_id' => Ctx::$user['un_id'], 'deleted_at' => null]);
        if ($t = $this->request->getGet('type')) {
            $b->where('type', $t);
        }
        $unread = (clone $b)->where('read_at', null)->countAllResults();
        $res = $this->page($b->orderBy('id', 'DESC'), static function ($r) {
            unset($r['id']);

            return $r;
        });
        $body = json_decode($res->getBody(), true);
        $body['meta']['unread'] = $unread;

        return $res->setJSON($body);
    }

    public function readNotifications()
    {
        $b = $this->db->table('notifications')->where(['user_un_id' => Ctx::$user['un_id'], 'read_at' => null]);
        if ($ids = $this->in()['ids'] ?? null) {
            $b->whereIn('un_id', $ids);
        }
        $b->update(['read_at' => now()]);

        return $this->ok(null, 'Marked read');
    }

    /** Global search across visa files, parties, transactions, projects. */
    public function search()
    {
        $q = trim((string) $this->request->getGet('q'));
        if (mb_strlen($q) < 2) {
            return $this->ok(['visa_files' => [], 'parties' => [], 'transactions' => [], 'projects' => []]);
        }
        $ids = Ctx::$biz ? [Ctx::$biz] : (Ctx::bizIds() ?: ['-']);
        $out = [];
        $out['visa_files'] = Ctx::can('visa.view') ? $this->db->table('visa_files vf')->select('vf.un_id, vf.no, vf.country, vf.status, p.name, p.passport_no, s.name stage_name')
            ->join('parties p', 'p.un_id = vf.candidate_un_id')->join('pipeline_stages s', 's.un_id = vf.stage_un_id', 'left')->where('vf.deleted_at', null)->whereIn('vf.business_un_id', $ids)
            ->groupStart()->like('p.name', $q)->orLike('p.passport_no', $q)->orLike('p.phone', $q)->orLike('vf.no', $q)->orLike('vf.visa_no', $q)->groupEnd()->limit(10)->get()->getResultArray() : [];
        $out['parties'] = Ctx::can('party.view') ? $this->db->table('parties')->select('un_id, name, phone, roles, passport_no')->where('deleted_at', null)
            ->groupStart()->like('name', $q)->orLike('phone', $q)->orLike('passport_no', $q)->orLike('nid', $q)->groupEnd()->limit(10)->get()->getResultArray() : [];
        $out['transactions'] = Ctx::can('finance.view') ? $this->db->table('transactions t')->select('t.un_id, t.no, t.date, t.type, t.amount, t.note, t.ref_no, t.party_un_id, t.entity_type, t.entity_un_id')->where('t.status', 'posted')->whereIn('t.business_un_id', $ids)
            ->groupStart()->like('t.no', $q)->orLike('t.ref_no', $q)->orLike('t.note', $q)->groupEnd()->orderBy('t.id', 'DESC')->limit(10)->get()->getResultArray() : [];
        $out['projects'] = Ctx::can('project.view') ? $this->db->table('projects')->select('un_id, no, name, status')->where('deleted_at', null)->whereIn('business_un_id', $ids)
            ->groupStart()->like('name', $q)->orLike('no', $q)->groupEnd()->limit(10)->get()->getResultArray() : [];

        return $this->ok($out);
    }

    /** multipart: file, entity_type?, entity_un_id? */
    public function upload()
    {
        $f = $this->request->getFile('file');
        if (! $f || ! $f->isValid()) {
            throw new ApiException('No valid file', 422);
        }
        if ($f->getSize() > 10 * 1024 * 1024 || ! in_array(strtolower($f->getExtension()), ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
            throw new ApiException('Only JPG/PNG/WEBP/PDF up to 10 MB', 422);
        }
        // Tie the file to its record's business so only people with access to that business can open it.
        $biz = Ctx::$biz;
        $type = $this->request->getPost('entity_type');
        $tables = ['visa_file' => 'visa_files', 'project' => 'projects', 'expense' => 'expenses', 'employee' => 'employees'];
        if ($type && isset($tables[$type])) {
            $rec = $this->row($tables[$type], (string) $this->request->getPost('entity_un_id'));
            $biz = $rec['business_un_id'];
        }
        $dir = WRITEPATH . 'uploads/' . date('Y/m');
        $name = $f->getRandomName();
        $f->move($dir, $name);
        $d = ['un_id' => uid(), 'business_un_id' => $biz, 'entity_type' => $this->request->getPost('entity_type'), 'entity_un_id' => $this->request->getPost('entity_un_id'),
            'path' => date('Y/m') . '/' . $name, 'name' => $f->getClientName(), 'mime' => $f->getClientMimeType(), 'size' => $f->getSize(), 'created_by' => Ctx::$user['un_id'], 'created_at' => now()];
        $this->db->table('attachments')->insert($d);

        return $this->ok($d, 'Uploaded', 201);
    }

    public function file(string $un)
    {
        $a = $this->db->table('attachments')->where(['un_id' => $un, 'deleted_at' => null])->get()->getRowArray() ?? throw new ApiException('Not found', 404);
        if ($a['business_un_id'] && ! isset(Ctx::$access[$a['business_un_id']])) {
            throw new ApiException('No access', 403);
        }
        $p = WRITEPATH . 'uploads/' . $a['path'];
        if (! is_file($p)) {
            throw new ApiException('File missing', 404);
        }

        return $this->response->setHeader('Content-Type', $a['mime'])->setHeader('Content-Disposition', 'inline; filename="' . addslashes($a['name']) . '"')->setBody(file_get_contents($p));
    }

    public function activity()
    {
        $this->need('settings.view');
        $b = $this->db->table('activity_logs l')->select('l.*, u.name user_name')->join('users u', 'u.un_id = l.user_un_id', 'left');
        $g = $this->request->getGet();
        if (! empty($g['entity_type'])) {
            $b->where('l.entity_type', $g['entity_type']);
        }
        if (! empty($g['entity_un_id'])) {
            $b->where('l.entity_un_id', $g['entity_un_id']);
        }

        return $this->page($b->orderBy('l.id', 'DESC'), static function ($r) {
            $r['before'] = jdec($r['before']);
            $r['after'] = jdec($r['after']);
            unset($r['id']);

            return $r;
        });
    }
}
