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
 *
 * When STAFF log attendance they may also type a member's full name. The name is only used to
 * find the Member ID at that moment; the row is saved under the Member ID, so a name typed in
 * a profile or form can never claim anyone's attendance.
 */
class CommunityRecordsModel
{
    const WINDOW = 3;   // how many recent sessions must be attended

    private PDO $db;

    /** Lazily built lookup of member names: ['byName' => [normalised name => [member_id => full name]], 'byId' => [member_id => full name]]. */
    private ?array $nameIndex = null;

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

    /**
     * True once enough sessions are logged for attendance to be able to count for anyone.
     * Informational only (the admin banner): eligibilityFor() always applies the attendance rule,
     * and with fewer than WINDOW sessions nobody qualifies by attendance. Errors are not swallowed.
     */
    public function isLive(): bool
    {
        return $this->sessionCount() >= self::WINDOW;
    }

    public function lastSessions(): array
    {
        return $this->db->query('SELECT id, title, held_on FROM ch_sessions ORDER BY held_on DESC, id DESC LIMIT ' . self::WINDOW)
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------------
    // Attendance
    // ------------------------------------------------------------------

    /**
     * Everyone logged for a session. Each row also carries 'full_name' ('' if unknown): the name of
     * the member it is saved under, or of the member whose verified username matches ('pm').
     */
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
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $byId = $this->nameIndex()['byId'];
        foreach ($rows as &$r) {
            $mid = (string) ($r['member_id'] ?: ($r['pm'] ?? ''));
            $r['full_name'] = $mid !== '' ? ($byId[$mid] ?? '') : '';
        }
        unset($r);
        return $rows;
    }

    public function removeAttendance(int $rowId): void
    {
        $this->db->prepare('DELETE FROM ch_attendance WHERE id=?')->execute([$rowId]);
    }

    /**
     * Turn pasted text into a preview. One person per line, or several on a line
     * separated by commas:
     *     D48213977
     *     A48213977
     *     SomeUser
     *     Asha Verma
     *     SomeUser, OtherUser, J48213977
     *     SomeUser | excused | exam that day
     * A Member ID is one letter (D, A, J or P: see MemberModel::ID_PREFIX) followed by digits;
     * the format is checked by MemberModel::chapterFromMemberId(), so it is defined in one place.
     * A line containing "|" is kept whole, because the reason after "excused" can contain commas.
     *
     * Each entry is tried in this order: Member ID, verified Wikimedia username, and (when
     * $byName is true) a member's full name. A name must match exactly one Member ID; if two
     * different members share it, the line is skipped so staff can paste the Member ID instead.
     * A name containing a comma cannot be used, because commas separate people.
     *
     * Each result: kind (member | username | bad), member_id, username, name, status, note, label, key.
     * Duplicates inside the paste are merged and counted in $dupes.
     */
    public function previewLines(string $text, ?int &$dupes = 0, bool $byName = true): array
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
            $row = ['raw' => $raw, 'kind' => 'bad', 'member_id' => null, 'username' => '', 'name' => '', 'status' => 'present', 'note' => '', 'label' => '', 'key' => ''];

            $parts = array_map('trim', explode('|', $raw, 3));
            $ident = $parts[0];
            $flag = strtolower($parts[1] ?? '');
            $row['note'] = $parts[2] ?? '';

            if (!in_array($flag, ['', 'present', 'excused'], true)) { $row['label'] = 'Second part must be "excused" or empty'; $out[] = $row; continue; }
            $row['status'] = $flag === 'excused' ? 'excused' : 'present';
            if ($row['status'] === 'excused' && $row['note'] === '') { $row['label'] = 'Excused needs a note (use: name | excused | reason)'; $out[] = $row; continue; }
            if (mb_strlen($row['note']) > 500) { $row['label'] = 'Note is too long'; $out[] = $row; continue; }

            $matchLabel = 'Matched to a member';

            // chapterFromMemberId() is case-sensitive, so upper-case first (IDs are typed in any case).
            $idCandidate = strtoupper($ident);
            if (MemberModel::chapterFromMemberId($idCandidate) !== null) {
                $mid = $idCandidate;
                if (!(new MemberModel())->membershipsFor($mid)) { $row['label'] = 'No member has this Member ID'; $out[] = $row; continue; }
                $row['kind'] = 'member'; $row['member_id'] = $mid; $row['username'] = $this->verifiedUsernameOf($mid);
            } else {
                $u = WikiUsername::normalize(preg_replace('/^User:/i', '', $ident));
                $readable = !($u === '' || mb_strlen($u) > 255 || preg_match('/[#<>\[\]|{}]/u', $u));

                $mid = $readable ? $this->memberByVerifiedUsername($u) : null;
                if ($mid !== null) {
                    $row['kind'] = 'member'; $row['member_id'] = $mid; $row['username'] = $this->verifiedUsernameOf($mid) ?: $u;
                } else {
                    $hits = $byName ? $this->membersByName($ident) : [];
                    if (count($hits) === 1) {
                        $mid = (string) array_key_first($hits);
                        $row['kind'] = 'member'; $row['member_id'] = $mid; $row['username'] = $this->verifiedUsernameOf($mid);
                        $matchLabel = 'Matched by name';
                    } elseif (count($hits) > 1) {
                        $row['label'] = 'More than one member has this name (' . implode(', ', array_keys($hits)) . '). Use the Member ID instead.';
                        $out[] = $row; continue;
                    } elseif (!$readable) {
                        $row['label'] = $byName ? 'Not a Member ID, username or member name' : 'Not a readable username or Member ID';
                        $out[] = $row; continue;
                    } else {
                        $row['kind'] = 'username'; $row['username'] = $u;
                    }
                }
            }

            if ($row['member_id'] !== null) $row['name'] = $this->nameIndex()['byId'][$row['member_id']] ?? '';

            $row['key'] = $row['member_id'] ?? 'u:' . mb_strtolower($row['username']);
            if (isset($seen[$row['key']])) { $dupes++; continue; }
            $seen[$row['key']] = true;
            $row['label'] = $row['kind'] === 'member' ? $matchLabel : 'No member with this verified username yet';
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
    // Member names (staff lookup only)
    // ------------------------------------------------------------------

    private static function normName(string $s): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
    }

    /**
     * Name lookups, built once per request from the members table. Matching is done in PHP on
     * purpose (see eligibilityFor): comparing strings across tables in SQL can trip
     * "Illegal mix of collations". One person with several chapter rows counts once.
     */
    private function nameIndex(): array
    {
        if ($this->nameIndex !== null) return $this->nameIndex;
        $idx = ['byName' => [], 'byId' => []];
        foreach ($this->db->query('SELECT member_id, full_name FROM members') as $r) {
            $name = trim((string) $r['full_name']);
            $mid = (string) $r['member_id'];
            if ($name === '' || $mid === '') continue;
            if (!isset($idx['byId'][$mid])) $idx['byId'][$mid] = $name;
            $idx['byName'][self::normName($name)][$mid] = $name;
        }
        return $this->nameIndex = $idx;
    }

    /** member_id => full name for every distinct Member ID whose name matches exactly (ignoring case and extra spaces). */
    private function membersByName(string $name): array
    {
        $key = self::normName($name);
        if ($key === '') return [];
        return $this->nameIndex()['byName'][$key] ?? [];
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

        // Member ID or verified username only: names are for logging attendance, not for this list.
        $dupes = 0;
        $p = $this->previewLines($identifier, $dupes, false);
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
     * Server-side answers for the Conversation Hour and technical questions. ALWAYS applied, from
     * the first session on: with fewer than WINDOW sessions logged nobody qualifies by attendance
     * (only the technical route is open). A lookup error is thrown, never swallowed, so callers
     * fail closed. $verifiedUsername must be the member's VERIFIED username ('' if none).
     */
    public function eligibilityFor(string $memberId, string $verifiedUsername): array
    {
        $sessions = [];
        $all = true;
        // Matching is done in PHP on purpose: comparing bound strings to literals/columns in SQL
        // trips "Illegal mix of collations" on tables with different collations.
        $st = $this->db->prepare('SELECT member_id, wikimedia_username, status FROM ch_attendance WHERE session_id = :s');
        $mid = strtoupper(trim($memberId));
        $uname = mb_strtolower(trim($verifiedUsername));
        foreach ($this->lastSessions() as $s) {
            $st->execute([':s' => $s['id']]);
            $status = 'absent';
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $byId   = $mid !== '' && strtoupper(trim((string) $r['member_id'])) === $mid;
                $byName = $uname !== '' && mb_strtolower(trim((string) $r['wikimedia_username'])) === $uname;
                if (!$byId && !$byName) continue;
                if ($r['status'] === 'present') { $status = 'present'; break; }
                if ($status === 'absent') $status = (string) $r['status'];
            }
            if ($status === 'absent') $all = false;
            $sessions[] = ['id' => (int) $s['id'], 'title' => $s['title'], 'held_on' => $s['held_on'], 'status' => $status];
        }
        $tech = $this->techActiveRow($memberId);
        return [
            'sessions'    => $sessions,
            'attended_ch' => count($sessions) === self::WINDOW && $all,
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
