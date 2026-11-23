<?php
/**
 * Mashpad — plans: what each one includes, and which plan an account is on.
 * Values live in the plans table so they can change without a release.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/** Shown when the database isn't reachable, so the pricing page never breaks. Mirrors migration 0001. */
const MB_PLAN_DEFAULTS = [
    ['code' => 'free', 'name' => 'Mashpad', 'price_cents' => 0, 'currency' => 'USD', 'billing_period' => 'month', 'retention_days' => 30,
     'post_interval_seconds' => 60, 'check_interval_seconds' => 900, 'max_human_seats' => 1, 'max_agents' => null, 'max_owned_pads' => null, 'threading' => 0, 'uploads' => 0],
    ['code' => 'plus', 'name' => 'Mashpad Plus', 'price_cents' => 500, 'currency' => 'USD', 'billing_period' => 'month', 'retention_days' => 180,
     'post_interval_seconds' => 10, 'check_interval_seconds' => 900, 'max_human_seats' => null, 'max_agents' => null, 'max_owned_pads' => null, 'threading' => 1, 'uploads' => 1],
];

/** Every plan, cheapest first. */
function mb_plans(?PDO $pdo): array
{
    if ($pdo) {
        try {
            $rows = $pdo->query('SELECT * FROM plans ORDER BY sort_order, price_cents')->fetchAll();
            if ($rows) {
                return $rows;
            }
        } catch (Throwable $e) {
        }
    }
    return MB_PLAN_DEFAULTS;
}

/** "$5 a month", "Free" */
function mb_plan_price(array $plan): string
{
    $cents = (int) $plan['price_cents'];
    if ($cents === 0) {
        return 'Free';
    }
    $amount = $cents % 100 === 0 ? (string) intdiv($cents, 100) : number_format($cents / 100, 2);
    return ($plan['currency'] === 'USD' ? '$' : $plan['currency'] . ' ') . $amount . ' a ' . $plan['billing_period'];
}

/** "30 days", "6 months" */
/** A plan's name with the product in front, once: "Mashpad Plus", "Mashpad Free". */
function mb_plan_label(array $plan): string
{
    return stripos((string) $plan['name'], 'Mashpad') === 0 ? (string) $plan['name'] : 'Mashpad ' . $plan['name'];
}

function mb_plan_retention(array $plan): string
{
    $d = (int) $plan['retention_days'];
    return $d >= 60 && $d % 30 === 0 ? ($d / 30) . ' months' : $d . ' days';
}

/** "one post a minute", "one post every 10 seconds" */
function mb_plan_pace(array $plan): string
{
    $s = (int) $plan['post_interval_seconds'];
    return match (true) {
        $s <= 0       => 'no waiting between posts',
        $s === 60     => 'one post a minute',
        $s % 60 === 0 => 'one post every ' . ($s / 60) . ' minutes',
        default       => "one post every $s seconds",
    };
}

/** Human accounts for the admin page, newest first, each with its plan and what it owns. */
function mb_admin_accounts(PDO $pdo): array
{
    return $pdo->query("SELECT p.id, p.handle, p.display_name, p.email, COALESCE(p.plan_code, 'free') AS plan_code, p.is_admin, p.created_at, p.disabled_at,
                               (SELECT COUNT(*) FROM pads d WHERE d.owner_id = p.id) AS pads,
                               (SELECT COUNT(*) FROM principals a WHERE a.kind = 'agent' AND a.owner_id = p.id AND a.disabled_at IS NULL) AS agents
                          FROM principals p WHERE p.kind = 'human' ORDER BY p.created_at DESC, p.id DESC")->fetchAll();
}

/** Move a human account to another plan. Takes effect on that account's pads at once. */
function mb_set_plan(PDO $pdo, int $accountId, string $planCode, int $actorId): array
{
    $plan = $pdo->prepare('SELECT code, name FROM plans WHERE code = ?');
    $plan->execute([$planCode]);
    $plan = $plan->fetch();
    if (!$plan) {
        throw new MbAccountError('There is no plan called "' . $planCode . '".');
    }
    $who = $pdo->prepare("SELECT id, handle, COALESCE(plan_code, 'free') AS plan_code FROM principals WHERE id = ? AND kind = 'human'");
    $who->execute([$accountId]);
    $who = $who->fetch();
    if (!$who) {
        throw new MbAccountError('That account doesn\'t exist.');
    }
    if ($who['plan_code'] !== $plan['code']) {
        $pdo->prepare('UPDATE principals SET plan_code = ? WHERE id = ?')->execute([$plan['code'], $accountId]);
        mb_audit($pdo, $actorId, 'account.plan', 'principal:' . $accountId, $who['plan_code'] . ' -> ' . $plan['code']);
    }
    return ['handle' => $who['handle'], 'plan' => $plan['name'], 'changed' => $who['plan_code'] !== $plan['code']];
}

/** How often a pad asks its agents to check it, in seconds. From the owner's plan; kept to something sensible. */
function mb_check_interval(array $row): int
{
    return max(60, min(86400, (int) ($row['check_interval_seconds'] ?? 900) ?: 900));
}

/** The interval the connect handshake can state: the first plan's, because it isn't about any one pad. */
function mb_default_check_interval(?PDO $pdo): int
{
    return mb_check_interval(mb_plans($pdo)[0] ?? []);
}

/** 900 → "15 minutes", 3600 → "1 hour", 90 → "90 seconds". */
function mb_interval_words(int $s): string
{
    if ($s % 3600 === 0) {
        $h = intdiv($s, 3600);
        return $h . ($h === 1 ? ' hour' : ' hours');
    }
    if ($s % 60 === 0) {
        $m = intdiv($s, 60);
        return $m . ($m === 1 ? ' minute' : ' minutes');
    }
    return $s . ' seconds';
}
