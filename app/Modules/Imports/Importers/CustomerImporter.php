<?php

declare(strict_types=1);

namespace App\Modules\Imports\Importers;

use App\Core\Database;
use App\Core\NumberSequence;
use App\Modules\Imports\Importer;

/** Customer master. Blank Customer Code = next automatic code (CUS-#####). */
final class CustomerImporter extends Importer
{
    public function key(): string { return 'customers'; }
    public function label(): string { return 'Customers'; }
    public function permission(): string { return 'customers.import'; }

    public function fields(): array
    {
        return [
            'customer_code'    => ['label' => 'Customer Code', 'format' => 'Blank = automatic', 'aliases' => ['code', 'party code', 'customer id'], 'example' => ['']],
            'name'             => ['label' => 'Customer Name', 'required' => true, 'format' => 'Text, max 150', 'aliases' => ['name', 'party name', 'customer'], 'example' => ['Sri Murugan Traders']],
            'company_name'     => ['label' => 'Company', 'format' => 'Text, max 150', 'aliases' => ['company name', 'firm'], 'example' => ['Sri Murugan Traders Pvt Ltd']],
            'mobile'           => ['label' => 'Mobile', 'format' => '10-digit mobile (mobile or email needed)', 'aliases' => ['mobile no', 'phone', 'contact no'], 'example' => ['9876543210']],
            'alternate_mobile' => ['label' => 'Alternate Mobile', 'format' => '10-digit mobile', 'aliases' => ['alt mobile', 'phone 2'], 'example' => ['']],
            'email'            => ['label' => 'Email', 'format' => 'Email address', 'aliases' => ['email id', 'mail'], 'example' => ['accounts@murugan.example']],
            'address'          => ['label' => 'Address', 'format' => 'Text, max 255', 'aliases' => ['address line'], 'example' => ['14, Bazaar Street']],
            'city'             => ['label' => 'City', 'format' => 'Text', 'aliases' => ['town'], 'example' => ['Salem']],
            'state'            => ['label' => 'State', 'format' => 'Text', 'aliases' => [], 'example' => ['Tamil Nadu']],
            'pincode'          => ['label' => 'Pincode', 'format' => '6 digits', 'aliases' => ['pin', 'pin code', 'zip'], 'example' => ['636001']],
            'gstin'            => ['label' => 'GSTIN', 'format' => '15 characters', 'aliases' => ['gst no', 'gst number'], 'example' => ['']],
            'branch_code'      => ['label' => 'Branch Code', 'required' => true, 'format' => 'e.g. CHN', 'aliases' => ['branch'], 'example' => ['CHN']],
            'employee'         => ['label' => 'Sales Employee', 'format' => 'Employee code or short name', 'aliases' => ['employee code', 'rep', 'sales person'], 'example' => ['JANA']],
            'credit_days'      => ['label' => 'Credit Days', 'format' => '0-365, default 30', 'aliases' => ['credit period'], 'example' => ['30']],
            'credit_limit'     => ['label' => 'Credit Limit', 'format' => 'Amount, blank = no limit', 'aliases' => [], 'example' => ['']],
        ];
    }

    public function notes(): array
    {
        return ['A customer whose code already exists, or whose mobile matches an existing customer, is skipped as a duplicate.'];
    }

    public function recordKey(array $data): ?string
    {
        return $data['customer_code'] !== null ? 'C|' . strtoupper($data['customer_code']) : ($data['mobile'] !== null ? 'M|' . $data['mobile'] : null);
    }

    public function validateRow(array $in): array
    {
        $this->begin();
        $code = $this->text($in, 'customer_code', 30);
        if ($code !== null && !preg_match('/^[A-Za-z0-9][A-Za-z0-9\-_\/]{0,29}$/', $code)) {
            $this->errors[] = 'Customer Code may use letters, digits, - _ /.';
        }
        $name = $this->text($in, 'name', 150, true);
        $company = $this->text($in, 'company_name', 150);
        $mobile = $this->mobile($in, 'mobile');
        $alt = $this->mobile($in, 'alternate_mobile');
        $email = $this->email($in, 'email');
        if (($in['mobile'] ?? '') === '' && ($in['email'] ?? '') === '') {
            $this->errors[] = 'Give a mobile number or an email address.';
        }
        $address = $this->text($in, 'address', 255);
        $city = $this->text($in, 'city', 80);
        $state = $this->text($in, 'state', 80);
        $pincode = $this->text($in, 'pincode', 10);
        if ($pincode !== null && !preg_match('/^\d{6}$/', $pincode)) {
            $this->errors[] = 'Pincode must be 6 digits.';
        }
        $gstin = strtoupper((string) $this->text($in, 'gstin', 15)) ?: null;
        if ($gstin !== null && !preg_match('/^[0-9]{2}[A-Z0-9]{10}[0-9][A-Z][0-9A-Z]$/', $gstin)) {
            $this->errors[] = 'GSTIN must be 15 characters in the standard format.';
        }
        $branch = $this->branch($in, 'branch_code', true);
        $employee = $this->employee($in, 'employee', null);
        $days = (string) ($in['credit_days'] ?? '');
        if ($days !== '' && (!ctype_digit($days) || (int) $days > 365)) {
            $this->errors[] = 'Credit Days must be a whole number from 0 to 365.';
        }
        $limit = $this->money($in, 'credit_limit', false, true);
        if ($this->errors) {
            return $this->done([]);
        }
        return $this->done([
            'customer_code' => $code !== null ? strtoupper($code) : null, 'name' => $name, 'company_name' => $company, 'mobile' => $mobile,
            'alternate_mobile' => $alt, 'email' => $email, 'address' => $address, 'city' => $city, 'state' => $state, 'pincode' => $pincode,
            'gstin' => $gstin, 'branch_id' => (int) $branch['id'], 'employee_id' => $employee, 'credit_days' => $days === '' ? 30 : (int) $days,
            'credit_limit' => $limit,
        ]);
    }

    public function existing(array $data): ?string
    {
        if ($data['customer_code'] !== null && Database::value('SELECT 1 FROM customers WHERE customer_code = ?', [$data['customer_code']])) {
            return "Customer code {$data['customer_code']} already exists.";
        }
        if ($data['mobile'] !== null) {
            $c = Database::fetch('SELECT customer_code, name FROM customers WHERE mobile = ? AND deleted_at IS NULL LIMIT 1', [$data['mobile']]);
            if ($c !== null) {
                return "Mobile {$data['mobile']} already belongs to {$c['name']} ({$c['customer_code']}).";
            }
        }
        return null;
    }

    public function store(array $lines, int $batchId): array
    {
        $d = $lines[0];
        $code = $d['customer_code'] ?? NumberSequence::next('customer');
        Database::query(
            'INSERT INTO customers (customer_code, name, company_name, mobile, alternate_mobile, email, address, city, state, pincode, gstin,
                                    branch_id, employee_id, credit_days, credit_limit, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$code, $d['name'], $d['company_name'], $d['mobile'], $d['alternate_mobile'], $d['email'], $d['address'], $d['city'], $d['state'],
             $d['pincode'], $d['gstin'], $d['branch_id'], $d['employee_id'], $d['credit_days'], self::dec($d['credit_limit']), $this->uid(), $this->uid()]
        );
        return ['customers', (int) Database::connection()->lastInsertId()];
    }
}
