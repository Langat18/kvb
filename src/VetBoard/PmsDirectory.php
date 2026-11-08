<?php

declare(strict_types=1);

namespace VetBoard;

use PDO;

final class PmsDirectory
{
    private const LOCK_KEY = 41041;

    private PDO $pdo;
    private PmsClient $client;

    public function __construct(?PmsClient $client = null, ?PDO $pdo = null)
    {
        $this->pdo    = $pdo ?? Database::getInstance()->getPdo();
        $this->client = $client ?? new PmsClient(null, null, 8, 3);
    }

    /** Pulls providers and activities from PMS into the local cache. Never throws. */
    public function sync(string $trigger = 'cron'): array
    {
        $trigger = $trigger === 'manual' ? 'manual' : 'cron';

        if (!(bool) $this->pdo->query('SELECT pg_try_advisory_lock(' . self::LOCK_KEY . ')')->fetchColumn()) {
            return ['ok' => false, 'message' => 'A sync is already running.'];
        }

        $stmt = $this->pdo->prepare('INSERT INTO pms_sync_runs (trigger_source) VALUES (:t) RETURNING id');
        $stmt->execute([':t' => $trigger]);
        $runId = (int) $stmt->fetchColumn();

        try {
            $result = $this->runSync();
            $this->finishRun($runId, 'ok', $result, null);

            return ['ok' => true] + $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            error_log('PMS sync failed: ' . $e->getMessage());
            $this->finishRun($runId, 'failed', [], mb_substr($e->getMessage(), 0, 255));

            return ['ok' => false, 'message' => $e->getMessage()];
        } finally {
            $this->pdo->query('SELECT pg_advisory_unlock(' . self::LOCK_KEY . ')');
        }
    }

    public function lastRun(): ?array
    {
        $row = $this->pdo->query('SELECT * FROM pms_sync_runs ORDER BY id DESC LIMIT 1')->fetch();

        return $row ?: null;
    }

    public function lastSuccess(): ?array
    {
        $row = $this->pdo->query("SELECT * FROM pms_sync_runs WHERE status = 'ok' ORDER BY id DESC LIMIT 1")->fetch();

        return $row ?: null;
    }

    public function listActivities(array $f, string $today, int $limit, int $offset): array
    {
        [$where, $params] = $this->activityFilter($f, $today);

        $order = ($f['scope'] ?? 'recent') === 'past'
            ? 'a.start_date DESC NULLS LAST, a.id DESC'
            : 'a.start_date ASC NULLS LAST, a.id ASC';

        $stmt = $this->pdo->prepare(
            "SELECT a.*,
                    (SELECT COALESCE(json_agg(json_build_object('id', e.id, 'title', e.title, 'token', e.view_token, 'method', l.method) ORDER BY e.start_date), '[]'::json)
                       FROM pms_event_links l JOIN events e ON e.id = l.event_id
                      WHERE l.pms_activity_id = a.id) AS linked_events,
                    (SELECT COUNT(*) FROM pms_activities b
                      WHERE b.removed_at IS NULL AND b.id <> a.id AND a.activity_number IS NOT NULL
                        AND upper(trim(b.activity_number)) = upper(trim(a.activity_number))) AS number_shared
             FROM pms_activities a
             WHERE {$where}
             ORDER BY {$order}
             LIMIT :limit OFFSET :offset"
        );

        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['linked_events'] = json_decode((string) $row['linked_events'], true) ?: [];
            $row['schedules']     = json_decode((string) $row['schedules'], true) ?: [];
        }

        return $rows;
    }

    public function countActivities(array $f, string $today): int
    {
        [$where, $params] = $this->activityFilter($f, $today);

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM pms_activities a WHERE {$where}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function activityTypes(): array
    {
        return $this->pdo->query(
            'SELECT DISTINCT activity_type FROM pms_activities WHERE removed_at IS NULL AND activity_type IS NOT NULL ORDER BY 1'
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    public function findActivity(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pms_activities WHERE id = :id AND removed_at IS NULL');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $row['schedules'] = json_decode((string) $row['schedules'], true) ?: [];

        return $row;
    }

    /** Events from the last $days days (and upcoming) that have no PMS activity link, with the reason. */
    public function unlinkedEvents(int $days = 120): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT e.id, e.title, e.event_code, e.start_date, e.end_date, e.view_token,
                    (SELECT COALESCE(json_agg(json_build_object('id', a.id, 'title', a.title, 'number', a.activity_number, 'start', a.start_date, 'end', a.end_date,
                                                              'overlaps', EXISTS (SELECT 1 FROM jsonb_array_elements(a.schedules) s
                                                                                   WHERE (s->>'start_date')::date <= e.end_date AND (s->>'end_date')::date >= e.start_date)) ORDER BY a.start_date), '[]'::json)
                       FROM pms_activities a
                      WHERE a.removed_at IS NULL
                        AND length(trim(COALESCE(e.event_code, ''))) > 0
                        AND upper(trim(a.activity_number)) = upper(trim(e.event_code))) AS candidates
             FROM events e
             WHERE NOT EXISTS (SELECT 1 FROM pms_event_links l WHERE l.event_id = e.id)
               AND e.end_date >= CURRENT_DATE - CAST(:days AS integer)
             ORDER BY e.start_date DESC"
        );
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['candidates'] = json_decode((string) $row['candidates'], true) ?: [];
            $code              = trim((string) ($row['event_code'] ?? ''));

            if ($code === '') {
                $row['reason'] = 'no_code';
            } elseif (!$row['candidates']) {
                $row['reason'] = 'unknown_code';
            } elseif (array_filter($row['candidates'], static fn($c) => !empty($c['overlaps']))) {
                $row['reason'] = 'ambiguous';
            } else {
                $row['reason'] = 'check_dates';
            }
        }

        return $rows;
    }

    public function link(int $eventId, int $activityId, ?int $staffId, string $method = 'manual'): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pms_event_links (event_id, pms_activity_id, method, linked_by)
             SELECT e.id, a.id, :method, :staff
             FROM events e, pms_activities a
             WHERE e.id = :event AND a.id = :activity AND a.removed_at IS NULL
             ON CONFLICT (event_id) DO UPDATE SET pms_activity_id = EXCLUDED.pms_activity_id, method = EXCLUDED.method,
                 linked_by = EXCLUDED.linked_by, linked_at = now()'
        );
        $stmt->execute([':method' => $method === 'auto' ? 'auto' : 'manual', ':staff' => $staffId, ':event' => $eventId, ':activity' => $activityId]);

        return $stmt->rowCount() === 1;
    }

    public function unlink(int $eventId): void
    {
        $this->pdo->prepare('DELETE FROM pms_event_links WHERE event_id = :id')->execute([':id' => $eventId]);
    }

    /** Links events whose code matches exactly one PMS activity number and whose dates overlap its schedule. */
    public function autoLinkEvents(): int
    {
        $stmt = $this->pdo->query(
            "INSERT INTO pms_event_links (event_id, pms_activity_id, method)
             SELECT e.id, MIN(a.id), 'auto'
             FROM events e
             JOIN pms_activities a
               ON a.removed_at IS NULL
              AND upper(trim(a.activity_number)) = upper(trim(e.event_code))
              AND EXISTS (SELECT 1 FROM jsonb_array_elements(a.schedules) s
                           WHERE (s->>'start_date')::date <= e.end_date AND (s->>'end_date')::date >= e.start_date)
             WHERE length(trim(COALESCE(e.event_code, ''))) > 0
               AND NOT EXISTS (SELECT 1 FROM pms_event_links l WHERE l.event_id = e.id)
             GROUP BY e.id
             HAVING COUNT(*) = 1
             ON CONFLICT (event_id) DO NOTHING"
        );

        return $stmt->rowCount();
    }

    /** Best local provider for a PMS provider name: via PMS accreditation number first, then by name. */
    public function matchLocalProvider(string $pmsProviderName): ?array
    {
        $key = self::normalize($pmsProviderName);

        if ($key === '') {
            return null;
        }

        $local = $this->pdo->query('SELECT id, accreditation_no, name FROM providers WHERE active = TRUE')->fetchAll();
        $byAcc = [];

        foreach ($local as $p) {
            $byAcc[strtoupper(trim((string) $p['accreditation_no']))] = $p;
        }

        foreach ($this->pdo->query('SELECT accred_num, name FROM pms_providers WHERE removed_at IS NULL')->fetchAll() as $row) {
            if (self::normalize((string) $row['name']) === $key && isset($byAcc[strtoupper(trim((string) $row['accred_num']))])) {
                return $byAcc[strtoupper(trim((string) $row['accred_num']))];
            }
        }

        foreach ($local as $p) {
            if (self::normalize((string) $p['name']) === $key) {
                return $p;
            }
        }

        return null;
    }

    /** @return array{pms_only: array, local_only: array, name_differs: array, counts: array} */
    public function providerReport(): array
    {
        $pms   = $this->pdo->query('SELECT * FROM pms_providers WHERE removed_at IS NULL ORDER BY name')->fetchAll();
        $local = $this->pdo->query('SELECT id, accreditation_no, name, active FROM providers ORDER BY name')->fetchAll();

        $localByAcc = [];
        foreach ($local as $p) {
            $localByAcc[strtoupper(trim((string) $p['accreditation_no']))] = $p;
        }

        $pmsByAcc = [];
        foreach ($pms as $p) {
            $pmsByAcc[strtoupper(trim((string) $p['accred_num']))] = $p;
        }

        $pmsOnly = $nameDiffers = $localOnly = [];

        foreach ($pms as $p) {
            $acc = strtoupper(trim((string) $p['accred_num']));

            if (!isset($localByAcc[$acc])) {
                $pmsOnly[] = $p;
            } elseif (self::normalize((string) $p['name']) !== self::normalize((string) $localByAcc[$acc]['name'])) {
                $nameDiffers[] = ['accred_num' => $p['accred_num'], 'pms_name' => $p['name'], 'local_name' => $localByAcc[$acc]['name'], 'status' => $p['status']];
            }
        }

        foreach ($local as $p) {
            if (!isset($pmsByAcc[strtoupper(trim((string) $p['accreditation_no']))])) {
                $localOnly[] = $p;
            }
        }

        return [
            'pms_only'     => $pmsOnly,
            'local_only'   => $localOnly,
            'name_differs' => $nameDiffers,
            'counts'       => ['pms' => count($pms), 'local' => count($local), 'matched' => count($pms) - count($pmsOnly)],
        ];
    }

    /** Copies one approved PMS provider into the local providers table. */
    public function addProviderLocally(string $accredNum): bool
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO providers (accreditation_no, name, contact_person, phone, email)
             SELECT accred_num, name, contact_person, telephone, email
             FROM pms_providers
             WHERE accred_num = :acc AND removed_at IS NULL AND status = 'approved'
             ON CONFLICT (accreditation_no) DO NOTHING"
        );
        $stmt->execute([':acc' => $accredNum]);

        return $stmt->rowCount() === 1;
    }

    public static function normalize(string $name): string
    {
        $name = mb_strtolower(str_replace('&', ' and ', $name));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $name));
    }

    private function runSync(): array
    {
        $providers  = $this->client->providers();
        $activities = $this->client->activities();

        foreach (['providers' => $providers, 'activities' => $activities] as $name => $r) {
            if ($r['status'] !== PmsClient::FOUND || empty($r['data'])) {
                throw new \RuntimeException('PMS ' . $name . ' list unavailable: ' . ($r['message'] ?? $r['status']));
            }
        }

        $existing = (int) $this->pdo->query('SELECT COUNT(*) FROM pms_activities WHERE removed_at IS NULL')->fetchColumn();

        if ($existing >= 20 && count($activities['data']) < $existing * 0.5) {
            throw new \RuntimeException('PMS returned far fewer activities than before (' . count($activities['data']) . ' vs ' . $existing . '); cache left unchanged.');
        }

        $ts     = (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP');
        $hashes = $this->pdo->query('SELECT id, content_hash FROM pms_activities')->fetchAll(PDO::FETCH_KEY_PAIR);
        $added  = 0;
        $changed = 0;
        $seen   = 0;

        $this->pdo->beginTransaction();

        $upProv = $this->pdo->prepare(
            'INSERT INTO pms_providers (accred_num, name, status, accreditation_year, contact_person, telephone, email, synced_at, removed_at)
             VALUES (:acc, :name, :status, :year, :contact, :tel, :email, :ts, NULL)
             ON CONFLICT (accred_num) DO UPDATE SET name = EXCLUDED.name, status = EXCLUDED.status,
                 accreditation_year = EXCLUDED.accreditation_year, contact_person = EXCLUDED.contact_person,
                 telephone = EXCLUDED.telephone, email = EXCLUDED.email, synced_at = EXCLUDED.synced_at, removed_at = NULL'
        );

        $providerRows = self::dedupeProviders($providers['data']);

        foreach ($providerRows as $p) {
            $upProv->execute([
                ':acc' => $p['accred_num'], ':name' => $p['name'], ':status' => $p['status'], ':year' => $p['year'],
                ':contact' => $p['contact'], ':tel' => $p['tel'], ':email' => $p['email'], ':ts' => $ts,
            ]);
        }

        $this->pdo->prepare('UPDATE pms_providers SET removed_at = now() WHERE synced_at < :ts AND removed_at IS NULL')->execute([':ts' => $ts]);

        $upAct = $this->pdo->prepare(
            'INSERT INTO pms_activities (id, title, activity_number, activity_type, coordinator, coordinator_phone, coordinator_email,
                                         provider_name, start_date, end_date, venue, schedules, content_hash, last_seen_at, removed_at)
             VALUES (:id, :title, :num, :type, :coord, :cphone, :cemail, :prov, :start, :end, :venue, :sched, :hash, :ts, NULL)
             ON CONFLICT (id) DO UPDATE SET title = EXCLUDED.title, activity_number = EXCLUDED.activity_number,
                 activity_type = EXCLUDED.activity_type, coordinator = EXCLUDED.coordinator,
                 coordinator_phone = EXCLUDED.coordinator_phone, coordinator_email = EXCLUDED.coordinator_email,
                 provider_name = EXCLUDED.provider_name, start_date = EXCLUDED.start_date, end_date = EXCLUDED.end_date,
                 venue = EXCLUDED.venue, schedules = EXCLUDED.schedules, content_hash = EXCLUDED.content_hash,
                 last_seen_at = EXCLUDED.last_seen_at, removed_at = NULL'
        );

        foreach ($activities['data'] as $raw) {
            $a = self::mapActivity($raw);

            if ($a === null) {
                continue;
            }

            $seen++;

            if (!isset($hashes[$a['id']])) {
                $added++;
            } elseif ($hashes[$a['id']] !== $a['hash']) {
                $changed++;
            }

            $upAct->execute([
                ':id' => $a['id'], ':title' => $a['title'], ':num' => $a['number'], ':type' => $a['type'], ':coord' => $a['coordinator'],
                ':cphone' => $a['coordinator_phone'], ':cemail' => $a['coordinator_email'], ':prov' => $a['provider'],
                ':start' => $a['start'], ':end' => $a['end'], ':venue' => $a['venue'],
                ':sched' => json_encode($a['schedules'], JSON_UNESCAPED_UNICODE), ':hash' => $a['hash'], ':ts' => $ts,
            ]);
        }

        if ($seen === 0) {
            throw new \RuntimeException('PMS activities list contained no usable records.');
        }

        $this->pdo->prepare('UPDATE pms_activities SET removed_at = now() WHERE last_seen_at < :ts AND removed_at IS NULL')->execute([':ts' => $ts]);

        $this->pdo->commit();

        return [
            'providers_seen'     => count($providerRows),
            'activities_seen'    => $seen,
            'activities_added'   => $added,
            'activities_changed' => $changed,
            'events_linked'      => $this->autoLinkEvents(),
        ];
    }

    private function finishRun(int $runId, string $status, array $r, ?string $message): void
    {
        $this->pdo->prepare(
            'UPDATE pms_sync_runs SET finished_at = now(), status = :status, providers_seen = :p, activities_seen = :a,
                    activities_added = :ad, activities_changed = :ch, events_linked = :ev, message = :msg
             WHERE id = :id'
        )->execute([
            ':status' => $status, ':p' => $r['providers_seen'] ?? null, ':a' => $r['activities_seen'] ?? null,
            ':ad' => $r['activities_added'] ?? null, ':ch' => $r['activities_changed'] ?? null, ':ev' => $r['events_linked'] ?? null,
            ':msg' => $message, ':id' => $runId,
        ]);
    }

    /** One row per accreditation number, preferring approved, then the most recently added. */
    private static function dedupeProviders(array $rows): array
    {
        $rank = ['approved' => 3, 'new' => 2, 'rejected' => 1];
        $best = [];

        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }

            $acc  = trim((string) ($r['accred_num'] ?? ''));
            $name = trim((string) ($r['name_of_provider'] ?? ''));

            if ($acc === '' || $name === '') {
                continue;
            }

            $status = strtolower((string) preg_replace('#^.*/#', '', (string) ($r['status'] ?? '')));
            $status = isset($rank[$status]) ? $status : 'unknown';
            $key    = strtoupper($acc);
            $score  = [$rank[$status] ?? 0, (string) ($r['date_added'] ?? '')];

            if (isset($best[$key]) && ($score <=> $best[$key]['score']) <= 0) {
                continue;
            }

            $best[$key] = [
                'score'      => $score,
                'accred_num' => mb_substr($acc, 0, 60),
                'name'       => mb_substr($name, 0, 255),
                'status'     => $status,
                'year'       => self::nullable($r['accreditation_year'] ?? null, 10),
                'contact'    => self::nullable($r['contact_person'] ?? null, 255),
                'tel'        => self::nullable($r['telephone_number'] ?? null, 60),
                'email'      => self::nullable($r['email_address'] ?? null, 255),
            ];
        }

        return array_values($best);
    }

    private static function mapActivity(mixed $r): ?array
    {
        if (!is_array($r)) {
            return null;
        }

        $id    = (int) ($r['id'] ?? 0);
        $title = trim((string) ($r['title'] ?? ''));

        if ($id <= 0 || $title === '') {
            return null;
        }

        $schedules = [];

        foreach ((array) ($r['schedules'] ?? []) as $s) {
            if (!is_array($s)) {
                continue;
            }

            $start = self::date($s['start_date'] ?? null);
            $end   = self::date($s['end_date'] ?? null) ?? $start;

            if ($start === null) {
                continue;
            }

            $schedules[] = ['venue' => (string) ($s['venue'] ?? ''), 'start_date' => $start, 'end_date' => max($start, (string) $end)];
        }

        $venues = array_values(array_unique(array_filter(array_map(static fn($s) => trim($s['venue']), $schedules))));
        $number = trim((string) ($r['activity_number'] ?? ''));

        $a = [
            'id'                => $id,
            'title'             => mb_substr($title, 0, 255),
            'number'            => $number !== '' ? mb_substr($number, 0, 40) : null,
            'type'              => self::nullable($r['activity_type'] ?? null, 60),
            'coordinator'       => self::nullable($r['name_of_cordinator'] ?? null, 255),
            'coordinator_phone' => self::nullable($r['phone_number_cordinator'] ?? null, 60),
            'coordinator_email' => self::nullable($r['email_cordinator'] ?? null, 255),
            'provider'          => self::nullable($r['name_of_provider'] ?? null, 255),
            'start'             => $schedules ? min(array_column($schedules, 'start_date')) : null,
            'end'               => $schedules ? max(array_column($schedules, 'end_date')) : null,
            'venue'             => $venues ? mb_substr(implode(' / ', $venues), 0, 255) : null,
            'schedules'         => $schedules,
        ];

        $a['hash'] = md5((string) json_encode($a, JSON_UNESCAPED_UNICODE));

        return $a;
    }

    private static function date(mixed $v): ?string
    {
        if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }

    private static function nullable(mixed $v, int $max): ?string
    {
        $v = trim((string) $v);

        return $v !== '' ? mb_substr($v, 0, $max) : null;
    }

    private function activityFilter(array $f, string $today): array
    {
        $where  = ['a.removed_at IS NULL'];
        $params = [];

        switch ($f['scope'] ?? 'recent') {
            case 'recent':
                $where[]          = 'a.end_date >= :today';
                $params[':today'] = date('Y-m-d', strtotime($today . ' -30 days'));
                break;
            case 'upcoming':
                $where[]        = 'a.end_date >= :today';
                $params[':today'] = $today;
                break;
            case 'past':
                $where[]        = 'a.end_date < :today';
                $params[':today'] = $today;
                break;
            case 'undated':
                $where[] = 'a.start_date IS NULL';
                break;
        }

        if (!empty($f['q'])) {
            $where[]       = '(a.title ILIKE :q OR a.activity_number ILIKE :q OR a.provider_name ILIKE :q OR a.coordinator ILIKE :q)';
            $params[':q'] = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
        }

        if (!empty($f['type'])) {
            $where[]         = 'a.activity_type = :type';
            $params[':type'] = (string) $f['type'];
        }

        if (($f['linked'] ?? '') === 'yes') {
            $where[] = 'EXISTS (SELECT 1 FROM pms_event_links l WHERE l.pms_activity_id = a.id)';
        } elseif (($f['linked'] ?? '') === 'no') {
            $where[] = 'NOT EXISTS (SELECT 1 FROM pms_event_links l WHERE l.pms_activity_id = a.id)';
        }

        return [implode(' AND ', $where), $params];
    }
}
