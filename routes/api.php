<?php

use App\Http\Controllers\Api\AccountingController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\HouseholdController;
use App\Http\Controllers\Api\OrganisationController;
use App\Http\Controllers\Api\SermonController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\ReminderController;
use App\Http\Controllers\Api\SundaySchoolController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\PastoralCareController;
use App\Http\Controllers\Api\OutreachController;
use App\Http\Controllers\Api\MyGroupsController;
use App\Http\Controllers\Api\HarvestReportController;
use App\Http\Controllers\Api\AreaController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChurchController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\BaptismController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\CellGroupController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MarriageController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MinistryController;
use App\Http\Controllers\Api\OffertoryController;
use App\Http\Controllers\Api\PledgeController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\SmsController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VisitorController;
use App\Http\Controllers\Api\WelfareController;
use Illuminate\Support\Facades\Route;

/*
| All routes are prefixed with /api/v1. Authenticate with a Sanctum bearer token from POST /auth/login.
| Reads and writes are gated separately via spatie permissions (see RolesAndPermissionsSeeder).
*/
Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    // Calendar subscription for phones (the token in the URL is the credential)
    Route::get('calendar/feed/{token}.ics', [CalendarController::class, 'feed'])->middleware('throttle:60,1');

    // A new church signs up (creates the church and its first admin) and verifies the admin's email
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::get('auth/verify/{id}/{hash}', [AuthController::class, 'verify'])->middleware(['signed', 'throttle:20,1'])->name('verification.verify');

    // Signed in: own account (church users and platform owners alike)
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::patch('auth/me', [ProfileController::class, 'update']);
        Route::put('auth/password', [ProfileController::class, 'password']);
        Route::post('auth/verify/resend', [AuthController::class, 'resendVerification'])->middleware('throttle:3,10');
    });

    // Platform owner console (not tied to any church)
    Route::middleware(['auth:sanctum', 'active', 'platform'])->prefix('platform')->group(function () {
        Route::get('stats', [PlatformController::class, 'stats']);
        Route::get('churches', [PlatformController::class, 'index']);
        Route::get('churches/{church}', [PlatformController::class, 'show']);
        Route::patch('churches/{church}', [PlatformController::class, 'update']);
    });

    // Everything else works inside the signed-in user's church
    Route::middleware(['auth:sanctum', 'active', 'church'])->group(function () {
        Route::get('church', [ChurchController::class, 'show']);
        Route::post('church/onboarding', [ChurchController::class, 'completeOnboarding'])->middleware('permission:settings.manage');

        Route::get('dashboard', DashboardController::class)->middleware('permission:members.view|finance.view');
        Route::get('search', SearchController::class)->middleware('throttle:120,1');

        // Position-holders' own groups; Sunday school class register is open to the class's teachers too
        Route::get('me/groups', MyGroupsController::class);

        // Calendar: everyone (items are scoped to what each person may see)
        Route::get('calendar', [CalendarController::class, 'index']);
        Route::get('calendar/church-year', [CalendarController::class, 'churchYear']);
        Route::get('calendar/feed-link', [CalendarController::class, 'feedLink']);
        Route::post('calendar/feed-link', [CalendarController::class, 'feedLink']);
        Route::get('events/{event}', [CalendarController::class, 'showEvent']);
        Route::get('observances', [CalendarController::class, 'observances']);
        Route::middleware('permission:alerts.manage')->group(function () {
            Route::post('events', [CalendarController::class, 'storeEvent']);
            Route::patch('events/{event}', [CalendarController::class, 'updateEvent']);
            Route::delete('events/{event}', [CalendarController::class, 'destroyEvent']);
            Route::post('observances', [CalendarController::class, 'storeObservance']);
            Route::patch('observances/{observance}', [CalendarController::class, 'updateObservance']);
            Route::delete('observances/{observance}', [CalendarController::class, 'destroyObservance']);
        });

        // Reminders: personal for everyone; church-wide queue & rules with sms.send
        Route::get('reminders', [ReminderController::class, 'index']);
        Route::post('reminders', [ReminderController::class, 'store']);
        Route::post('reminders/{reminder}/cancel', [ReminderController::class, 'cancel']);
        Route::middleware('permission:sms.send')->group(function () {
            Route::get('reminder-rules', [ReminderController::class, 'rules']);
            Route::patch('reminder-rules/{key}', [ReminderController::class, 'updateRule']);
            Route::post('reminders/run', [ReminderController::class, 'runNow']);
        });
        Route::get('sunday-school/classes/{class}', [SundaySchoolController::class, 'show']);
        Route::get('sunday-school/classes/{class}/attendance', [SundaySchoolController::class, 'attendance']);
        Route::put('sunday-school/classes/{class}/attendance', [SundaySchoolController::class, 'saveAttendance']);

        // Church settings: readable by all staff (drives labels), editable by admins
        Route::get('settings', [SettingController::class, 'show']);
        Route::put('settings', [SettingController::class, 'update'])->middleware('permission:settings.manage');

        // Members & ministries
        Route::middleware('permission:members.view')->group(function () {
            Route::apiResource('members', MemberController::class)->only(['index', 'show']);
            Route::apiResource('ministries', MinistryController::class)->only(['index', 'show']);
            Route::get('baptisms/stats', [BaptismController::class, 'stats']);
            Route::apiResource('baptisms', BaptismController::class)->only(['index', 'show']);
            Route::get('attendance/day', [AttendanceController::class, 'day']);
            Route::get('attendance/trends', [AttendanceController::class, 'trends']);
            Route::get('marriages/stats', [MarriageController::class, 'stats']);
            Route::apiResource('marriages', MarriageController::class)->only(['index', 'show']);
            Route::get('visitors/stats', [VisitorController::class, 'stats']);
            Route::apiResource('visitors', VisitorController::class)->only(['index', 'show']);
            Route::apiResource('cell-groups', CellGroupController::class)->only(['index', 'show']);
            Route::get('areas', [AreaController::class, 'index']);
            Route::get('sunday-school/classes', [SundaySchoolController::class, 'index']);
            Route::get('positions', [PositionController::class, 'index']);
            Route::get('positions/scopes', [PositionController::class, 'scopes']);
            Route::get('care/stats', [PastoralCareController::class, 'stats']);
            Route::get('care/cases', [PastoralCareController::class, 'cases']);
            Route::get('care/cases/{case}', [PastoralCareController::class, 'showCase']);
            Route::get('care/visits', [PastoralCareController::class, 'visits']);
            Route::get('outreach', [OutreachController::class, 'index']);
            Route::get('households', [HouseholdController::class, 'index']);
            Route::get('households/{household}', [HouseholdController::class, 'show']);
            Route::get('sermons/stats', [SermonController::class, 'stats']);
            Route::get('sermons/series', [SermonController::class, 'series']);
            Route::get('scripture/parse', [SermonController::class, 'parse']);
            Route::get('sermons', [SermonController::class, 'index']);
            Route::get('sermons/{sermon}', [SermonController::class, 'show']);
        });
        Route::middleware('permission:members.manage')->group(function () {
            Route::apiResource('members', MemberController::class)->only(['store', 'update', 'destroy']);
            Route::apiResource('baptisms', BaptismController::class)->only(['store', 'update', 'destroy']);
            Route::put('baptisms/{baptism}/requirements/{key}', [BaptismController::class, 'requirement']);
            Route::put('attendance/day', [AttendanceController::class, 'saveDay']);
            Route::apiResource('marriages', MarriageController::class)->only(['store', 'update', 'destroy']);
            Route::apiResource('visitors', VisitorController::class)->only(['store', 'update', 'destroy']);
            Route::post('visitors/{visitor}/follow-ups', [VisitorController::class, 'followUp']);
            Route::post('visitors/{visitor}/convert', [VisitorController::class, 'convert']);
            Route::post('cell-groups/from-villages', [CellGroupController::class, 'fromVillages']);
            Route::apiResource('cell-groups', CellGroupController::class)->only(['store', 'update', 'destroy']);
            Route::post('cell-groups/{cell_group}/members', [CellGroupController::class, 'addMembers']);
            Route::delete('cell-groups/{cell_group}/members/{member}', [CellGroupController::class, 'removeMember']);
            Route::post('areas/from-members', [AreaController::class, 'fromMembers']);
            Route::apiResource('areas', AreaController::class)->only(['store', 'update', 'destroy']);
            Route::post('sunday-school/auto-assign', [SundaySchoolController::class, 'autoAssign']);
            Route::post('sunday-school/classes', [SundaySchoolController::class, 'store']);
            Route::patch('sunday-school/classes/{class}', [SundaySchoolController::class, 'update']);
            Route::delete('sunday-school/classes/{class}', [SundaySchoolController::class, 'destroy']);
            Route::post('positions', [PositionController::class, 'store']);
            Route::patch('positions/{position}', [PositionController::class, 'update']);
            Route::delete('positions/{position}', [PositionController::class, 'destroy']);
            Route::post('care/cases', [PastoralCareController::class, 'storeCase']);
            Route::patch('care/cases/{case}', [PastoralCareController::class, 'updateCase']);
            Route::delete('care/cases/{case}', [PastoralCareController::class, 'destroyCase']);
            Route::post('care/visits', [PastoralCareController::class, 'storeVisit']);
            Route::patch('care/visits/{visit}', [PastoralCareController::class, 'updateVisit']);
            Route::delete('care/visits/{visit}', [PastoralCareController::class, 'destroyVisit']);
            Route::post('members/{member}/death', [PastoralCareController::class, 'recordDeath']);
            Route::post('outreach', [OutreachController::class, 'store']);
            Route::post('households', [HouseholdController::class, 'store']);
            Route::patch('households/{household}', [HouseholdController::class, 'update']);
            Route::delete('households/{household}', [HouseholdController::class, 'destroy']);
            Route::post('households/{household}/members', [HouseholdController::class, 'addMembers']);
            Route::delete('households/{household}/members/{member}', [HouseholdController::class, 'removeMember']);
            Route::patch('outreach/{outreach}', [OutreachController::class, 'update']);
            Route::delete('outreach/{outreach}', [OutreachController::class, 'destroy']);
            Route::post('sermons/series', [SermonController::class, 'storeSeries']);
            Route::patch('sermons/series/{series}', [SermonController::class, 'updateSeries']);
            Route::delete('sermons/series/{series}', [SermonController::class, 'destroySeries']);
            Route::post('sermons', [SermonController::class, 'store']);
            Route::patch('sermons/{sermon}', [SermonController::class, 'update']);
            Route::delete('sermons/{sermon}', [SermonController::class, 'destroy']);
        });
        Route::middleware('permission:ministries.manage')->group(function () {
            Route::apiResource('ministries', MinistryController::class)->only(['store', 'update', 'destroy']);
            Route::post('ministries/{ministry}/members', [MinistryController::class, 'addMember']);
            Route::delete('ministries/{ministry}/members/{member}', [MinistryController::class, 'removeMember']);
            Route::post('ministries/{ministry}/activities', [MinistryController::class, 'storeActivity']);
            Route::delete('ministries/{ministry}/activities/{activity}', [MinistryController::class, 'destroyActivity']);
        });

        // Finance: offertory, accounts (transactions), budget, donation campaigns
        Route::middleware('permission:finance.view')->group(function () {
            Route::get('offertory/summary', [OffertoryController::class, 'summary']);
            Route::get('offertory/day', [OffertoryController::class, 'day']);
            Route::get('offertory/days', [OffertoryController::class, 'days']);
            Route::get('reports/tithes', [ReportController::class, 'tithes']);
            Route::get('reports/statement', [ReportController::class, 'statement']);
            Route::get('reports/statements', [ReportController::class, 'statements']);
            Route::get('pledges/stats', [PledgeController::class, 'stats']);
            Route::get('reports/harvest', HarvestReportController::class);
            Route::get('accounting/accounts', [AccountingController::class, 'accounts']);
            Route::get('accounting/mappings', [AccountingController::class, 'mappings']);
            Route::get('accounting/journals', [AccountingController::class, 'journals']);
            Route::get('accounting/funds', [AccountingController::class, 'funds']);
            Route::get('accounting/trial-balance', [AccountingController::class, 'trialBalance']);
            Route::get('accounting/income-statement', [AccountingController::class, 'incomeStatement']);
            Route::get('accounting/balance-sheet', [AccountingController::class, 'balanceSheet']);
            Route::get('accounting/ledger/{account}', [AccountingController::class, 'generalLedger']);
            Route::get('projects/stats', [ProjectController::class, 'stats']);
            Route::get('projects', [ProjectController::class, 'index']);
            Route::get('projects/{project}', [ProjectController::class, 'show']);
            Route::get('projects/{project}/contributions', [ProjectController::class, 'contributions']);
            Route::get('projects/{project}/summary', [ProjectController::class, 'summary']);
            Route::get('organisations', [OrganisationController::class, 'index']);
            Route::apiResource('pledges', PledgeController::class)->only(['index', 'show']);
            Route::apiResource('offertory', OffertoryController::class)->only(['index', 'show'])
                ->parameters(['offertory' => 'offertory']);
            Route::get('accounts/summary', [TransactionController::class, 'summary']);
            Route::get('accounts/trends', [TransactionController::class, 'trends']);
            Route::apiResource('accounts', TransactionController::class)->only(['index', 'show'])
                ->parameters(['accounts' => 'transaction']);
            Route::apiResource('budgets', BudgetController::class)->only(['index', 'show']);
        });
        Route::middleware('permission:finance.manage')->group(function () {
            Route::apiResource('offertory', OffertoryController::class)->only(['store', 'update', 'destroy'])
                ->parameters(['offertory' => 'offertory']);
            Route::apiResource('accounts', TransactionController::class)->only(['store', 'update', 'destroy'])
                ->parameters(['accounts' => 'transaction']);
            Route::apiResource('budgets', BudgetController::class)->only(['store', 'update', 'destroy']);
            Route::apiResource('pledges', PledgeController::class)->only(['store', 'update', 'destroy']);
            Route::post('pledges/{pledge}/payments', [PledgeController::class, 'pay']);
            Route::post('projects', [ProjectController::class, 'store']);
            Route::patch('projects/{project}', [ProjectController::class, 'update']);
            Route::delete('projects/{project}', [ProjectController::class, 'destroy']);
            Route::post('projects/{project}/transactions', [ProjectController::class, 'addTransaction']);
            Route::post('projects/{project}/contributions', [ProjectController::class, 'storeContribution']);
            Route::patch('contributions/{contribution}', [ProjectController::class, 'updateContribution']);
            Route::delete('contributions/{contribution}', [ProjectController::class, 'destroyContribution']);
            Route::post('contributions/{contribution}/thank', [ProjectController::class, 'thank']);
            Route::put('projects/{project}/targets', [ProjectController::class, 'setTargets']);
            Route::post('projects/{project}/milestones', [ProjectController::class, 'storeMilestone']);
            Route::patch('milestones/{milestone}', [ProjectController::class, 'updateMilestone']);
            Route::delete('milestones/{milestone}', [ProjectController::class, 'destroyMilestone']);
            Route::post('organisations', [OrganisationController::class, 'store']);
            Route::post('accounting/accounts', [AccountingController::class, 'storeAccount']);
            Route::patch('accounting/accounts/{account}', [AccountingController::class, 'updateAccount']);
            Route::delete('accounting/accounts/{account}', [AccountingController::class, 'destroyAccount']);
            Route::put('accounting/mappings', [AccountingController::class, 'updateMappings']);
            Route::post('accounting/journals', [AccountingController::class, 'storeJournal']);
            Route::patch('accounting/journals/{journal}', [AccountingController::class, 'updateJournal']);
            Route::delete('accounting/journals/{journal}', [AccountingController::class, 'destroyJournal']);
            Route::patch('organisations/{organisation}', [OrganisationController::class, 'update']);
            Route::delete('organisations/{organisation}', [OrganisationController::class, 'destroy']);
        });

        // Welfare / benevolence (sensitive: its own permission). Paying out also needs finance.manage.
        Route::middleware('permission:welfare.manage')->group(function () {
            Route::get('welfare/stats', [WelfareController::class, 'stats']);
            Route::apiResource('welfare', WelfareController::class)->only(['index', 'store', 'update', 'destroy'])
                ->parameters(['welfare' => 'welfare']);
            Route::post('welfare/{welfare}/decision', [WelfareController::class, 'decide']);
            Route::post('welfare/{welfare}/pay', [WelfareController::class, 'pay'])->middleware('permission:finance.manage');
        });

        // Bulk SMS
        Route::middleware('permission:sms.send')->group(function () {
            Route::get('sms', [SmsController::class, 'index']);
            Route::post('sms/preview', [SmsController::class, 'preview']);
            Route::post('sms', [SmsController::class, 'send']);
            Route::get('sms/{batch}', [SmsController::class, 'show']);
        });

        // Notifications (every staff user sees their own + broadcast ones)
        Route::get('notifications', [AlertController::class, 'index']);
        Route::get('notifications/unread-count', [AlertController::class, 'unreadCount']);
        Route::post('notifications/read-all', [AlertController::class, 'markAllRead']);
        Route::patch('notifications/{alert}', [AlertController::class, 'update']);
        Route::delete('notifications/{alert}', [AlertController::class, 'destroy']);
        Route::post('notifications', [AlertController::class, 'store'])->middleware('permission:alerts.manage');

        // Community announcements (burials, weddings, meetings…): everyone reads, alerts.manage writes
        Route::apiResource('announcements', AnnouncementController::class)->only(['index', 'show']);
        Route::apiResource('announcements', AnnouncementController::class)->only(['store', 'update', 'destroy'])
            ->middleware('permission:alerts.manage');

        // Staff accounts
        Route::middleware('permission:users.manage')->group(function () {
            Route::get('roles', [ProfileController::class, 'roles']);
            Route::get('permissions', [RoleController::class, 'permissions']);
            Route::post('roles', [RoleController::class, 'store']);
            Route::put('roles/{role}', [RoleController::class, 'update']);
            Route::delete('roles/{role}', [RoleController::class, 'destroy']);
            Route::post('positions/{position}/login', [PositionController::class, 'grantLogin']);
        });
        Route::apiResource('users', UserController::class)->middleware('permission:users.manage');
    });
});
