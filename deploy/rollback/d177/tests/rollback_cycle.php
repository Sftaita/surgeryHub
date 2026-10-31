<?php
// D-177 — test croisé du repli sur une base ISOLÉE (jamais la production).
//   php rollback_cycle.php <backend> <action> <state.json>
// Le même scénario est lu par plusieurs versions du code (D-177, d32dbe7 brut, version de repli)
// sur la même base, en APP_ENV=prod (file Messenger Doctrine réelle, MAILER_DSN=null://null).
//   seed   (code D-177) : T  = reprise de salle publiée DIRECTEMENT au pool (aucune instrumentiste
//                             habituelle → aucune priorité, audit ROOM_TAKEOVER_PRIORITY_BYPASSED) ;
//                        O1 = mission ordinaire publiée au pool puis prise par Paul (ASSIGNED) ;
//                        O2 = demande nominative D-125 en attente pour Marie ;
//                        O3 = attribution directe à Marie.
//   seed-ordinary (code D-177) : O1–O3 seulement, sans reprise (cas B1 : aucune trace D-177).
//   check  (tout code)   : lectures HTTP (manager, instrumentistes) + empreinte des missions et
//                          publications + compteurs de notifications et de la file.
//   claim  (tout code)   : Paul prend T depuis le pool (le pool reste atteignable).
require $argv[1] . '/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv($argv[1] . '/.env');
[$backend, $action, $stateFile] = [$argv[1], $argv[2], $argv[3]];
const PASSWORD = 'Repli-D177-test!';

function kernel(): App\Kernel { $k = new App\Kernel($_SERVER['APP_ENV'], false); $k->boot(); return $k; }
function http(string $method, string $uri, ?string $token = null, ?array $body = null): array
{
    $k = kernel();
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    if ($token !== null) { $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token; }
    $res = $k->handle(Symfony\Component\HttpFoundation\Request::create($uri, $method, server: $server, content: $body !== null ? json_encode($body) : null));
    $out = [$res->getStatusCode(), json_decode((string) $res->getContent(), true), (string) $res->getContent()];
    $k->shutdown();
    return $out;
}
function login(string $email): string
{
    [$code, $json] = http('POST', '/api/auth/login', null, ['email' => $email, 'password' => PASSWORD]);
    if ($code !== 200 || empty($json['token'])) { throw new RuntimeException("login $email → $code"); }
    return $json['token'];
}
function sql(): Doctrine\DBAL\Connection { static $c; return $c ??= kernel()->getContainer()->get('doctrine')->getConnection(); }
function expect(bool $ok, string $label): void { echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n"; if (!$ok) { $GLOBALS['fail'] = ($GLOBALS['fail'] ?? 0) + 1; } }

if ($action === 'seed' || $action === 'seed-ordinary') {
    $k = kernel();
    $em = $k->getContainer()->get('doctrine')->getManager();
    $tag = bin2hex(random_bytes(3));
    $hash = password_hash(PASSWORD, PASSWORD_BCRYPT);
    $mk = function (string $role, string $first, ?App\Enum\EmploymentType $emp = null) use ($em, $tag, $hash) {
        $u = (new App\Entity\User())->setEmail("rb-$first-$tag@surgicalhub.test")->setRoles([$role])->setActive(true)
            ->setFirstname($first)->setLastname('Repli')->setPassword($hash);
        $u->setEmploymentType($emp);
        $em->persist($u);
        return $u;
    };
    $manager = $mk('ROLE_MANAGER', 'Manon'); $a = $mk('ROLE_SURGEON', 'Alain'); $b = $mk('ROLE_SURGEON', 'Bruno'); $c = $mk('ROLE_SURGEON', 'Claire');
    $marie = $mk('ROLE_INSTRUMENTIST', 'Marie', App\Enum\EmploymentType::EMPLOYEE); $paul = $mk('ROLE_INSTRUMENTIST', 'Paul', App\Enum\EmploymentType::EMPLOYEE);
    $site = (new App\Entity\Hospital())->setName("Repli $tag"); $em->persist($site);
    $em->flush();
    foreach ([[$a, 'SURGEON'], [$b, 'SURGEON'], [$c, 'SURGEON'], [$marie, 'INSTRUMENTIST'], [$paul, 'INSTRUMENTIST']] as [$u, $r]) {
        $sm = new App\Entity\SiteMembership(); $sm->setUser($u); $sm->setSite($site); $sm->setSiteRole($r); $em->persist($sm);
    }
    $tz = new DateTimeZone('Europe/Brussels');
    $day = (new DateTimeImmutable('today', $tz))->modify('+28 days');
    $rule = new App\Entity\RecurrenceRule();
    $rule->setFrequency(App\Enum\RecurrenceFrequency::WEEKLY); $rule->setInterval(1); $rule->setWeekdays([(int) $day->format('N')]);
    $rule->setAnchorDate($day); $rule->setMonthWeeks([]);
    $post = new App\Entity\SurgeonSchedulePost();
    $post->setSurgeon($a); $post->setSite($site); $post->setType(App\Enum\MissionType::BLOCK); $post->setPeriod(App\Enum\ShiftPeriod::MATIN);
    $post->setRecurrence($rule); $post->setStartDate($day); $post->setEndDate($day); $post->setInstrumentist(null); $post->setCreatedBy($a);
    $em->persist($post);
    $mission = function (App\Entity\User $surgeon, string $d) use ($em, $site, $tz) {
        $m = (new App\Entity\Mission())->setStatus(App\Enum\MissionStatus::OPEN)->setType(App\Enum\MissionType::BLOCK)->setSurgeon($surgeon)
            ->setSite($site)->setCreatedBy($surgeon)->setInstrumentist(null)
            ->setStartAt(new DateTimeImmutable("$d 08:00", $tz))->setEndAt(new DateTimeImmutable("$d 13:00", $tz));
        $em->persist($m);
        return $m;
    };
    $original = $mission($a, $day->format('Y-m-d'));
    $o1 = $mission($c, $day->modify('+1 day')->format('Y-m-d'));
    $o2 = $mission($c, $day->modify('+2 days')->format('Y-m-d'));
    $o3 = $mission($c, $day->modify('+3 days')->format('Y-m-d'));
    $em->flush();
    $ids = ['manager' => $manager->getId(), 'b' => $b->getId(), 'marie' => $marie->getId(), 'paul' => $paul->getId(), 'site' => $site->getId(),
        'original' => $original->getId(), 'O1' => $o1->getId(), 'O2' => $o2->getId(), 'O3' => $o3->getId(),
        'emails' => ['manager' => $manager->getEmail(), 'b' => $b->getEmail(), 'marie' => $marie->getEmail(), 'paul' => $paul->getEmail()]];
    $k->shutdown();

    $tm = login($ids['emails']['manager']); $tb = login($ids['emails']['b']); $tp = login($ids['emails']['paul']);
    if ($action === 'seed-ordinary') { goto ordinary; }
    [$code] = http('POST', '/api/absences', $tm, ['userId' => $a->getId(), 'dateStart' => $day->format('Y-m-d'), 'dateEnd' => $day->format('Y-m-d')]);
    expect($code === 201, "absence d'Alain déclarée (201) → salle libérée");
    $slot = (int) sql()->fetchOne('SELECT id FROM released_operating_room_slot WHERE site_id = ?', [$ids['site']]);
    [$code, , $raw] = http('POST', "/api/available-rooms/$slot/take-over", $tb);
    expect($code === 200, "reprise par Bruno (200) " . ($code !== 200 ? $raw : ''));
    $ids['slot'] = $slot;
    $ids['T'] = (int) sql()->fetchOne('SELECT id FROM mission WHERE surgeon_id = ?', [$ids['b']]);
    ordinary:
    $ids['T'] ??= $ids['original'];
    [$c1] = http('POST', "/api/missions/{$ids['O1']}/publish", $tm, ['scope' => 'POOL']);
    [$c2] = http('POST', "/api/missions/{$ids['O1']}/claim", $tp);
    [$c3] = http('POST', "/api/missions/{$ids['O2']}/publish", $tm, ['scope' => 'TARGETED', 'targetUserId' => $ids['marie']]);
    [$c4] = http('POST', "/api/missions/{$ids['O3']}/assign-directly", $tm, ['instrumentistId' => $ids['marie']]);
    expect([$c1, $c2, $c3, $c4] === [204, 200, 204, 200], "missions ordinaires : pool+prise, demande nominative, attribution directe ($c1/$c2/$c3/$c4)");
    file_put_contents($stateFile, json_encode($ids, JSON_PRETTY_PRINT));
    $pubs = sql()->fetchAllAssociative('SELECT scope, expires_at, closed_at FROM mission_publication WHERE mission_id = ?', [$ids['T']]);
    $types = sql()->fetchFirstColumn('SELECT event_type FROM audit_event WHERE mission_id = ? ORDER BY id', [$ids['T']]);
    echo '  T = mission ' . $ids['T'] . ' — publications : ' . json_encode($pubs) . "\n  audits T : " . implode(', ', $types) . "\n";
    exit($GLOBALS['fail'] ?? 0);
}

$ids = json_decode(file_get_contents($stateFile), true);

if ($action === 'claim') {
    [$code, , $raw] = http('POST', "/api/missions/{$ids['T']}/claim", login($ids['emails']['paul']));
    expect($code === 200, "Paul prend T depuis le pool ($code) " . ($code !== 200 ? substr($raw, 0, 200) : ''));
    exit($GLOBALS['fail'] ?? 0);
}

// check : ce que voit ce code.
$tm = login($ids['emails']['manager']); $tp = login($ids['emails']['paul']); $tmarie = login($ids['emails']['marie']);
$reads = [
    ['manager', 'GET', "/api/missions/{$ids['T']}", $tm], ['manager', 'GET', "/api/missions/{$ids['T']}/audit", $tm],
    ['manager', 'GET', "/api/missions/{$ids['O1']}", $tm], ['manager', 'GET', "/api/missions/{$ids['O2']}", $tm],
    ['manager', 'GET', "/api/missions/{$ids['O3']}/audit", $tm], ['manager', 'GET', '/api/missions', $tm],
    ['manager', 'GET', '/api/manager/audit-events', $tm], ['manager', 'GET', '/api/planning/available-rooms', $tm],
    ['paul', 'GET', "/api/missions/{$ids['T']}", $tp], ['paul', 'GET', '/api/missions', $tp],
    ['marie', 'GET', "/api/missions/{$ids['O2']}", $tmarie], ['marie', 'GET', '/api/missions', $tmarie],
];
$codes = [];
foreach ($reads as [$who, $m, $uri, $tok]) {
    [$code, $json, $raw] = http($m, $uri, $tok);
    $codes[] = $code;
    $extra = '';
    if ($code >= 500) { $extra = ' ← ' . substr(preg_replace('/\s+/', ' ', strip_tags($raw)), 0, 140); }
    if ($uri === "/api/missions/{$ids['T']}" && $code === 200) { $extra = ' status=' . ($json['status'] ?? '?'); }
    if ($uri === "/api/missions/{$ids['O2']}" && $code === 200) { $extra = ' demande=' . ($json['targetedOffer']['status'] ?? 'aucune'); }
    printf("  %-7s %-4s %-40s %d%s\n", $who, $m, $uri, $code, $extra);
}
$all = implode(',', [$ids['T'], $ids['O1'], $ids['O2'], $ids['O3']]);
$missions = sql()->fetchAllAssociative("SELECT id, status, IFNULL(instrumentist_id,'-') AS instr FROM mission WHERE id IN ($all) ORDER BY id");
$pubs = sql()->fetchAllAssociative("SELECT id, mission_id, scope, IFNULL(target_instrumentist_id,'-') t, IFNULL(declined_at,'-') d, IFNULL(expires_at,'-') e, IFNULL(closed_at,'-') c FROM mission_publication WHERE mission_id IN ($all) ORDER BY id");
echo '  missions    : ' . implode(' ', array_map(fn ($m) => "{$m['id']}={$m['status']}/{$m['instr']}", $missions)) . "\n";
echo '  empreinte   : missions ' . md5(json_encode($missions)) . ' · publications ' . md5(json_encode($pubs)) . ' (' . count($pubs) . ")\n";
printf("  compteurs   : outbound_notification=%d notification_event=%d messenger_messages=%s\n",
    sql()->fetchOne('SELECT COUNT(*) FROM outbound_notification'), sql()->fetchOne('SELECT COUNT(*) FROM notification_event'),
    json_encode(sql()->fetchAllKeyValue('SELECT queue_name, COUNT(*) FROM messenger_messages GROUP BY queue_name')));
echo '  lectures    : ' . (max($codes) < 500 ? 'aucune erreur 500' : 'ERREUR 500 sur ' . count(array_filter($codes, fn ($c) => $c >= 500)) . ' lecture(s)') . "\n";
