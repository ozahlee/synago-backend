<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;

/** The standard church chart of accounts and how existing records post to it. Editable afterwards. */
class ChartOfAccounts
{
    /** code => [name, type, subtype] */
    public const ACCOUNTS = [
        '1000' => ['Cash at hand', 'asset', 'cash'],
        '1010' => ['Mobile money', 'asset', 'mobile_money'],
        '1020' => ['Bank – current account', 'asset', 'bank'],
        '1030' => ['Bank – savings / fixed deposit', 'asset', 'bank'],
        '1100' => ['Receivables & staff advances', 'asset', 'receivable'],
        '1500' => ['Land & buildings', 'asset', 'fixed_asset'],
        '1510' => ['Furniture & equipment', 'asset', 'fixed_asset'],
        '1520' => ['Vehicles', 'asset', 'fixed_asset'],
        '1590' => ['Accumulated depreciation', 'asset', 'contra_asset'],
        '2000' => ['Payables & accruals', 'liability', 'payable'],
        '2100' => ['Loans', 'liability', 'loan'],
        '2200' => ['Due to diocese / head office', 'liability', 'payable'],
        '3000' => ['General fund', 'fund', null],
        '3100' => ['Restricted funds', 'fund', null],
        '4000' => ['Tithes', 'income', null],
        '4010' => ['Sunday offerings', 'income', null],
        '4020' => ['Thanksgiving', 'income', null],
        '4030' => ['Pledge redemption', 'income', null],
        '4040' => ['Harvest', 'income', null],
        '4100' => ['Donations & gifts', 'income', null],
        '4110' => ['Project contributions', 'income', null],
        '4200' => ['Fundraising & events', 'income', null],
        '4300' => ['Rental income', 'income', null],
        '4400' => ['Welfare collections', 'income', null],
        '4500' => ['Sacrament & service fees', 'income', null],
        '4900' => ['Other income', 'income', null],
        '5000' => ['Salaries & allowances', 'expense', null],
        '5100' => ['Utilities', 'expense', null],
        '5200' => ['Repairs & maintenance', 'expense', null],
        '5300' => ['Ministry programmes', 'expense', null],
        '5310' => ['Outreach & missions', 'expense', null],
        '5400' => ['Media & technology', 'expense', null],
        '5500' => ['Supplies & stationery', 'expense', null],
        '5600' => ['Events', 'expense', null],
        '5700' => ['Welfare support', 'expense', null],
        '5800' => ['Project costs', 'expense', null],
        '5810' => ['Small equipment', 'expense', null],
        '5850' => ['Depreciation', 'expense', null],
        '5900' => ['Other expenses', 'expense', null],
        '5950' => ['Diocesan assessment / quota', 'expense', null],
    ];

    /** source => [key => code]. Unmapped keys fall back to FALLBACK. */
    public const MAPPINGS = [
        'payment_method' => ['Cash' => '1000', 'Mobile Money' => '1010', 'Bank Transfer' => '1020', 'Cheque' => '1020'],
        'offertory' => ['tithe' => '4000', 'pledge' => '4030', 'thanksgiving' => '4020', 'harvest' => '4040'],
        'income' => [
            'Offerings' => '4010', 'Tithes' => '4000', 'Donations' => '4100', 'Events' => '4200', 'Fundraising' => '4200',
            'Rental Income' => '4300', 'Welfare' => '4400', 'Project contributions' => '4110', 'Sacrament fees' => '4500', 'Other' => '4900',
        ],
        'expense' => [
            'Salaries' => '5000', 'Utilities' => '5100', 'Maintenance' => '5200', 'Ministry Programs' => '5300', 'Outreach' => '5310',
            'Missions' => '5310', 'Media & Technology' => '5400', 'Supplies' => '5500', 'Events' => '5600', 'Welfare' => '5700',
            'Building materials' => '5800', 'Labour' => '5800', 'Professional fees' => '5800', 'Transport' => '5800',
            'Equipment' => '5810', 'Land & buildings' => '1500', 'Diocesan quota' => '5950', 'Other' => '5900',
        ],
    ];

    public const FALLBACK = ['payment_method' => '1000', 'offertory' => '4900', 'income' => '4900', 'expense' => '5900'];

    /** Accounts the ledger relies on; they can be renamed but not deleted. */
    public const SYSTEM = ['1000', '3000', '3100', '4900', '5900'];

    /** Give a church the standard chart of accounts and posting rules (safe to run again). */
    public static function install(int $churchId): void
    {
        $now = now();
        foreach (self::ACCOUNTS as $code => [$name, $type, $subtype]) {
            DB::table('accounts')->insertOrIgnore([
                'church_id' => $churchId, 'code' => $code, 'name' => $name, 'type' => $type, 'subtype' => $subtype,
                'is_system' => in_array($code, self::SYSTEM, true), 'active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $ids = DB::table('accounts')->where('church_id', $churchId)->pluck('id', 'code');
        foreach (self::MAPPINGS as $source => $map) {
            foreach ($map as $key => $code) {
                DB::table('account_mappings')->insertOrIgnore([
                    'church_id' => $churchId, 'source' => $source, 'key' => $key, 'account_id' => $ids[$code], 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }
}
