<?php
require_once __DIR__ . '/../includes/wiki_username.php';
require_once __DIR__ . '/MemberModel.php';

/**
 * Conversation Hour attendance, the technical contributors list, and the
 * eligibility check for internet support.
 *
 * Attendance rows are matched to a member at CHECK time: by Member ID, or by the member's
 * VERIFIED Wikimedia username. Unverified usernames never match, so nobody can claim
 * someone else's attendance by typing their username into a profile.
 */
class CommunityRecordsModel
{
    const WINDOW = 3;   // how many recent sessions must be attended

    private PDO $db;

    public function __construct(?PDO $pdo = null)
    {
        $this->db = $pdo ?? self::connect();
    }

    private static function connect(): PDO
    {
        return DB::getInstance()->getConnection();   // same as InternetSupportModel
    }

    // ------------------------------------------------------------------
    // Sessions
    // ------------------------------------------------------------------

    public function cleanTitle(string $t): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $t));
        if ($t === '' || mb_strlen($t) > 255) throw new InvalidArgumentException('Give the session a title (up to 255 characters).');
        return $t;
    }

    public function cleanDate(string $d): string
    {
        $d = trim($d);
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d, new DateTimeZone('Asia/Kolkata'));
        if (!$dt || $dt->format('Y-m-d') !== $d) throw new InvalidArgumentException('Enter the date the session was held.');
        if ($dt > new DateTimeImmutable('today', new DateTimeZone('Asia/Kolkata'))) throw new InvalidArgumentException('A session cannot be in the future.');
        return $d;
    }

    public function createSession(string $title, string $date, string $by): int
    {
        $st = $this->db->prepare('INSERT INTO ch_sessions (title, held_on, created_by) VALUES (?,?,?)');
        $st->execute([$this->cleanTitle($title), $this->cleanDate($date), $by]);
        return (int) $this->db->lastInsertId();
    }

    public function updateSession(int $id, string $title, string $date, string $by): void
    {
        $st = $this->db->prepare('UPDATE ch_sessions SET title=?, held_on=?, edited_by=?, edited_at=NOW() WHERE id=?');
        $st->execute([$this->cleanTitle($title), $this->cleanDate($date), $by, $id]);
    }

    public function getSession(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM ch_sessions WHERE id=?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** All sessions, newest first, with attendee counts. */
    public function listSessions(): array
    {
        return $this->db->query(
            'SELECT s.*, COUNT(a.id) AS attendees, SUM(a.status="excused") AS excused
               FROM ch_sessions s LEFT JOIN ch_attendance a ON a.session_id = s.id
              GROUP BY s.id ORDER BY s.held_on DESC, s.id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function sessionCount(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM ch_sessions')->fetchColumn();
    }

    /** The rule only applies once enough sessions exist. */
    public function isLive(): bool
    {
        try {
            return $this->sessionCount() >= self::WINDOW;
        } catch (Throwable $e) {
            error_log('CommunityRecords isLive failed: ' . $e->getMessage());
            return false;   // tables missing (migration not run yet): the old form keeps working
        }
    }

    public function lastSessions(): array
    {
        return $this->db->query('SELECT id, title, held_on FROM ch_sessions ORDER BY held_on DESC, id DESC LIMIT ' . self::WINDOW)
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------------
    // Attendance
    // ------------------------------------------------------------------

    public function attendees(int $sessionId): array
    {
        $st = $this->db->prepare(
            'SELECT a.*, p.member_id AS pm FROM ch_attendance a
               LEFT JOIN member_profile p
                 ON p.username_verified_at IS NOT NULL AND a.wikimedia_username <> ""
                AND LOWER(p.wikimedia_username) = LOWER(a.wikimedia_username)
              WHERE a.session_id = ? ORDER BY a.logged_at DESC, a.id DESC'
        );
        $st->execute([$sessionId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function removeAttendance(int $rowId): void
    {
        $this->db->prepare('DELETE FROM ch_attendance WHERE id=?')->execute([$rowId]);
    }

    /**
     * Turn pasted text into a preview. One person per line, or several on a line
     * separated by commas:
     *     D48213977
     *     SomeUser
     *     SomeUser, OtherUser, D48213977
     *     SomeUser | excused | exam that day
     * A line containing "|" is kept whole, because the reason after "excused" can contain commas.
     * Each result: kind (member | username | bad), member_id, username, status, note, label, key.
     * Duplicates inside the paste are merged and counted in $dupes.
     */
    public function previewLines(string $text, ?int &$dupes = 0): array
    {
        $dupes = 0;
        $out = [];
        $seen = [];

        $entries = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            if (strpos($line, '|') !== false) {
                $entries[] = $line;
                continue;
            }
            foreach (explode(',', $line) as $part) {
                $entries[] = $part;
            }
        }

        foreach ($entries as $raw) {
            $raw = trim($raw);
            if ($raw === '') continue;
            $row = ['raw' => $raw, 'kind' => 'bad', 'member_id' => null, 'username' => '', 'status' => 'present', 'note' => '', 'label' => '', 'key' => ''];

            $parts = array_map('trim', explode('|', $raw, 3));
            $ident = $parts[0];
            $flag = strtolower($parts[1] ?? '');
            $row['note'] = $parts[2] ?? '';

            if (!in_array($flag, ['', 'present', 'excused'], true)) { $row['label'] = 'Second part must be "excused" or empty'; $out[] = $row; continue; }
            $row['status'] = $flag === 'excused' ? 'excused' : 'present';
            if ($row['status'] === 'excused' && $row['note'] === '') { $row['label'] = 'Excused needs a note (use: name | excused | reason)'; $out[] = $row; continue; }
            if (mb_strlen($row['note']) > 500) { $row['label'] = 'Note is too long'; $out[] = $row; continue; }

            if (preg_match('/^D\d{' . MemberModel::MEMBER_ID_DIGITS . '}$/i', $ident)) {
                $mid = strtoupper($ident);
                if (!(new MemberModel())->membershipsFor($mid)) { $row['label'] = 'No member has this Member ID'; $out[] = $row; continue; }
                $row['kind'] = 'member'; $row['member_id'] = $mid; $row['username'] = $this->verifiedUsernameOf($mid);
            } else {
                $u = WikiUsername::normalize(preg_replace('/^User:/i', '', $ident));
                if ($u === '' || mb_strlen($u) > 255 || preg_match('/[#<>\[\]|{}]/u', $u)) { $row['label'] = 'Not a readable username or Member ID'; $out[] = $row; continue; }
                $mid = $this->memberByVerifiedUsername($u);
                if ($mid !== null) {
                    $row['kind'] = 'member'; $row['member_id'] = $mid; $row['username'] = $this->verifiedUsernameOf($mid) ?: $u;
                } else {
                    $row['kind'] = 'username'; $row['username'] = $u;
                }
            }

            $row['key'] = $row['member_id'] ?? 'u:' . mb_strtolower($row['username']);
            if (isset($seen[$row['key']])) { $dupes++; continue; }
            $seen[$row['key']] = true;
            $row['label'] = $row['kind'] === 'member' ? 'Matched to a member' : 'No member with this verified username yet';
            $out[] = $row;
        }
        return $out;
    }

    public function saveAttendance(int $sessionId, array $rows, string $by): int
    {
        $st = $this->db->prepare(
            'INSERT INTO ch_attendance (session_id, person_key, member_id, wikimedia_username, status, note, logged_by)
             VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE member_id=VALUES(member_id), wikimedia_username=VALUES(wikimedia_username),
                  status=VALUES(status), note=VALUES(note), logged_by=VALUES(logged_by), logged_at=NOW()'
        );
        $n = 0;
        $this->db->beginTransaction();
        try {
            foreach ($rows as $r) {
                if ($r['kind'] === 'bad') continue;
                $st->execute([$sessionId, $r['key'], $r['member_id'], $r['username'], $r['status'], $r['note'], $by]);
                $n++;
            }
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
        return $n;
    }

    private function verifiedUsernameOf(string $memberId): string
    {
        $st = $this->db->prepare('SELECT wikimedia_username FROM member_profile WHERE member_id=? AND username_verified_at IS NOT NULL');
        $st->execute([$memberId]);
        return (string) ($st->fetchColumn() ?: '');
    }

    private function memberByVerifiedUsername(string $u): ?string
    {
        $st = $this->db->prepare('SELECT member_id FROM member_profile WHERE username_verified_at IS NOT NULL AND LOWER(wikimedia_username)=LOWER(?) LIMIT 1');
        $st->execute([$u]);
        $v = $st->fetchColumn();
        return $v ? (string) $v : null;
    }

    // ------------------------------------------------------------------
    // Technical contributors
    // ------------------------------------------------------------------

    public function techActiveRow(string $memberId): ?array
    {
        $st = $this->db->prepare(
            'SELECT * FROM tech_contributors WHERE member_id=? AND removed_at IS NULL
                AND (confirmed_until IS NULL OR confirmed_until >= CURDATE()) ORDER BY id DESC LIMIT 1'
        );
        $st->execute([strtoupper($memberId)]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Entries not removed (lapsed ones included, so staff can re-confirm them). */
    public function techCurrent(): array
    {
        return $this->withUsernames($this->db->query(
            'SELECT * FROM tech_contributors WHERE removed_at IS NULL ORDER BY added_at DESC'
        )->fetchAll(PDO::FETCH_ASSOC));
    }

    public function techRemoved(): array
    {
        return $this->withUsernames($this->db->query(
            'SELECT * FROM tech_contributors WHERE removed_at IS NOT NULL ORDER BY removed_at DESC LIMIT 200'
        )->fetchAll(PDO::FETCH_ASSOC));
    }

    private function withUsernames(array $rows): array
    {
        foreach ($rows as &$r) $r['username'] = $this->verifiedUsernameOf((string) $r['member_id']);
        unset($r);
        return $rows;
    }

    public function techAdd(string $identifier, string $reason, string $until, string $by): string
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) throw new InvalidArgumentException('Say why this person is on the list (up to 500 characters).');
        $until = trim($until);
        if ($until !== '') $until = $this->futureDate($until);

        $p = $this->previewLines($identifier);
        if (count($p) !== 1 || $p[0]['kind'] !== 'member') {
            throw new InvalidArgumentException('Enter one Member ID, or the verified Wikimedia username of an existing member.');
        }
        $mid = $p[0]['member_id'];
        if ($this->techActiveRow($mid)) throw new InvalidArgumentException("$mid is already on the list.");

        // A lapsed entry is re-confirmed rather than duplicated.
        $st = $this->db->prepare('SELECT id FROM tech_contributors WHERE member_id=? AND removed_at IS NULL LIMIT 1');
        $st->execute([$mid]);
        if ($old = $st->fetchColumn()) {
            $this->techConfirm((int) $old, $until, $by);
            return $mid;
        }
        $this->db->prepare('INSERT INTO tech_contributors (member_id, added_by, reason, confirmed_until) VALUES (?,?,?,?)')
            ->execute([$mid, $by, $reason, $until === '' ? null : $until]);
        return $mid;
    }

    public function techConfirm(int $id, string $until, string $by): void
    {
        $until = trim($until);
        if ($until !== '') $until = $this->futureDate($until);
        $this->db->prepare('UPDATE tech_contributors SET confirmed_until=?, added_by=? WHERE id=? AND removed_at IS NULL')
            ->execute([$until === '' ? null : $until, $by, $id]);
    }

    public function techRemove(int $id, string $reason, string $by): void
    {
        $reason = trim($reason);
        if ($reason === '') throw new InvalidArgumentException('Give a reason for removing this person.');
        $this->db->prepare('UPDATE tech_contributors SET removed_by=?, removed_at=NOW(), removed_reason=? WHERE id=? AND removed_at IS NULL')
            ->execute([$by, mb_substr($reason, 0, 500), $id]);
    }

    private function futureDate(string $d): string
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d, new DateTimeZone('Asia/Kolkata'));
        if (!$dt || $dt->format('Y-m-d') !== $d) throw new InvalidArgumentException('Enter a valid "confirmed until" date.');
        if ($dt < new DateTimeImmutable('today', new DateTimeZone('Asia/Kolkata'))) throw new InvalidArgumentException('"Confirmed until" cannot be in the past.');
        return $d;
    }

    // ------------------------------------------------------------------
    // Eligibility
    // ------------------------------------------------------------------

    /**
     * Server-side answers for the two questions members used to tick themselves.
     * $verifiedUsername must be the member's VERIFIED username ('' if none).
     */
    public function eligibilityFor(string $memberId, string $verifiedUsername): array
    {
        $live = $this->isLive();
        $sessions = [];
        $all = true;
        if ($live) {
            $st = $this->db->prepare(
                'SELECT status FROM ch_attendance
                  WHERE session_id = :s AND (member_id = :m OR (:u1 <> "" AND LOWER(wikimedia_username) = LOWER(:u2)))
                  ORDER BY (status = "present") DESC LIMIT 1'
            );
            foreach ($this->lastSessions() as $s) {
                $st->execute([':s' => $s['id'], ':m' => strtoupper($memberId), ':u1' => $verifiedUsername, ':u2' => $verifiedUsername]);
                $status = $st->fetchColumn() ?: 'absent';
                if ($status === 'absent') $all = false;
                $sessions[] = ['id' => (int) $s['id'], 'title' => $s['title'], 'held_on' => $s['held_on'], 'status' => $status];
            }
        }
        $tech = $live ? $this->techActiveRow($memberId) : null;
        return [
            'live'        => $live,
            'sessions'    => $sessions,
            'attended_ch' => $live && $all && count($sessions) === self::WINDOW,
            'tech'        => $tech !== null,
            'tech_row'    => $tech,
        ];
    }

    public function saveSnapshot(string $trackingId, string $memberId, array $elig, string $edits80): void
    {
        $route = ($elig['tech'] ?? false) ? 'technical' : ((($elig['attended_ch'] ?? false) && $edits80 === 'yes') ? 'attendance' : 'none');
        $snap = [
            'route'       => $route,
            'edits_80'    => $edits80,
            'sessions'    => $elig['sessions'] ?? [],
            'tech'        => $elig['tech_row'] ? [
                'added_by' => $elig['tech_row']['added_by'], 'added_at' => $elig['tech_row']['added_at'],
                'reason' => $elig['tech_row']['reason'], 'confirmed_until' => $elig['tech_row']['confirmed_until'],
            ] : null,
            'checked_at'  => (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('c'),
        ];
        $this->db->prepare('INSERT IGNORE INTO ch_eligibility_snapshots (tracking_id, member_id, snapshot_json) VALUES (?,?,?)')
            ->execute([$trackingId, strtoupper($memberId), json_encode($snap, JSON_UNESCAPED_UNICODE)]);
    }

    /** For the reviewer's request page. Null for requests made before this feature. */
    public function getSnapshot(string $trackingId): ?array
    {
        $st = $this->db->prepare('SELECT snapshot_json FROM ch_eligibility_snapshots WHERE tracking_id=?');
        $st->execute([$trackingId]);
        $j = $st->fetchColumn();
        return $j ? (json_decode((string) $j, true) ?: null) : null;
    }
}
