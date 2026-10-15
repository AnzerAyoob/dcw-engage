<?php
require_once __DIR__ . '/../includes/app_log.php';
require_once __DIR__ . '/MemberAuthModel.php';
require_once __DIR__ . '/MemberModel.php';

/**
 * DCW Engage - membership cards (the QR code a member shows).
 *
 * A card is a random 32-character token in the link /card/<token>. The token says nothing about the member;
 * it only points to a row in member_cards. A member has one live card (replaced_at IS NULL). Replacing the
 * card retires the old token, so a printed or screenshotted old code stops working at once.
 *
 * What the public check shows is decided by lookup(): first name and last initial, each chapter and whether it
 * is current. Nothing else leaves this class for the public page. "Current" uses MemberAuthModel::isActive(),
 * the same rule the rest of the app uses.
 *
 * Tables: member_cards and member_card_hits (member_cards.sql).
 */
class MemberCardModel
{
    /** Public checks allowed per connection per minute. */
    public const RATE_LIMIT = 30;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DB::getInstance()->getConnection();
    }

    // ---- Public page helpers ---------------------------------------------------------------------

    /**
     * Rate limit for the public check page. Counts requests per connection per minute.
     * The address is only stored as a hash that changes every day. If the database cannot be reached
     * this lets the request through (the lookup that follows will report the real problem).
     */
    public static function allow(): bool
    {
        try {
            $db     = DB::getInstance()->getConnection();
            $hash   = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . gmdate('Y-m-d') . '|dcw-engage-card');
            $window = gmdate('Y-m-d H:i:00');

            $db->prepare('INSERT INTO member_card_hits (ip_hash, window_start, hits) VALUES (?, ?, 1)
                          ON DUPLICATE KEY UPDATE hits = hits + 1')->execute([$hash, $window]);
            $q = $db->prepare('SELECT hits FROM member_card_hits WHERE ip_hash = ? AND window_start = ?');
            $q->execute([$hash, $window]);
            $hits = (int) $q->fetchColumn();

            if (random_int(1, 100) === 1) {   // tidy up now and then
                $db->prepare('DELETE FROM member_card_hits WHERE window_start < ?')
                   ->execute([gmdate('Y-m-d H:i:s', time() - 3600)]);
            }
            return $hits <= self::RATE_LIMIT;
        } catch (Throwable $e) {
            app_log('Card rate limit failed: ' . $e->getMessage());
            return true;
        }
    }

    /**
     * What /card/<token> shows, or null if the token is unknown or was replaced (both look the same
     * to the visitor). Shape: ['overall' => valid|expired|inactive, 'name' => 'Asha K.',
     * 'memberships' => [['name', 'state' => active|expired|inactive, 'date']]]
     */
    public function lookup(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $st = $this->db->prepare('SELECT member_id FROM member_cards WHERE token = ? AND replaced_at IS NULL');
        $st->execute([$token]);
        $memberId = $st->fetchColumn();
        if ($memberId === false) {
            return null;
        }
        $s = $this->summary((string) $memberId);
        if ($s === null) {
            return null;
        }
        return ['overall' => $s['overall'], 'name' => $s['short_name'], 'memberships' => $s['memberships']];
    }

    /** The full link a QR code should carry. Set ENGAGE_BASE_URL in config to override the default. */
    public static function url(string $token): string
    {
        $base = defined('ENGAGE_BASE_URL') ? rtrim((string) ENGAGE_BASE_URL, '/') : 'https://engage.dcwwiki.org';
        return $base . '/card/' . $token;
    }

    // ---- The member's own card -------------------------------------------------------------------

    /** The live token for a Member ID, or null if no card has been made yet. */
    public function liveToken(string $memberId): ?string
    {
        $st = $this->db->prepare('SELECT token FROM member_cards WHERE member_id = ? AND replaced_at IS NULL ORDER BY id DESC LIMIT 1');
        $st->execute([$memberId]);
        $t = $st->fetchColumn();
        return $t === false ? null : (string) $t;
    }

    /** The member's live token, creating the first card if there is none. */
    public function issue(string $memberId): string
    {
        return $this->inTransaction($memberId, function () use ($memberId): string {
            $token = $this->liveToken($memberId);
            if ($token === null) {
                $token = $this->insertCard($memberId);
                app_log("Member card issued: $memberId");
            }
            return $token;
        });
    }

    /** Retire the live card and make a new one. The old QR code stops working immediately. */
    public function replace(string $memberId, string $by): string
    {
        return $this->inTransaction($memberId, function () use ($memberId, $by): string {
            $this->db->prepare('UPDATE member_cards SET replaced_at = ? WHERE member_id = ? AND replaced_at IS NULL')
                     ->execute([gmdate('Y-m-d H:i:s'), $memberId]);
            $token = $this->insertCard($memberId);
            app_log("Member card replaced: $memberId by $by");
            return $token;
        });
    }

    /**
     * Everything the member's own card page and PDF need: ['member_id', 'full_name', 'short_name', 'overall',
     * 'memberships' => [['name', 'state', 'date']]], or null if the Member ID has no usable membership.
     */
    public function summary(string $memberId): ?array
    {
        $st = $this->db->prepare(
            "SELECT chapter, status, expires_at, full_name FROM members
             WHERE member_id = ? AND status <> 'rejected' ORDER BY id DESC"
        );
        $st->execute([$memberId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return null;
        }

        $list = [];
        foreach ($rows as $r) {
            if (MemberAuthModel::isActive($r)) {
                $state = 'active';
            } elseif ((string) $r['status'] === 'approved') {
                $state = 'expired';
            } else {
                $state = 'inactive';
            }
            $date = '';
            $exp  = substr((string) ($r['expires_at'] ?? ''), 0, 10);
            if ($exp !== '' && $exp !== '0000-00-00') {
                try {
                    $date = (new DateTimeImmutable($exp))->format('j M Y');
                } catch (Throwable $e) {
                    $date = '';
                }
            }
            $key    = trim((string) $r['chapter']);
            $list[] = [
                'name'  => MemberModel::CHAPTER_NAMES[$key] ?? ($key !== '' ? $key : 'DCW membership'),
                'state' => $state,
                'date'  => $date,
            ];
        }
        $rank = ['active' => 0, 'expired' => 1, 'inactive' => 2];
        usort($list, fn($a, $b) => $rank[$a['state']] <=> $rank[$b['state']]);

        $states  = array_column($list, 'state');
        $overall = in_array('active', $states, true) ? 'valid' : (in_array('expired', $states, true) ? 'expired' : 'inactive');

        $full = trim((string) $rows[0]['full_name']);
        return [
            'member_id'   => $memberId,
            'full_name'   => $full,
            'short_name'  => self::shortName($full),
            'overall'     => $overall,
            'memberships' => $list,
        ];
    }

    /** "Asha Kumari Singh" -> "Asha S."; one word stays as it is. */
    public static function shortName(string $full): string
    {
        $parts = preg_split('/\s+/u', trim($full), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$parts) {
            return 'Member';
        }
        if (count($parts) === 1) {
            return $parts[0];
        }
        return $parts[0] . ' ' . mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)) . '.';
    }

    // ---- Internals -------------------------------------------------------------------------------

    private function insertCard(string $memberId): string
    {
        $token = bin2hex(random_bytes(16));
        $this->db->prepare('INSERT INTO member_cards (member_id, token, created_at) VALUES (?, ?, ?)')
                 ->execute([$memberId, $token, gmdate('Y-m-d H:i:s')]);
        return $token;
    }

    /**
     * Run $work with the member's rows locked, so two requests at once cannot make two live cards.
     * Also proves the Member ID exists.
     */
    private function inTransaction(string $memberId, callable $work): string
    {
        $memberId = trim($memberId);
        if ($memberId === '') {
            throw new InvalidArgumentException('Missing Member ID.');
        }
        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare("SELECT id FROM members WHERE member_id = ? AND status <> 'rejected' FOR UPDATE");
            $lock->execute([$memberId]);
            if (!$lock->fetchAll()) {
                throw new InvalidArgumentException('No membership found for this Member ID.');
            }
            $out = $work();
            $this->db->commit();
            return $out;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
