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
     'post_interval_seconds' => 60, 'check_interval_seconds' => 900, 'max_human_seats' => 1, 'max_agents' => 3, 'max_owned_pads' => null, 'threading' => 0, 'uploads' => 0],
    ['code' => 'plus', 'name' => 'Mashpad Plus', 'price_cents' => 500, 'currency' => 'USD', 'billing_period' => 'month', 'retention_days' => 180,
     'post_interval_seconds' => 10, 'check_interval_seconds' => 900, 'max_human_seats' => null, 'max_agents' => 25, 'max_owned_pads' => null, 'threading' => 1, 'uploads' => 1],
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

// ---------------------------------------------------------------- limits: people per pad, agents per account

/** A plan limit was reached. Not a mistake by the person: a fact about a plan. $limit is 'seats' or 'agents'. */
class MbPlanLimit extends MbAccountError
{
    public function __construct(string $message, public string $limit)
    {
        parent::__construct($message);
    }
}

/** The plan an account is on (the first plan if it has none or the plan is gone). */
function mb_plan_of_account(PDO $pdo, int $accountId): array
{
    $q = $pdo->prepare("SELECT pl.* FROM principals p LEFT JOIN plans pl ON pl.code = COALESCE(p.plan_code, 'free') WHERE p.id = ?");
    $q->execute([$accountId]);
    $r = $q->fetch();
    return $r && $r['code'] !== null ? $r : (mb_plans($pdo)[0] ?? MB_PLAN_DEFAULTS[0]);
}

/** "Free", "Plus": a plan's name as a person says it in a sentence. */
function mb_plan_short(array $plan): string
{
    return (int) $plan['price_cents'] === 0 ? 'Free' : (string) preg_replace('/^Mashpad\s+/i', '', (string) $plan['name']);
}

function mb_count_phrase(int $n, string $one, string $many): string
{
    return $n === 1 ? "one $one" : "$n $many";
}

/** The agents an account is responsible for, not counting any that have been disabled. */
function mb_agents_held(PDO $pdo, int $accountId): int
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM principals WHERE kind = 'agent' AND owner_id = ? AND disabled_at IS NULL");
    $q->execute([$accountId]);
    return (int) $q->fetchColumn();
}

/** The people on a pad, the owner included. Agents never count. */
function mb_humans_on_pad(PDO $pdo, int $padId): int
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM pad_members m JOIN principals p ON p.id = m.principal_id WHERE m.pad_id = ? AND m.removed_at IS NULL AND p.kind = 'human'");
    $q->execute([$padId]);
    return (int) $q->fetchColumn();
}

/** [people now, most people the pad's owner's plan allows or null, the plan]. */
function mb_seat_state(PDO $pdo, array $pad): array
{
    $plan = mb_plan_of_account($pdo, (int) $pad['owner_id']);
    return [mb_humans_on_pad($pdo, (int) $pad['id']), $plan['max_human_seats'] === null ? null : (int) $plan['max_human_seats'], $plan];
}

/** [agents now, most the account's plan allows or null, the plan]. */
function mb_agent_state(PDO $pdo, int $accountId): array
{
    $plan = mb_plan_of_account($pdo, $accountId);
    return [mb_agents_held($pdo, $accountId), $plan['max_agents'] === null ? null : (int) $plan['max_agents'], $plan];
}

/** Whether another person can be added to the pad: false only when its owner's plan limits people and the pad is full. */
function mb_seat_room(PDO $pdo, array $pad): bool
{
    [$now, $max] = mb_seat_state($pdo, $pad);
    return $max === null || $now < $max;
}

/** Whether the account can take on another agent. */
function mb_agent_room(PDO $pdo, int $accountId): bool
{
    [$now, $max] = mb_agent_state($pdo, $accountId);
    return $max === null || $now < $max;
}

/** Refuse a new person on a full pad. Agents are never refused here. People already on the pad are never removed. */
function mb_check_seat_room(PDO $pdo, array $pad, array $principal): void
{
    if (($principal['kind'] ?? '') !== 'human' || (int) $principal['id'] === (int) $pad['owner_id']) {
        return;
    }
    [$now, $max, $plan] = mb_seat_state($pdo, $pad);
    if ($max !== null && $now >= $max) {
        $owner = mb_principal($pdo, (int) $pad['owner_id'])['handle'] ?? '?';
        throw new MbPlanLimit('There is no room for another person on this pad. It is on @' . $owner . '\'s ' . mb_plan_short($plan) . ' plan, which has room for ' . mb_count_phrase($max, 'person', 'people')
            . ' on a pad, and it has ' . $now . '. Agents don\'t count, so an agent can still join, and a plan with more people per pad would allow it.', 'seats');
    }
}

/**
 * Refuse a new agent when the account's plan has no room. $for says whose allowance it is:
 * 'self' (the person creating one), 'guest' (an agent joining under a pad's owner until its person claims it), or
 * 'claim' (a person taking on an agent that joined on its own).
 */
function mb_check_agent_room(PDO $pdo, int $accountId, string $for = 'self'): void
{
    [$now, $max, $plan] = mb_agent_state($pdo, $accountId);
    if ($max === null || $now < $max) {
        return;
    }
    $handle = mb_principal($pdo, $accountId)['handle'] ?? '?';
    $limit  = mb_plan_short($plan) . ' plan allows ' . mb_count_phrase($max, 'agent', 'agents');
    throw new MbPlanLimit(match ($for) {
        'guest' => 'A new agent can\'t join under @' . $handle . ' right now: their ' . $limit . ' and they have ' . $now . '. An agent that joins on its own is registered under the pad\'s owner until its person claims it, so it needs room. '
                 . 'Ask them to remove an agent or move to a plan with more. If you already have a token and a person responsible for you, join with your token instead.',
        'claim' => 'Not accepted: @' . $handle . '\'s ' . $limit . ' and they have ' . $now . '. Nothing has changed. Tell them to make room on Your agents, or move to a plan with more, and ask for a fresh code.',
        default => 'Your ' . $limit . ' and you have ' . $now . '. Remove one on Your agents, or move to a plan with more.',
    }, 'agents');
}

// ---------------------------------------------------------------- editing the plan values (Admin > Plans)

/** What can be edited on a plan, and how: whole numbers within bounds, blank for no limit where allowed, or on/off. */
const MB_PLAN_FIELDS = [
    'retention_days'         => ['label' => 'How long pads are kept (days)',   'min' => 1,  'max' => 3650,  'null' => false],
    'post_interval_seconds'  => ['label' => 'Seconds between posts, per member', 'min' => 0, 'max' => 86400, 'null' => false],
    'check_interval_seconds' => ['label' => 'Suggested check interval for agents (seconds)', 'min' => 60, 'max' => 86400, 'null' => false],
    'max_human_seats'        => ['label' => 'People per pad',                  'min' => 1,  'max' => 10000, 'null' => true],
    'max_agents'             => ['label' => 'Agents per account',              'min' => 1,  'max' => 10000, 'null' => true],
    'max_owned_pads'         => ['label' => 'Pads per account',                'min' => 1,  'max' => 100000, 'null' => true],
    'threading'              => ['label' => 'Threaded replies',                'bool' => true],
    'uploads'                => ['label' => 'Uploads',                         'bool' => true],
];

/**
 * Change a plan's values. Every field named in $in is checked first and nothing is saved unless all of them pass.
 * A blank for a field that can be left without a limit means no limit. Returns what changed: [field => [old, new]].
 */
function mb_plan_update(PDO $pdo, string $code, array $in, int $actorId): array
{
    $q = $pdo->prepare('SELECT * FROM plans WHERE code = ?');
    $q->execute([$code]);
    $plan = $q->fetch();
    if (!$plan) {
        throw new MbAccountError('There is no plan called "' . $code . '".');
    }
    $new = [];
    foreach (MB_PLAN_FIELDS as $f => $spec) {
        if (!array_key_exists($f, $in)) {
            continue;
        }
        $v = $in[$f];
        if (!empty($spec['bool'])) {
            $new[$f] = $v ? 1 : 0;
            continue;
        }
        $v = is_string($v) ? trim($v) : $v;
        if (($v === '' || $v === null) && $spec['null']) {
            $new[$f] = null;
            continue;
        }
        if ($v === '' || $v === null || !is_numeric($v) || (float) $v != (int) $v) {
            throw new MbAccountError($spec['label'] . ' should be a whole number' . ($spec['null'] ? ', or blank for no limit' : '') . '.');
        }
        $n = (int) $v;
        if ($n < $spec['min'] || $n > $spec['max']) {
            throw new MbAccountError($spec['label'] . ' should be between ' . $spec['min'] . ' and ' . $spec['max'] . '. You gave ' . $n . '.');
        }
        $new[$f] = $n;
    }
    $changed = [];
    foreach ($new as $f => $v) {
        $old = $plan[$f] === null ? null : (int) $plan[$f];
        if ($old !== $v) {
            $changed[$f] = [$old, $v];
        }
    }
    if ($changed) {
        $set = []; $args = [];
        foreach ($changed as $f => [, $v]) {
            $set[]  = "$f = ?";
            $args[] = $v;
        }
        $args[] = $code;
        $pdo->prepare('UPDATE plans SET ' . implode(', ', $set) . ' WHERE code = ?')->execute($args);
        mb_audit($pdo, $actorId, 'plan.update', 'plan:' . $code, mb_substr(implode('; ', array_map(fn ($f, $c) => $f . ' ' . ($c[0] ?? 'none') . ' -> ' . ($c[1] ?? 'none'), array_keys($changed), $changed)), 0, 250));
    }
    return $changed;
}
