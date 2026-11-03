# Synago Backend

REST API for the Synago church management app — members, ministries, offertory, accounts, budgets,
project giving (members, families, cells, ministries, organisations, well-wishers), staff notifications and dashboard stats.

**Stack:** Laravel 12 · PHP 8.2+ · Sanctum (bearer tokens) · spatie/laravel-permission · SQLite (dev) / MySQL

## Scheduler (automatic reminders)

Reminders are created and sent by `php artisan reminders:run`, scheduled every five minutes. On the server add one cron line:

```
* * * * * cd /path/to/synago-backend && php artisan schedule:run >> /dev/null 2>&1
```

Calendar and reminder times are wall-clock times in `CHURCH_TIMEZONE` (default `Africa/Kampala`). Without cron, "Run now" on the Reminders page does the same job by hand.

## Quick start

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed      # roles, admin user, demo data (demo data skipped in production)
php artisan serve               # http://127.0.0.1:8000
php artisan test
```

Default admin: `admin@synago.test` / `password` (set `ADMIN_EMAIL` / `ADMIN_PASSWORD` before seeding).

To use MySQL (e.g. the shared `../docker-compose.yml`), set `DB_CONNECTION=mysql` and the `DB_*` vars in `.env`.

## Auth

```http
POST /api/v1/auth/login   {"email": "...", "password": "...", "deviceName": "web"}
→ {"token": "1|...", "user": {..., "roles": [...], "permissions": [...]}}
```
Send `Authorization: Bearer <token>` and `Accept: application/json` on every other request.
`GET /auth/me`, `POST /auth/logout`. Login is rate-limited to 5/min per email+IP.

## Roles & permissions

| Permission          | admin | pastor | secretary | treasurer |
|---------------------|:-----:|:------:|:---------:|:---------:|
| members.view        | ✓ | ✓ | ✓ | ✓ |
| members.manage      | ✓ | ✓ | ✓ |   |
| ministries.manage   | ✓ | ✓ | ✓ |   |
| finance.view        | ✓ | ✓ |   | ✓ |
| finance.manage      | ✓ |   |   | ✓ |
| alerts.manage       | ✓ | ✓ | ✓ |   |
| users.manage        | ✓ |   |   |   |
| settings.manage     | ✓ |   |   |   |
| welfare.manage      | ✓ | ✓ |   | ✓ |
| sacraments.override | ✓ | ✓ |   |   |
| groups.lead         | the `leader` role only (position-holders' "My groups") |
| sms.send            | ✓ | ✓ | ✓ |   |

Defined in `database/seeders/RolesAndPermissionsSeeder.php` (idempotent — re-run after edits:
`php artisan db:seed --class=RolesAndPermissionsSeeder`).

## Endpoints (`/api/v1`)

| Resource | Routes | Notes |
|---|---|---|
| Dashboard | `GET dashboard` | `DashboardStats` shape |
| Search | `GET search?q=&limit=5` | grouped hits `{type, id, title, subtitle, status}` across members (incl. pledge no.), visitors, ministries, cell groups, baptisms, marriages, pledges, welfare, announcements, staff. A group is only searched if the user has its permission; min 2 chars; 120 req/min |
| Members | `apiResource members` | filters: `search` (name, email, phone, pledge no.), `status, gender, sundaySchool, ministryId`; soft-deleted; `pledgeNumber` auto-assigned as `{pledgePrefix}-0001` |
| Ministries | `apiResource ministries`, `POST/DELETE ministries/{id}/members[/{member}]`, `POST/DELETE ministries/{id}/activities[/{activity}]` | |
| Offertory | `apiResource offertory`, `GET offertory/summary` | filters: `category, memberId, from, to` |
| Accounts | `apiResource accounts`, `GET accounts/summary`, `GET accounts/trends?months=6` | income/expense ledger; filters: `type, category, from, to` |
| Budgets | `apiResource budgets` | `spent`/`status` derived from expense transactions in the same category + month; filter `period=YYYY-MM` |
| Notifications | `GET notifications`, `GET notifications/unread-count`, `POST notifications/read-all`, `PATCH/DELETE notifications/{id}`, `POST notifications` | per-user + broadcast |
| Users | `apiResource users`, `GET roles` | staff accounts and the role → permission catalogue (admin only) |
| Profile | `PATCH auth/me`, `PUT auth/password` | own name/email/phone; password change needs `currentPassword` + `password_confirmation`, signs out other devices |
| Settings | `GET settings`, `PUT settings` | `churchName, denomination (anglican\|pentecostal\|other), diocese, address, pledgePrefix, services[]`; defaults in `config/church.php`; write needs `settings.manage` |
| Baptisms | `apiResource baptisms`, `GET baptisms/stats` | register of baptism / confirmation / dedication; `status` scheduled → completed issues `BAP-2026-0001` style numbers; filters `kind, status, year, memberId, search`; read `members.view`, write `members.manage` |
| Offertory by service | `GET offertory/day?date=`, `GET offertory/days?limit=` | per-service × category totals and the day total; offertory records take `service` (filter `?service=`) |
| Tithe report | `GET reports/tithes?year=` | rows per tither with 12 monthly totals, month totals, grand total (`finance.view`) |
| Attendance | `GET attendance/day?date=`, `PUT attendance/day`, `GET attendance/trends?weeks=` | head counts (men/women/children/visitors) per service; a whole day is saved at once, all-zero rows are cleared. Feeds the dashboard `attendanceRate` (avg adults over the last 4 recorded days ÷ active members) |
| Marriages | `apiResource marriages`, `GET marriages/stats` | planned weddings (banns 0–3, counselling) → completed issues `MAR-2026-0001`; groom/bride can be members or free names |
| Pledges | `apiResource pledges`, `GET pledges/stats`, `POST pledges/{id}/payments` | a payment creates an offertory record (category `pledge`, `pledge_id` set), so offertory totals stay correct; status `open/overdue/fulfilled` is derived (`finance.view` / `finance.manage`) |
| Visitors | `apiResource visitors`, `GET visitors/stats`, `POST visitors/{id}/follow-ups`, `POST visitors/{id}/convert` | follow-up checklist; logging a call/visit/SMS ticks the matching step; convert creates a member |
| Welfare | `apiResource welfare` (no show), `GET welfare/stats`, `POST welfare/{id}/decision`, `POST welfare/{id}/pay` | pending → approved (amount) / declined → paid. Paying posts an expense in ledger category **Welfare**; fund balance = Welfare income − Welfare expenses. `welfare.manage`; paying also needs `finance.manage` |
| Cell groups | `apiResource cell-groups`, `POST cell-groups/from-villages`, `POST/DELETE cell-groups/{id}/members[/{member}]` | members have one `cell_group_id`; from-villages creates "{Village} Cell" groups and places unassigned active members |
| Bulk SMS | `GET sms`, `POST sms/preview`, `POST sms`, `GET sms/{id}` | audiences: `all, ministry, cell_group, gender, birthdays, visitors, members, numbers`; `{name}` / `{church}` placeholders; numbers normalised to E.164 with `SMS_COUNTRY_CODE`. Driver via `SMS_DRIVER` (`log` default, `africastalking`) |
| Areas | `GET/POST/PATCH/DELETE areas`, `POST areas/from-members` | archdeaconry > parish > region > village; a parent must be a higher level |
| Harvest | `GET reports/harvest?year=` | cells (gift's `cellGroupId` or giver's cell), rolled up by area level, top givers, unassigned |
| Sunday school | `GET/POST sunday-school/classes`, `GET/PATCH/DELETE sunday-school/classes/{id}`, `GET/PUT …/{id}/attendance`, `POST sunday-school/auto-assign` | members ≤ 12 auto-enrol on save; class teachers (positions) may read and save their own register |
| Requirements | `PUT baptisms/{id}/requirements/{key}` | checklist from settings `sacramentRequirements`; completing needs all met or `overrideReason` + `sacraments.override` |
| Pastoral care | `care/stats`, `care/cases[/{id}]`, `care/visits[/{id}]`, `POST members/{id}/death` | death marks member `deceased` and optionally creates announcement, welfare case and next-of-kin SMS |
| Projects | `GET projects?open=1`, `GET projects/stats`, `POST projects`, `GET/PATCH/DELETE projects/{id}`, `POST projects/{id}/transactions` | `goal` = target or budget; `raised` = cash contributions + other tagged income; `pledgeBalance` = promised but unpaid; delete refused (422) once it has contributions |
| Project giving | `GET/POST projects/{id}/contributions`, `PATCH/DELETE contributions/{id}`, `POST contributions/{id}/thank`, `GET projects/{id}/summary`, `PUT projects/{id}/targets`, `POST projects/{id}/milestones`, `PATCH/DELETE milestones/{id}` | kinds: gift → income transaction, thanksgiving / pledge_payment → offertory, in_kind → value only (never cash). Contributor: member, household, cell_group, ministry, organisation, named, anonymous. `hideName` shows as “Well-wisher”. Receipts `CTB-ymd-XXXX`; optional thank-you SMS |
| Accounting | `GET accounting/trial-balance?asOf=`, `GET accounting/income-statement?from=&to=`, `GET accounting/balance-sheet?asOf=`, `GET accounting/ledger/{account}?from=&to=`, `GET/POST accounting/accounts`, `PATCH/DELETE accounting/accounts/{id}`, `GET/PUT accounting/mappings`, `GET/POST accounting/journals`, `PATCH/DELETE accounting/journals/{id}`, `GET accounting/funds` | double-entry ledger derived from offertory + transactions (via mappings: category → income/expense account, payment method → cash/bank/mobile money) plus manual journals (must balance). Each posting carries a fund (general, welfare, or a project), giving restricted funds on the balance sheet. Income and expenses reset each financial year (`financialYearStartMonth` setting, default January), and earlier surplus rolls into the fund accounts. In-kind gifts stay off the books. Read: finance.view, write: finance.manage |
| Families | `GET households?search=`, `GET households/{id}`, `POST/PATCH/DELETE households`, `POST households/{id}/members`, `DELETE households/{id}/members/{member}` | read: members.view, write: members.manage; members carry `householdId` |
| Organisations | `GET organisations`, `POST/PATCH/DELETE organisations/{id}` | companies, NGOs, churches… that give; delete refused once they have contributions |
| Outreach | `GET/POST outreach`, `PATCH/DELETE outreach/{id}` | `recordExpense` posts/updates one "Outreach" expense |
| Positions | `GET positions`, `GET positions/scopes`, `POST/PATCH/DELETE positions/{id}`, `POST positions/{id}/login` | scope: church, ministry, cell_group, sunday_school_class, project; login → `leader` role linked to the member |
| My groups | `GET me/groups` | the signed-in leader's current groups with people |
| Roles | `GET permissions`, `POST roles`, `PUT/DELETE roles/{name}` | admin locked; built-ins can't be deleted |
| Calendar | `GET calendar?from=&to=&kinds=` (everything on the calendar, scoped to the user, plus church-year seasons & feasts), `GET calendar/church-year?year=`, `GET/POST calendar/feed-link`, public `GET calendar/feed/{token}.ics` | items come from events, services (settings `serviceTimes`), cell & ministry meeting days, activities, dated announcements, planned baptisms/weddings, birthdays, pledge due dates, visit follow-ups, outreach |
| Events | `POST events`, `GET/PATCH/DELETE events/{id}` | repeats: `weekly`, `monthly_nth` (e.g. first Sunday), `monthly_day`, `yearly`; same venue + overlapping time → **409** with `clashes` unless `ignoreClash`; `remindMinutes`/`remindAudience`/`remindChannel` (`alerts.manage`) |
| Church year | `GET/POST observances`, `PATCH/DELETE observances/{id}` | Anglican seasons and principal feasts computed from Easter (incl. Uganda Martyrs Day); custom observances by fixed date or Easter offset |
| Reminders | `GET reminders?status=pending\|done`, `POST reminders`, `POST reminders/{id}/cancel`, `GET reminder-rules`, `PATCH reminder-rules/{key}`, `POST reminders/run` | rules: `service_eve, birthdays, group_meetings, pledge_due, follow_ups, week_ahead` (off by default). Audiences: all, staff, leaders, ministry, cell_group, members, users. SMS waits out quiet hours and stops at `smsMonthlyBudget`; each automatic reminder is created once (`dedupe_key`). Personal reminders (in-app) for everyone; the rest needs `sms.send` |
| Sermons | `GET sermons?status=planned\|preached&search=&book=&seriesId=&year=&date=`, `GET sermons/stats`, `POST sermons`, `GET/PATCH/DELETE sermons/{id}`, `GET/POST sermons/series`, `PATCH/DELETE sermons/series/{id}`, `GET scripture/parse?ref=` | preaching rota (planned) and service register (preached); `scriptures` are the passages the preacher used, typed loosely and stored normalised ("Jn 15 1-8" → "John 15:1–8"); stats give rota gaps (next 4 Sundays × services) and book coverage; detail includes that service's attendance and offertory; `preacher_rota` reminder rule texts the preacher N days before |
| Giving statements | `GET reports/statement?memberId=&year=`, `GET reports/statements?year=` | per-member year totals by category, gifts, project contributions (with in-kind value), pledges |
| Announcements | `apiResource announcements` | community notices (`burial, wedding, introduction, thanksgiving, fundraising, meeting, general`); `?when=upcoming\|past\|all&type=&search=`; all staff read, `alerts.manage` writes |

### Conventions
- JSON keys are **camelCase** both ways, matching the types in `v0-church-management-dashboard/lib/types.ts`. IDs are strings.
- Single items: `{"data": {...}}`. Lists are paginated: `{"data": [...], "links": {...}, "meta": {...}}` with `?page=&perPage=` (max 100).
- Every list and report with dates takes `?from=YYYY-MM-DD&to=YYYY-MM-DD` (either optional): offertory, accounts, pledges, contributions, journals, welfare, outreach, SMS, visitors, baptisms, marriages, pastoral cases/visits, attendance trends, and the tithe, harvest and giving-statement reports (which still accept `?year=`).
- `PUT/PATCH` are partial updates. `reference` on offertory/transactions/contributions is auto-generated if omitted (e.g. `TH-20261102-7K3QF`).
- Errors: `401 {"message"}`, `403`, `404 {"message": "Resource not found."}`, `422 {"message", "errors": {field: [...]}}`.

## Frontend

The admin dashboard lives in `../synago-ui` (Next.js) — see its README.

## Not yet built
- Attendance tracking and Sunday School classes (`attendanceRate`, `sundaySchoolClasses` return `null`)
- Multi-church tenancy (currently a single church per deployment)
- Broadcast notifications share read/archived state across staff (per-user state would need a pivot table)
- Payments integration (e.g. Mobile Money), SMS/email, audit log, file uploads (member photos)

### Upgrading from campaigns to project giving

Migration `2026_11_08_100000_project_contributions` turns every old fundraising campaign into a project
(open for contributions), each donation into a contribution plus its income transaction, and links
campaign pledges and their payments to the project. The old `campaigns`/`donations` tables are kept
for history but no longer used. Run `php artisan migrate` (no fresh) after backing up the database.

"Not in the future" date checks use the church's local date (`CHURCH_TIMEZONE`, default Africa/Kampala),
so entries made just after midnight are accepted even though the server runs in UTC.

### Accounting

Migration `2026_11_09_100000_create_general_ledger` installs a standard church chart of accounts (1xxx assets … 5xxx expenses)
and posting rules for every existing offertory category, Accounts category and payment method, so the trial balance,
income statement and balance sheet work immediately over all history. Record the church's opening balances
(cash, bank, land and buildings, loans) as an *opening* journal to complete the balance sheet. Unmapped categories post
to Other income / Other expenses / Cash at hand and are flagged on every statement.

### Multi-church (SaaS)

Many churches share one installation and one database. **`church_id` is the key**: every church-owned
table carries it, and `App\Models\Concerns\BelongsToChurch` filters every query to the signed-in user's
church and stamps new rows with it. The `church` middleware sets the current church (`App\Support\Tenant`)
from the user on each request. `exists:`/`unique:` validation is church-aware (`TenantPresenceVerifier`), so
pledge numbers, receipts and certificate numbers are unique *within* a church. Roles are per church (spatie
"teams", team key `church_id`). User emails are unique across the platform: one login = one church.

| Area | Endpoints | Notes |
|---|---|---|
| Sign-up | `POST auth/register`, `GET auth/verify/{id}/{hash}` (signed link), `POST auth/verify/resend` | creates the church (30-day trial), its roles, chart of accounts, starter settings and first admin; sends an email-verification link that redirects to `FRONTEND_URL` |
| Own church | `GET church`, `POST church/onboarding` | `auth/me` returns `church` (status, trial days left, onboarded), `emailVerified`, `platformAdmin` |
| Platform console | `GET platform/stats`, `GET platform/churches?status=&search=`, `GET/PATCH platform/churches/{id}` (`status`, `plan`, `trialEndsAt`) | platform admins only (`is_platform_admin`, no church). Create one with `php artisan synago:platform-admin you@example.com` |

- Suspended churches can't sign in, and their API calls answer 403 `church_suspended`. Reminders (`reminders:run`) loop over every church that isn't suspended.
- Migration `2026_11_10_100000_multi_church` turns an existing single-church install into church #1 with all its data, roles and settings. It is one-way, so back up first.
- Set `FRONTEND_URL` (verification redirects) and a real `MAIL_MAILER` in production. In dev, mail goes to the log.
- Dev seed: church "Saint James Wigua" (`admin@synago.test` / `password`) and platform owner `platform@synago.test` / `password`.

### Dashboard

| Endpoint | Notes |
|---|---|
| `GET dashboard` | headline figures. People figures need `members.view` and money figures `finance.view`; the rest come back as `null` |
| `GET dashboard/attention` | "Needs attention": `{key, area, severity, title, detail, count, href}` for things the user can **act on**. Visitors, follow-ups, candidates, attendance and preachers need `members.manage`; welfare needs `welfare.manage`; missing offertory and unlinked categories need `finance.manage`; overdue pledges, budget and ledger warnings need `finance.view`; reminders and SMS budget need `sms.send`; unconfirmed staff need `users.manage`. Zero counts are left out |
| `GET dashboard/sunday` | the latest Sunday per service (attendance and giving) vs the week before and the same Sunday last year, each part only for roles that may see it |

