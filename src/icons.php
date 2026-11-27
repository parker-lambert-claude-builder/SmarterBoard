<?php
/**
 * Mashpad — agent icons.
 *
 * The library is the .webp files in assets/agent-icons/, each 100 x 100, named like orange-visor.webp. Adding an icon is adding
 * a file. An agent's icon is stored as the file's name without ".webp" (agent_profiles.icon), and is assigned, not chosen:
 * the one the account's other active agents use least, the earliest in the library on a tie. So no two of an account's agents
 * share an icon while the library has enough, and when it runs out the icons are shared as evenly as can be. Removing an agent
 * frees its icon. An agent with none yet gets one the first time it is shown. Later a person may pick; the column allows it.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const MB_ICON_DIR = __DIR__ . '/../assets/agent-icons';

/** The icon keys, in order. */
function mb_agent_icon_library(): array
{
    static $lib = null;
    if ($lib === null) {
        $lib = [];
        foreach (glob(MB_ICON_DIR . '/*.webp') ?: [] as $f) {
            $lib[] = basename($f, '.webp');
        }
        sort($lib);
    }
    return $lib;
}

/** The address of an icon, changing when the file does, so a replaced icon shows at once. */
function mb_agent_icon_url(string $key): string
{
    $f = MB_ICON_DIR . '/' . $key . '.webp';
    return mb_base_path() . 'assets/agent-icons/' . rawurlencode($key) . '.webp' . (is_file($f) ? '?v=' . substr(hash_file('sha256', $f), 0, 10) : '');
}

/** The icon for the account's next agent: the one its other active agents use least, the earliest on a tie. '' if there is no library. */
function mb_agent_icon_pick(PDO $pdo, int $ownerId, int $exceptAgentId = 0): string
{
    $lib = mb_agent_icon_library();
    if (!$lib) {
        return '';
    }
    $q = $pdo->prepare("SELECT a.icon, COUNT(*) FROM principals p JOIN agent_profiles a ON a.principal_id = p.id
                         WHERE p.kind = 'agent' AND p.owner_id = ? AND p.disabled_at IS NULL AND p.id <> ? AND a.icon IS NOT NULL GROUP BY a.icon");
    $q->execute([$ownerId, $exceptAgentId]);
    $used = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    $best = null;
    $bestN = PHP_INT_MAX;
    foreach ($lib as $key) {
        $n = (int) ($used[$key] ?? 0);
        if ($n < $bestN) {
            $best = $key;
            $bestN = $n;
        }
    }
    return (string) $best;
}

/** Give an agent an icon and keep it. Returns the key. */
function mb_agent_icon_assign(PDO $pdo, int $agentId): string
{
    $o = $pdo->prepare('SELECT owner_id FROM principals WHERE id = ?');
    $o->execute([$agentId]);
    $key = mb_agent_icon_pick($pdo, (int) $o->fetchColumn(), $agentId);
    $pdo->prepare('UPDATE agent_profiles SET icon = ? WHERE principal_id = ?')->execute([$key !== '' ? $key : null, $agentId]);
    return $key;
}

/** An agent's icon key, assigning one if it has none (or its file has gone). */
function mb_agent_icon(PDO $pdo, array $agent): string
{
    $key = (string) ($agent['icon'] ?? '');
    return $key !== '' && in_array($key, mb_agent_icon_library(), true) ? $key : mb_agent_icon_assign($pdo, (int) $agent['id']);
}

/** After an agent moves to another account: keep its icon if that account's other agents don't use it, else pick another. */
function mb_agent_icon_settle(PDO $pdo, int $agentId): void
{
    $q = $pdo->prepare('SELECT p.owner_id, a.icon FROM principals p JOIN agent_profiles a ON a.principal_id = p.id WHERE p.id = ?');
    $q->execute([$agentId]);
    $r = $q->fetch();
    if (!$r) {
        return;
    }
    $taken = $pdo->prepare("SELECT 1 FROM principals p JOIN agent_profiles a ON a.principal_id = p.id WHERE p.kind = 'agent' AND p.owner_id = ? AND p.disabled_at IS NULL AND p.id <> ? AND a.icon = ?");
    $taken->execute([$r['owner_id'], $agentId, $r['icon']]);
    if ($r['icon'] === null || $taken->fetchColumn()) {
        mb_agent_icon_assign($pdo, $agentId);
    }
}

/** An <img> for an agent's icon at $size pixels. Decorative: the agent's name is beside it. */
function mb_agent_icon_tag(PDO $pdo, array $agent, int $size = 40, string $class = 'agent-icon'): string
{
    $key = mb_agent_icon($pdo, $agent);
    return $key === '' ? '' : '<img class="' . htmlspecialchars($class, ENT_QUOTES) . '" src="' . htmlspecialchars(mb_agent_icon_url($key), ENT_QUOTES) . '" alt="" width="' . $size . '" height="' . $size . '">';
}
