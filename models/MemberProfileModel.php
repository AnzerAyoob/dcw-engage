<?php
require_once __DIR__ . '/../includes/app_log.php';
require_once __DIR__ . '/../includes/wiki_username.php';

/**
 * Member profile, keyed by Member ID (one person, however many clubs they belong to).
 *
 * Today it holds the Wikimedia username and whether a reviewer or coordinator has verified it.
 * Every change is written to app_log with the old and new value and who made it.
 *
 * Rules:
 *  - A username belongs to one Member ID only (unique on the lower-cased name).
 *  - Changing the username to a different account clears verification.
 *    Fixing only capitalisation or underscores keeps it.
 *  - Who may do what is decided by the pages (member: own profile; staff: chapter scope).
 *    This model only stores.
 */
class MemberProfileModel
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        // ADAPT THIS ONE LINE: use the same connection call that MemberModel's constructor uses.
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    // ---- Reading -------------------------------------------------------------------------------

    public function get(string $memberId): ?array
    {
        $st = $this->db->prepare('SELECT * FROM member_profile WHERE member_id = ?');
        $st->execute([$memberId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Profiles for many Member IDs in one query: [member_id => row]. */
    public function mapFor(array $memberIds): array
    {
        $memberIds = array_values(array_unique(array_filter(array_map('strval', $memberIds), 'strlen')));
        if (!$memberIds) return [];
        $in = implode(',', array_fill(0, count($memberIds), '?'));
        $st = $this->db->prepare("SELECT * FROM member_profile WHERE member_id IN ($in)");
        $st->execute($memberIds);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['member_id']] = $r;
        return $out;
    }

    /** The verified username for a Member ID, or '' if there is none. */
    public function verifiedUsername(string $memberId): string
    {
        $p = $this->get($memberId);
        return ($p && !empty($p['wikimedia_username']) && !empty($p['username_verified_at']))
            ? (string) $p['wikimedia_username'] : '';
    }

    // ---- Writing -------------------------------------------------------------------------------

    /** A member editing their own username. Verification is never granted here. */
    public function saveByMember(string $memberId, string $raw, string $by): void
    {
        $this->store($memberId, $raw, $by, false);
    }

    /** Staff editing a username, optionally verifying it in the same step. */
    public function saveByStaff(string $memberId, string $raw, string $by, bool $verify): void
    {
        $this->store($memberId, $raw, $by, $verify);
    }

    /** Mark the current username verified, or remove verification. */
    public function setVerified(string $memberId, string $by, bool $verified): void
    {
        $p = $this->get($memberId);
        if (!$p || empty($p['wikimedia_username'])) {
            throw new InvalidArgumentException('There is no Wikimedia username on this profile yet.');
        }
        $st = $this->db->prepare(
            'UPDATE member_profile SET username_verified_at = ?, username_verified_by = ?, updated_by = ? WHERE member_id = ?'
        );
        $st->execute([$verified ? gmdate('Y-m-d H:i:s') : null, $verified ? $by : null, $by, $memberId]);
        app_log('Member profile ' . ($verified ? 'verified' : 'unverified') . ": $memberId username '{$p['wikimedia_username']}' by $by");
    }

    private function store(string $memberId, string $raw, string $by, bool $verify): void
    {
        $memberId = trim($memberId);
        if ($memberId === '') throw new InvalidArgumentException('Missing Member ID.');

        $name = self::clean($raw);
        $key  = self::usernameKey($name);

        $st = $this->db->prepare('SELECT member_id FROM member_profile WHERE username_key = ? AND member_id <> ?');
        $st->execute([$key, $memberId]);
        if ($st->fetchColumn()) throw new InvalidArgumentException(self::TAKEN);

        $old = $this->get($memberId);
        $sameAccount = $old && ($old['username_key'] ?? '') === $key;

        if ($verify) {
            $vAt = gmdate('Y-m-d H:i:s'); $vBy = $by;
        } elseif ($sameAccount) {
            $vAt = $old['username_verified_at']; $vBy = $old['username_verified_by'];
        } else {
            $vAt = null; $vBy = null;   // new or different account: needs verifying again
        }

        try {
            if ($old) {
                $up = $this->db->prepare(
                    'UPDATE member_profile SET wikimedia_username = ?, username_key = ?, username_verified_at = ?,
                            username_verified_by = ?, updated_by = ? WHERE member_id = ?'
                );
                $up->execute([$name, $key, $vAt, $vBy, $by, $memberId]);
            } else {
                $in = $this->db->prepare(
                    'INSERT INTO member_profile (member_id, wikimedia_username, username_key, username_verified_at,
                            username_verified_by, updated_by) VALUES (?, ?, ?, ?, ?, ?)'
                );
                $in->execute([$memberId, $name, $key, $vAt, $vBy, $by]);
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') throw new InvalidArgumentException(self::TAKEN);   // lost a race
            throw $e;
        }

        app_log("Member profile username: $memberId '" . ($old['wikimedia_username'] ?? '') . "' -> '$name' by $by"
            . ($verify ? ' (verified)' : ''));
    }

    private const TAKEN = 'That Wikimedia username is already linked to another member. If it is yours, please contact DCW Support.';

    // ---- Helpers -------------------------------------------------------------------------------

    /** Normalise and validate a typed username. Throws InvalidArgumentException with a plain message. */
    public static function clean(string $raw): string
    {
        $u = trim(WikiUsername::normalize($raw));
        if ($u === '') throw new InvalidArgumentException('The username cannot be empty.');
        if (mb_strlen($u) > 255) throw new InvalidArgumentException('That username is too long.');
        if (preg_match('/^(user|user talk)\s*:/i', $u)) {
            throw new InvalidArgumentException('Enter the username only, without "User:".');
        }
        if (preg_match('/[#<>\[\]|{}\/\x00-\x1F]/u', $u)) {
            throw new InvalidArgumentException('A Wikimedia username cannot contain # < > [ ] | { } / characters.');
        }
        return $u;
    }

    /** Lower-cased, underscores as spaces: what uniqueness is checked on. */
    public static function usernameKey(string $u): string
    {
        return mb_strtolower(trim(str_replace('_', ' ', $u)), 'UTF-8');
    }

    private static function slug(string $u): string
    {
        return rawurlencode(str_replace(' ', '_', $u));
    }

    /** Global account page (home wiki, registration, wikis with edits, blocks). */
    public static function globalUrl(string $u): string
    {
        return 'https://meta.wikimedia.org/wiki/Special:CentralAuth/' . self::slug($u);
    }

    /** Contributions across all Wikimedia projects (XTools). */
    public static function contribsUrl(string $u): string
    {
        return 'https://xtools.wmcloud.org/globalcontribs/' . self::slug($u);
    }
}
