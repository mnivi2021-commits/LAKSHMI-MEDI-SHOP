<?php

declare(strict_types=1);

/*
 * Excel Upload (Phase 14):  php tests/imports.php
 * Spreadsheet reading, date/amount parsing, every importer through the full
 * upload -> map -> validate -> import pipeline, and that imported data moves the
 * dashboard KPIs by exactly the imported amounts. Seeded DB; rolled back.
 */

use App\Core\Database;
use App\Core\Money;
use App\Core\Spreadsheet\SheetReader;
use App\Core\Spreadsheet\XlsxReader;
use App\Core\Spreadsheet\XlsxWriter;
use App\Core\Spreadsheet\Zip;
use App\Modules\Dashboard\DashboardContext;
use App\Modules\Dashboard\Kpi\CollectionKpi;
use App\Modules\Dashboard\Kpi\OutstandingKpi;
use App\Modules\Dashboard\Kpi\PendingOrderKpi;
use App\Modules\Dashboard\Kpi\SalesKpi;
use App\Modules\Dashboard\Kpi\SampleDcKpi;
use App\Modules\Imports\Importer;
use App\Modules\Imports\ImportService;

require dirname(__DIR__) . '/bootstrap/app.php';
require __DIR__ . '/_helpers.php';

$today = new DateTimeImmutable('2026-10-06');
$tmpDir = sys_get_temp_dir();
$created = [];

/** Upload a CSV string, auto-map, validate. @return array{0: array, 1: Importer, 2: array} [batch, importer, counts] */
$upload = static function (string $type, string $csv, array $user) use ($today, $tmpDir, &$created): array {
    $imp = ImportService::importer($type, $user, $today);
    $path = tempnam($tmpDir, 'imp');
    file_put_contents($path, $csv);
    $res = ImportService::createBatch($imp, $path, "{$type}.csv", false, (int) $user['id']);
    unlink($path);
    $batch = Database::fetch('SELECT * FROM import_batches WHERE id = ?', [$res['id']]);
    $created[] = $batch['stored_filename'];
    $counts = ImportService::validate($batch, $imp);
    return [Database::fetch('SELECT * FROM import_batches WHERE id = ?', [$res['id']]), $imp, $counts];
};
$rowStatus = static fn (int $batchId): array => array_column(
    Database::fetchAll('SELECT row_no, status FROM import_rows WHERE batch_id = ? ORDER BY row_no', [$batchId]), 'status', 'row_no');
$rowErrors = static fn (int $batchId, int $rowNo): string => implode(' ', json_decode((string) Database::value(
    'SELECT errors FROM import_rows WHERE batch_id = ? AND row_no = ?', [$batchId, $rowNo]), true) ?: []);

$pdo = Database::connection();
$pdo->beginTransaction();
try {
    // ---------------------------------------------------------------- parsing
    foreach (['05-04-2026' => '2026-04-05', '5/4/2026' => '2026-04-05', '05.04.26' => '2026-04-05', '2026-04-05' => '2026-04-05',
              '05-Apr-2026' => '2026-04-05', '5 April 2026' => '2026-04-05', '46117' => '2026-04-05', '2026-04-05 10:30' => '2026-04-05',
              '31-02-2026' => null, '13/13/2026' => null, 'tomorrow' => null] as $in => $want) {
        check("date \"{$in}\"", $want, Importer::parseDate((string) $in));
    }
    check('Money::times 42 x 500', 2100000, Money::times(4200, '500'));
    check('Money::times half-up 33.35 x 0.1 = 3.335 -> 3.34', 334, Money::times(3335, '0.1'));

    // XLSX written by us -> read back
    $bytes = (new XlsxWriter())->addSheet('S', [['A', 'B'], ['x', '=1+1'], ['தமிழ்', '']])->toString();
    check('xlsx round trip', [['A', 'B'], ['x', '=1+1'], ['தமிழ்', '']], XlsxReader::read($bytes, 10));

    // XLSX shaped like Excel's own output: shared strings, date style, custom date format, float noise, gaps
    $xl = static fn (string $body) => '<?xml version="1.0" encoding="UTF-8"?>' . $body;
    $excel = Zip::build([
        '[Content_Types].xml' => $xl('<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>'),
        'xl/workbook.xml' => $xl('<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Orders" sheetId="1" r:id="rId3"/></sheets></workbook>'),
        'xl/_rels/workbook.xml.rels' => $xl('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId3" Type="x" Target="worksheets/sheet7.xml"/></Relationships>'),
        'xl/sharedStrings.xml' => $xl('<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Order No</t></si><si><t>Date</t></si><si><r><t>Va</t></r><r><t>lue</t></r></si><si><t xml:space="preserve"> SO-1 </t></si><si><t>Due</t></si></sst>'),
        'xl/styles.xml' => $xl('<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts><numFmt numFmtId="164" formatCode="dd\-mmm\-yy;@"/><numFmt numFmtId="165" formatCode="&quot;Rs&quot;#,##0.00"/></numFmts>'
            . '<cellXfs><xf numFmtId="0"/><xf numFmtId="14"/><xf numFmtId="164"/><xf numFmtId="165"/></cellXfs></styleSheet>'),
        'xl/worksheets/sheet7.xml' => $xl('<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="E1" t="s"><v>4</v></c></row>'
            . '<row r="2"><c r="A2" t="s"><v>3</v></c><c r="B2" s="1"><v>46117</v></c><c r="C2" s="3"><v>1250.0000000001</v></c><c r="E2" s="2"><v>46147</v></c></row>'
            . '<row r="4"><c r="A4" t="inlineStr"><is><t>SO-2</t></is></c><c r="C4"><v>0.30000000000000004</v></c><c r="D4" t="b"><v>1</v></c></row>'
            . '</sheetData></worksheet>'),
    ]);
    $read = XlsxReader::read($excel, 10);
    check('excel: header from shared strings (rich text joined)', ['Order No', 'Date', 'Value', '', 'Due'], $read[0]);
    check('excel: date cell (built-in format 14)', '2026-04-05', $read[1][1]);
    check('excel: date cell (custom dd-mmm-yy)', '2026-05-05', $read[1][4]);
    check('excel: currency format is NOT a date; float noise removed', '1250', $read[1][2]);
    check('excel: trimmed shared string', 'SO-1', $read[1][0]);
    check('excel: blank row 3 kept so row numbers stay true', [], $read[2]);
    check('excel: 0.1+0.2 noise -> 0.3', '0.3', $read[3][2]);
    check('excel: boolean', 'TRUE', $read[3][3]);
    $threw = null;
    try { XlsxReader::read($excel, 1); } catch (RuntimeException $e) { $threw = $e->getMessage(); }
    check('row limit enforced', true, $threw !== null && str_contains($threw, 'more than 1 data rows'));
    $threw = null;
    try { Zip::open('PK' . str_repeat('x', 40)); } catch (RuntimeException $e) { $threw = $e->getMessage(); }
    check('garbage zip refused', true, $threw !== null);

    check('csv: semicolon + quotes + BOM', [['a', 'b'], ['1', 'x;y']], SheetReader::csv("\xEF\xBB\xBFa;b\r\n1;\"x;y\"\r\n", 10));

    $admin = seeded_user('admin');
    $ctx = static fn () => DashboardContext::fromRequest($admin, [], $today);

    // ------------------------------------------------------- pending orders
    $before = (new PendingOrderKpi($ctx()))->summary()['value'];
    [$b, $imp, $c] = $upload('pending_orders',
        "Order No,Order Date,Customer Code,Product Code,Qty,Rate,Order Value,Supplied Qty,Expected Delivery\n"
        . "IMP-SO-1,05-09-2026,CUS-00001,P001,500,42,,100,30-10-2026\n"        // 21,000 order, 4,200 supplied
        . "IMP-SO-1,05-09-2026,CUS-00001,P003,200,,7600,,30-10-2026\n"       // 7,600
        . "IMP-SO-2,06-09-2026,CUS-00002,P001,10,42,,,\n"
        . "IMP-SO-2,06-09-2026,CUS-00002,P999,10,42,,,\n"                     // bad product -> whole SO-2 held back
        . "IMP-SO-3,07-09-2026,CUS-00003,P002,1,1150,,,\n"
        . "IMP-SO-3,08-09-2026,CUS-00003,P004,1,980,,,\n"                     // date differs between lines
        . "IMP-SO-4,07-09-2026,CUS-00004,P002,2,,,,\n"                        // no rate / value
        . "IMP-SO-5,07-09-2026,CUS-00004,P002,2,100,,3,\n"                    // supplied > ordered
        . ",,,,,,,,\n"                                                         // blank line ignored
        . "IMP-SO-6,07-09-2026,CUS-00005,P002,1,1150,,1,\n", $admin);       // fully supplied -> closed
    check('orders: auto-mapped every column', 9, count(json_decode($b['column_mapping'], true)));
    check('orders: 9 data rows (blank line skipped)', 9, (int) $b['total_rows']);
    $st = $rowStatus((int) $b['id']);
    check('orders: SO-1 both lines valid', ['valid', 'valid'], [$st[2], $st[3]]);
    check('orders: bad product line invalid', 'invalid', $st[5]);
    check('orders: good line of same order held back', 'invalid', $st[4]);
    check('orders: held-back reason names the bad row', true, str_contains($rowErrors((int) $b['id'], 4), 'row 5'));
    check('orders: header mismatch -> invalid', 'invalid', $st[6]);
    check('orders: header mismatch message', true, str_contains($rowErrors((int) $b['id'], 6), 'same Order Date'));
    check('orders: missing rate/value', true, str_contains($rowErrors((int) $b['id'], 8), 'Rate or Order Value'));
    check('orders: supplied > ordered', true, str_contains($rowErrors((int) $b['id'], 9), 'cannot be more than Order Qty'));
    check('orders: counts', ['valid' => 3, 'invalid' => 6, 'duplicate' => 0, 'records' => 2], $c);
    $r = ImportService::run((int) $b['id'], $imp);
    check('orders: 2 orders from 3 rows', ['imported_rows' => 3, 'records' => 2], $r);
    $so1 = Database::fetch("SELECT * FROM pending_orders WHERE order_no = 'IMP-SO-1'");
    check('orders: SO-1 partial, import source + batch', ['partial', 'import', (int) $b['id']], [$so1['status'], $so1['source'], (int) $so1['import_batch_id']]);
    check('orders: SO-6 fully supplied -> closed', 'closed', Database::value("SELECT status FROM pending_orders WHERE order_no = 'IMP-SO-6'"));
    check('orders: pending KPI moved by exactly 21,000 - 4,200 + 7,600', $before + 2440000, (new PendingOrderKpi($ctx()))->summary()['value']);
    check('orders: rows marked imported with record id', 3, (int) Database::value("SELECT COUNT(*) FROM import_rows WHERE batch_id = ? AND status = 'imported' AND record_id IS NOT NULL", [$b['id']]));
    $threw = null;
    try { ImportService::run((int) $b['id'], $imp); } catch (RuntimeException $e) { $threw = $e->getMessage(); }
    check('orders: cannot import the same batch twice', true, $threw !== null && str_contains($threw, 'not ready'));

    // re-upload: existing orders are duplicates
    [$b2, , $c2] = $upload('pending_orders', "Order No,Order Date,Customer Code,Product Code,Qty,Rate\nIMP-SO-1,05-09-2026,CUS-00001,P001,500,42\n", $admin);
    check('orders: existing order -> duplicate', ['valid' => 0, 'invalid' => 0, 'duplicate' => 1, 'records' => 0], $c2);
    [$header, $problems] = ImportService::problemRows($b);
    check('error report header', ['Row', 'Status', 'Problem', 'Order No', 'Order Date'], array_slice($header, 0, 5));
    check('error report has every problem row', 6, count($problems));

    // ------------------------------------------------------------- sales
    $sBefore = (new SalesKpi($ctx()))->summary()['sales_total'];
    $balBefore = Money::fromDb(Database::value("SELECT balance FROM v_invoice_balances WHERE invoice_no = (SELECT invoice_no FROM sales_invoices WHERE customer_id = 3 AND document_type='invoice' ORDER BY id LIMIT 1) AND customer_id = 3"));
    $origInv = Database::value("SELECT invoice_no FROM sales_invoices WHERE customer_id = 3 AND document_type='invoice' ORDER BY id LIMIT 1");
    [$b, $imp, $c] = $upload('sales',
        "Invoice No,Invoice Date,Type,Customer Code,Product Code,Quantity,Rate,Taxable Value,GST %,Total Amount,Against Invoice\n"
        . "IMP-INV-1,10-09-2026,Invoice,CUS-00003,P002,10,1150,,18,,\n"          // 11,500 + 2,070
        . "IMP-INV-1,10-09-2026,Invoice,CUS-00003,P007,5,,3600,,,\n"              // 3,600 + 648 (product GST 18)
        . "IMP-INV-2,11-09-2026,Tax Invoice,CUS-00004,,,,10000,12,11200.40,\n"     // header-only, round off +0.40
        . "IMP-INV-3,11-09-2026,Invoice,CUS-00004,,,,10000,12,11500,\n"            // total off by 300 -> error
        . "IMP-CN-1,12-09-2026,Credit Note,CUS-00003,,,,1000,18,,{$origInv}\n", $admin);
    check('sales: counts', ['valid' => 4, 'invalid' => 1, 'duplicate' => 0, 'records' => 3], $c);
    check('sales: total mismatch reported', true, str_contains($rowErrors((int) $b['id'], 5), 'does not equal'));
    ImportService::run((int) $b['id'], $imp);
    $inv1 = Database::fetch("SELECT * FROM sales_invoices WHERE invoice_no = 'IMP-INV-1'");
    check('sales: invoice header = sum of lines', ['15100.00', '2718.00', '17818.00'], [$inv1['taxable_amount'], $inv1['tax_amount'], $inv1['total_amount']]);
    check('sales: 2 item lines', 2, (int) Database::value('SELECT COUNT(*) FROM sales_invoice_items WHERE invoice_id = ?', [$inv1['id']]));
    check('sales: due date from credit days', true, $inv1['due_date'] !== null && $inv1['due_date'] > '2026-09-10');
    check('sales: round-off kept', ['0.40', '11200.40'], array_values(Database::fetch("SELECT round_off, total_amount FROM sales_invoices WHERE invoice_no = 'IMP-INV-2'")));
    check('sales: KPI += 15,100 + 10,000 - 1,000 (credit note)', $sBefore + 2410000, (new SalesKpi($ctx()))->summary()['sales_total']);
    $balAfter = Money::fromDb(Database::value('SELECT balance FROM v_invoice_balances WHERE invoice_no = ? AND customer_id = 3', [$origInv]));
    check('sales: credit note reduced the original invoice balance by 1,180', $balBefore - 118000, $balAfter);

    // ------------------------------------------------------- collections
    $cBefore = (new CollectionKpi($ctx()))->summary()['fy_to_date'];
    $openBefore = Money::fromDb(Database::value('SELECT COALESCE(SUM(balance),0) FROM v_invoice_balances WHERE customer_id = 3 AND balance > 0'));
    [$b, $imp, $c] = $upload('collections',
        "Receipt No,Receipt Date,Customer Code,Amount,Payment Mode,Status,Against Invoice\n"
        . "IMP-RC-1,15-09-2026,CUS-00003,\"10,000\",NEFT,,IMP-INV-1\n"
        . "IMP-RC-2,15-09-2026,CUS-00003,5000,Cheque,Bounced,\n"
        . "IMP-RC-1,16-09-2026,CUS-00003,100,UPI,,\n"                       // same receipt no in file
        . "IMP-RC-3,15-09-2026,CUS-00003,500,Bitcoin,,\n"                   // bad mode
        . "IMP-RC-4,15-09-2026,CUS-00003,750,gpay,,\n", $admin);
    check('collections: counts', ['valid' => 3, 'invalid' => 1, 'duplicate' => 1, 'records' => 3], $c);
    check('collections: repeated receipt flagged', true, str_contains($rowErrors((int) $b['id'], 4), 'Same record as row 2'));
    ImportService::run((int) $b['id'], $imp);
    check('collections: KPI += 10,000 + 750 (bounced excluded)', $cBefore + 1075000, (new CollectionKpi($ctx()))->summary()['fy_to_date']);
    check('collections: allocated to the named invoice', '10000.00', Database::value("SELECT ca.amount FROM collection_allocations ca JOIN collections c ON c.id = ca.collection_id JOIN sales_invoices si ON si.id = ca.invoice_id WHERE c.receipt_no = 'IMP-RC-1' AND si.invoice_no = 'IMP-INV-1'"));
    check('collections: bounced receipt not allocated', 0, (int) Database::value("SELECT COUNT(*) FROM collection_allocations ca JOIN collections c ON c.id = ca.collection_id WHERE c.receipt_no = 'IMP-RC-2'"));
    check('collections: gpay -> UPI', 'upi', Database::value("SELECT payment_mode FROM collections WHERE receipt_no = 'IMP-RC-4'"));
    $openAfter = Money::fromDb(Database::value('SELECT COALESCE(SUM(balance),0) FROM v_invoice_balances WHERE customer_id = 3 AND balance > 0'));
    check('collections: customer outstanding fell by exactly 10,750', $openBefore - 1075000, $openAfter);

    // ---------------------------------------------------- samples and DC
    $sd = (new SampleDcKpi($ctx()))->summary();
    [$b, $imp] = $upload('samples', "Sample No,Sample Date,Customer Code,Product Code,Quantity,Sample Value,Supply Status\nIMP-SMP-1,01-10-2026,CUS-00001,P004,2,1960,Supplied\nIMP-SMP-1,01-10-2026,CUS-00001,P008,1,2100,Supplied\n", $admin);
    ImportService::run((int) $b['id'], $imp);
    [$b, $imp] = $upload('dc', "DC No,DC Date,Customer Code,Product Code,Quantity,DC Value,Sample No\nIMP-DC-1,02-10-2026,CUS-00001,P004,10,9800,IMP-SMP-1\n", $admin);
    ImportService::run((int) $b['id'], $imp);
    $sd2 = (new SampleDcKpi($ctx()))->summary();
    check('samples: pending samples += 4,060', $sd['samples']['value'] + 406000, $sd2['samples']['value']);
    check('dc: pending DC += 9,800', $sd['dc']['value'] + 980000, $sd2['dc']['value']);
    check('dc: linked to the sample', true, (bool) Database::value("SELECT 1 FROM dc_records d JOIN samples s ON s.id = d.sample_id WHERE d.dc_no = 'IMP-DC-1' AND s.document_no = 'IMP-SMP-1'"));

    // ------------------------------------------------------- outstanding
    [$b, $imp, $c] = $upload('outstanding', "As On Date,Customer Code,Invoice No,Invoice Date,Bill Amount,Pending Amount\n30-09-2026,CUS-00005,OB-1,15-04-2026,\"1,18,000\",\"68,000\"\n30-09-2026,CUS-00005,OB-2,01-09-2026,5000,6000\n", $admin);
    check('outstanding: pending > bill rejected', ['valid' => 1, 'invalid' => 1, 'duplicate' => 0, 'records' => 1], $c);
    ImportService::run((int) $b['id'], $imp);
    Database::query("INSERT INTO settings (setting_group, setting_key, setting_value) VALUES ('outstanding', 'source', 'imported') ON DUPLICATE KEY UPDATE setting_value = 'imported'");
    \App\Core\Settings::forget();
    $o = (new OutstandingKpi($ctx()))->categories();
    check('outstanding: imported bill (174 days old) is in the 150+ box', 6800000, $o['d150']['value']);
    Database::query("UPDATE settings SET setting_value = 'computed' WHERE setting_group = 'outstanding' AND setting_key = 'source'");
    \App\Core\Settings::forget();

    // --------------------------------------------------------- customers
    $next = (int) Database::value("SELECT next_number FROM number_sequences WHERE name = 'customer'");
    [$b, $imp, $c] = $upload('customers',
        "Customer Name,Mobile,Email,Branch Code,Sales Employee,GSTIN,Pincode\n"
        . "New Buyer One,+91 98765 43210,,chn,jana,,600001\n"
        . "Dup Mobile,9100000001,,CHN,,,\n"
        . "No Contact,,,CHN,,,\n"
        . "Bad Branch,9876500000,,XYZ,,,\n"
        . "Bad GST,9876500001,,CBE,PRAKASH,12345,\n", $admin);
    check('customers: counts', ['valid' => 1, 'invalid' => 3, 'duplicate' => 1, 'records' => 1], $c);
    ImportService::run((int) $b['id'], $imp);
    $nc = Database::fetch("SELECT * FROM customers WHERE name = 'New Buyer One'");
    check('customers: auto code', sprintf('CUS-%05d', $next), $nc['customer_code']);
    check('customers: mobile normalised, branch + employee resolved', ['9876543210', 1, 2], [$nc['mobile'], (int) $nc['branch_id'], (int) $nc['employee_id']]);

    // ------------------------------------------------------------- leads
    [$b, $imp, $c] = $upload('leads', "Lead Name,Mobile,Source,Branch Code,Priority,Expected Value,Next Follow-up\nLead A,9300001111,Website,CHN,Hot,\"1,50,000\",20-10-2026\nLead B,9300002222,Fax,CHN,,,\nLead C,9300001111,,CHN,,,\n", $admin);
    check('leads: unknown source invalid, repeated mobile duplicate', ['valid' => 1, 'invalid' => 1, 'duplicate' => 1, 'records' => 1], $c);
    ImportService::run((int) $b['id'], $imp);
    $la = Database::fetch("SELECT * FROM leads WHERE name = 'Lead A'");
    check('leads: urgent, value, number', ['urgent', '150000.00', true], [$la['priority'], $la['expected_value'], str_starts_with($la['lead_number'], 'LD-')]);
    check('leads: follow-up record created', 1, (int) Database::value("SELECT COUNT(*) FROM followups WHERE lead_id = ? AND status = 'pending'", [$la['id']]));

    // ---------------------------------------------------------- scope
    $jana = seeded_user('jana');
    $janaImp = ImportService::importer('pending_orders', $jana, $today);
    check('JANA has no import permission by default', false, \App\Core\Gate::allows($janaImp->permission(), $jana));
    [, $c] = (static function () use ($janaImp): array {
        return $janaImp->validateRow(['order_no' => 'X1', 'order_date' => '01-09-2026', 'customer_code' => 'CUS-00006', 'product_code' => 'P001', 'order_qty' => '1', 'rate' => '10']);
    })();
    check('scope: JANA cannot import for a Coimbatore customer', true, in_array('You do not have access to customer CUS-00006.', $c, true));

    // --------------------------------------------- future date / locked FY
    [, $e] = ImportService::importer('collections', $admin, $today)->validateRow(['receipt_no' => 'F1', 'receipt_date' => '07-10-2026', 'customer_code' => 'CUS-00001', 'amount' => '1', 'payment_mode' => 'cash']);
    check('future receipt date refused', true, in_array('Receipt Date cannot be in the future.', $e, true));
} finally {
    $pdo->rollBack();
    foreach ($created as $f) {
        @unlink(ImportService::storageDir() . '/' . $f);
    }
}

exit(test_summary());
