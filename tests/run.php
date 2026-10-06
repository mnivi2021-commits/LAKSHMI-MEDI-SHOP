<?php

declare(strict_types=1);

/*
 * Dependency-free test runner:  php tests/run.php
 * Phase 1 covers financial-year date windows and Indian currency formatting -
 * the two calculations every dashboard figure depends on.
 */

use App\Core\FinancialYear;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap/app.php';

$passed = 0;
$failed = 0;

function check(string $name, mixed $expected, mixed $actual): void
{
    global $passed, $failed;
    if ($expected === $actual) {
        $passed++;
        return;
    }
    $failed++;
    echo "FAIL  {$name}\n      expected: " . var_export($expected, true) . "\n      actual:   " . var_export($actual, true) . "\n";
}

// --- Financial year: the worked example from the spec (06-10-2026) -------------
$fy = FinancialYear::forDate('2026-10-06', 4);
check('label', 'FY 2026-27', $fy->label());
check('short label', '26-27', $fy->shortLabel());
check('full year', '01-04-2026 to 31-03-2027', $fy->fullYear()->label());
check('FY to previous day', '01-04-2026 to 05-10-2026', $fy->fyToPreviousDay()?->label());
check('month to previous day', '01-10-2026 to 05-10-2026', $fy->monthToPreviousDay()?->label());
check('today', '06-10-2026', $fy->today()->label());
check('FY to date SQL bounds', ['2026-04-01', '2026-10-06'], [$fy->fyToDate()->from(), $fy->fyToDate()->to()]);
check('completed months Apr-Sep', 6, $fy->completedMonths());
check('remaining months Oct-Mar', 6, $fy->remainingMonths());

// No overlap: previous-day window ends the day before today's window starts.
check('no double counting', true, $fy->fyToPreviousDay()->end < $fy->today()->start);

// --- Boundaries -----------------------------------------------------------------
$jan = FinancialYear::forDate('2027-01-15', 4);
check('January belongs to previous calendar year FY', 'FY 2026-27', $jan->label());

$mar31 = FinancialYear::forDate('2027-03-31', 4);
check('31 March is last day of FY', 'FY 2026-27', $mar31->label());
check('31 March: 11 completed months', 11, $mar31->completedMonths());
check('31 March: 1 remaining month', 1, $mar31->remainingMonths());

$apr1 = FinancialYear::forDate('2027-04-01', 4);
check('1 April starts new FY', 'FY 2027-28', $apr1->label());
check('1 April: FY-to-previous-day is empty', null, $apr1->fyToPreviousDay());
check('1 April: month-to-previous-day is empty', null, $apr1->monthToPreviousDay());

$nov1 = FinancialYear::forDate('2026-11-01', 4);
check('1st of month: month-to-previous-day empty', null, $nov1->monthToPreviousDay());
check('1st of month: FY-to-previous-day still set', '01-04-2026 to 31-10-2026', $nov1->fyToPreviousDay()?->label());

$leap = FinancialYear::forDate('2028-03-01', 4);
check('leap year: FY ends 31-03-2028', '01-04-2027 to 31-03-2028', $leap->fullYear()->label());
check('leap year: month-to-previous-day empty on 1 March', null, $leap->monthToPreviousDay());

$calendar = FinancialYear::forDate('2026-10-06', 1);
check('configurable start month (Jan-Dec)', 'FY 2026', $calendar->label());

try {
    FinancialYear::forDate('2026-02-30');
    check('invalid date rejected', true, false);
} catch (InvalidArgumentException) {
    check('invalid date rejected', true, true);
}

// --- Indian currency formatting --------------------------------------------------
check('10 lakh', '₹10,00,000.00', inr('1000000'));
check('1 crore 23 lakh', '₹1,23,45,678.90', inr('12345678.9'));
check('thousands', '₹1,000.00', inr(1000));
check('below thousand', '₹999.50', inr('999.5'));
check('zero', '₹0.00', inr('0'));
check('null', '₹0.00', inr(null));
check('negative', '-₹5,25,000.00', inr('-525000'));
check('round half up', '₹1,000.01', inr('1000.005'));
check('carry on rounding', '₹10,00,000.00', inr('999999.995'));
check('no decimals', '₹10,00,000', inr('999999.5', 0));
check('large DECIMAL(15,2) exact', '₹99,99,99,99,99,999.99', inr('9999999999999.99'));
check('negative rounding to zero', '₹0.00', inr('-0.001'));

// --- Password policy ----------------------------------------------------------------
use App\Core\PasswordPolicy;
use App\Core\Request;

check('strong password accepted', [], PasswordPolicy::validate('Mango-River-42', 'jana', 'jana@example.com'));
check('too short rejected', true, in_array('Use at least 10 characters.', PasswordPolicy::validate('Ab1-short', 'x'), true));
check('letters only rejected', true, PasswordPolicy::validate('onlyletterspassword') !== []);
check('seeded demo password rejected', true, PasswordPolicy::validate('Admin@2026') !== []);
check('common password rejected', true, PasswordPolicy::validate('Password@123') !== []);
check('contains username rejected', true, PasswordPolicy::validate('jana-2026-secure', 'jana') !== []);
check('contains email name rejected', true, PasswordPolicy::validate('xLakshmi99xx', 'coord', 'lakshmi@example.com') !== []);
check('repetitive rejected', true, PasswordPolicy::validate('1111111111a') !== []);
check('unicode letters count', [], PasswordPolicy::validate('மாம்பழம்2026x', 'jana'));

// --- Open-redirect protection -----------------------------------------------------
check('relative path allowed', '/reports?x=1', Request::safeRedirectPath('/reports?x=1'));
check('protocol-relative blocked', '/', Request::safeRedirectPath('//evil.example.com'));
check('absolute URL blocked', '/', Request::safeRedirectPath('https://evil.example.com'));
check('backslash trick blocked', '/', Request::safeRedirectPath('/\\evil.example.com'));
check('header injection blocked', '/', Request::safeRedirectPath("/a\r\nSet-Cookie: x=1"));
check('empty uses default', '/', Request::safeRedirectPath(''));

// --- Money (integer paise) -------------------------------------------------------------
use App\Core\Money;

check('parse plain', 1250050, Money::parse('12500.50'));
check('parse Indian grouping + symbol', 100000000, Money::parse('₹ 10,00,000'));
check('parse one decimal', 1050, Money::parse('10.5'));
check('reject 3 decimals', null, Money::parse('10.555'));
check('reject negative', null, Money::parse('-5'));
check('reject text', null, Money::parse('12abc'));
check('reject too large', null, Money::parse('99999999999999'));
check('toDecimal', '123456.07', Money::toDecimal(12345607));
check('toDecimal zero', '0.00', Money::toDecimal(0));
check('GST 18% exact', 180000, Money::percentOf(1000000, '18'));
check('GST rounds half up', 2, Money::percentOf(11, '18'));        // 1.98 paise -> 2
check('GST 12.5%', 125, Money::percentOf(1000, '12.5'));
check('split remainder to last month', [833333, 833333, 833333, 833333, 833333, 833333, 833333, 833333, 833333, 833333, 833333, 833337], Money::split(10000000, 12));
check('split sums exactly', 10000001, array_sum(Money::split(10000001, 12)));
check('per unit rate', 333, Money::perUnit(1000, '3'));             // 10.00 / 3 = 3.33
check('per unit fractional qty', 400, Money::perUnit(1000, '2.5'));

// --- CSV export: spreadsheet formula injection --------------------------------------------
use App\Modules\Dashboard\SalesController;

check('formula neutralised', "'=HYPERLINK(\"x\")", SalesController::csvSafe('=HYPERLINK("x")'));
check('plus neutralised', "'+cmd", SalesController::csvSafe('+cmd'));
check('at neutralised', "'@SUM(A1)", SalesController::csvSafe('@SUM(A1)'));
check('negative number kept', '-2360.00', SalesController::csvSafe('-2360.00'));
check('dash text neutralised', "'-cmd", SalesController::csvSafe('-cmd'));
check('normal text untouched', 'Sri Balaji Traders', SalesController::csvSafe('Sri Balaji Traders'));
check('null becomes empty', '', SalesController::csvSafe(null));

// --- Short rupee format (chart axes) ----------------------------------------------------------
check('crore', '₹1.25 Cr', rupees_short(1250000000));
check('lakh', '₹38.2 L', rupees_short(382320000));
check('thousand', '₹45.6 K', rupees_short(4560000));
check('small', '₹950', rupees_short(95000));
check('zero', '₹0', rupees_short(0));
check('negative', '-₹2.36 K', rupees_short(-236000));

echo PHP_EOL . "{$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
