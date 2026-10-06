<?php

declare(strict_types=1);

namespace App\Modules\Imports\Importers;

use App\Core\Database;
use App\Core\NumberSequence;
use App\Modules\Imports\Importer;

/** Leads (enquiries). Lead numbers are assigned automatically (LD-#####). */
final class LeadImporter extends Importer
{
    private const PRIORITIES = ['low' => 'low', 'medium' => 'medium', 'normal' => 'medium', 'high' => 'high', 'urgent' => 'urgent', 'hot' => 'urgent'];

    public function key(): string { return 'leads'; }
    public function label(): string { return 'Leads'; }
    public function permission(): string { return 'leads.import'; }

    public function fields(): array
    {
        return [
            'name'           => ['label' => 'Lead Name', 'required' => true, 'format' => 'Contact person, max 150', 'aliases' => ['name', 'contact name', 'contact person'], 'example' => ['Arul Selvan']],
            'company_name'   => ['label' => 'Company', 'format' => 'Text, max 150', 'aliases' => ['company name', 'firm'], 'example' => ['Arul Packaging']],
            'mobile'         => ['label' => 'Mobile', 'format' => '10-digit mobile (mobile or email needed)', 'aliases' => ['mobile no', 'phone'], 'example' => ['9123456780']],
            'email'          => ['label' => 'Email', 'format' => 'Email address', 'aliases' => ['email id'], 'example' => ['']],
            'source'         => ['label' => 'Source', 'format' => 'An existing lead source name', 'aliases' => ['lead source'], 'example' => ['Website']],
            'product_code'   => ['label' => 'Product Code', 'format' => 'Product of interest', 'aliases' => ['product'], 'example' => ['P001']],
            'branch_code'    => ['label' => 'Branch Code', 'required' => true, 'format' => 'e.g. CHN', 'aliases' => ['branch'], 'example' => ['CHN']],
            'employee'       => ['label' => 'Sales Employee', 'format' => 'Employee code or short name', 'aliases' => ['employee code', 'owner', 'assigned to'], 'example' => ['JANA']],
            'priority'       => ['label' => 'Priority', 'format' => 'Low / Medium / High / Urgent (default Medium)', 'aliases' => [], 'example' => ['High']],
            'expected_value' => ['label' => 'Expected Value', 'format' => 'Amount', 'aliases' => ['value', 'deal value'], 'example' => ['1,50,000']],
            'next_followup'  => ['label' => 'Next Follow-up', 'format' => 'DD-MM-YYYY', 'aliases' => ['follow up date', 'followup'], 'example' => ['']],
            'remarks'        => ['label' => 'Remarks', 'format' => 'Text', 'aliases' => ['notes', 'requirement'], 'example' => ['Needs 5-ply boxes monthly']],
        ];
    }

    public function notes(): array
    {
        return ['A lead whose mobile matches an open lead (not won or lost) is skipped as a duplicate.', 'Imported leads start with status New.'];
    }

    public function recordKey(array $data): ?string
    {
        return $data['mobile'] !== null ? 'M|' . $data['mobile'] : ($data['email'] !== null ? 'E|' . $data['email'] : null);
    }

    public function validateRow(array $in): array
    {
        $this->begin();
        $name = $this->text($in, 'name', 150, true);
        $company = $this->text($in, 'company_name', 150);
        $mobile = $this->mobile($in, 'mobile');
        $email = $this->email($in, 'email');
        if (($in['mobile'] ?? '') === '' && ($in['email'] ?? '') === '') {
            $this->errors[] = 'Give a mobile number or an email address.';
        }
        $sourceId = null;
        $source = (string) ($in['source'] ?? '');
        if ($source !== '') {
            $sourceId = $this->cached('src:' . strtolower($source), static fn () => Database::value("SELECT id FROM lead_sources WHERE name = ? AND status = 'active'", [$source]));
            if (!$sourceId) {
                $this->errors[] = "Lead source \"{$source}\" was not found.";
            }
        }
        $product = $this->product($in, 'product_code', false);
        $branch = $this->branch($in, 'branch_code', true);
        $employee = $this->employee($in, 'employee', null);
        if ($employee === null && ($in['employee'] ?? '') === '' && !$this->scope->isUnrestricted() && ($this->user['employee_id'] ?? null)) {
            $employee = (int) $this->user['employee_id'];
        }
        $priority = $this->choice($in, 'priority', self::PRIORITIES, 'medium');
        $value = $this->money($in, 'expected_value', false, true);
        $followup = $this->date($in, 'next_followup', false, false);
        $remarks = $this->text($in, 'remarks', 2000);
        if ($this->errors) {
            return $this->done([]);
        }
        return $this->done([
            'name' => $name, 'company_name' => $company, 'mobile' => $mobile, 'email' => $email, 'source_id' => $sourceId ? (int) $sourceId : null,
            'product_id' => $product !== null ? (int) $product['id'] : null, 'branch_id' => (int) $branch['id'], 'employee_id' => $employee,
            'priority' => $priority, 'expected_value' => $value, 'next_followup_at' => $followup !== null ? $followup . ' 10:00:00' : null, 'remarks' => $remarks,
        ]);
    }

    public function existing(array $data): ?string
    {
        if ($data['mobile'] === null) {
            return null;
        }
        $l = Database::fetch("SELECT lead_number, name FROM leads WHERE mobile = ? AND status NOT IN ('won', 'lost') AND deleted_at IS NULL LIMIT 1", [$data['mobile']]);
        return $l !== null ? "Mobile {$data['mobile']} already has an open lead ({$l['lead_number']}, {$l['name']})." : null;
    }

    public function store(array $lines, int $batchId): array
    {
        $d = $lines[0];
        Database::query(
            "INSERT INTO leads (lead_number, name, company_name, mobile, email, source_id, product_id, branch_id, employee_id, status, priority,
                                expected_value, next_followup_at, remarks, import_batch_id, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', ?, ?, ?, ?, ?, ?, ?)",
            [NumberSequence::next('lead'), $d['name'], $d['company_name'], $d['mobile'], $d['email'], $d['source_id'], $d['product_id'], $d['branch_id'],
             $d['employee_id'], $d['priority'], self::dec($d['expected_value']), $d['next_followup_at'], $d['remarks'], $batchId, $this->uid(), $this->uid()]
        );
        $id = (int) Database::connection()->lastInsertId();
        if ($d['next_followup_at'] !== null) {
            // The lead's next follow-up is always derived from pending follow-ups (as on the lead screen).
            Database::query(
                "INSERT INTO followups (lead_id, employee_id, followup_at, followup_type, purpose, notes, status, created_by, updated_by)
                 VALUES (?, ?, ?, 'call', 'sales', 'Follow-up from imported lead list', 'pending', ?, ?)",
                [$id, $d['employee_id'], $d['next_followup_at'], $this->uid(), $this->uid()]
            );
        }
        return ['leads', $id];
    }
}
