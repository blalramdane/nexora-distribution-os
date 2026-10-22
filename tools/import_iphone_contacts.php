<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$csvPath = $root . '/NEXORA_Customers_Test_Import_Candidates.csv';
$dbPath = $root . '/backend/database/distribution-local.sqlite';
$commit = in_array('--commit', $argv, true);

if (!is_file($csvPath) || !is_file($dbPath)) {
    fwrite(STDERR, "Required CSV or local SQLite database not found.\n");
    exit(2);
}

function normalizePhone(?string $value): string {
    $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
    if (str_starts_with($digits, '0020')) $digits = substr($digits, 2);
    if (str_starts_with($digits, '20') && strlen($digits) === 12) $digits = '0' . substr($digits, 2);
    return $digits;
}

function normalizeName(?string $value): string {
    $name = mb_strtolower(trim((string) $value), 'UTF-8');
    $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
    return $name;
}

$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA busy_timeout = 5000');
$org = $pdo->query("SELECT id FROM organizations WHERE status = 'active' ORDER BY created_at LIMIT 1")->fetchColumn();
if (!$org) { fwrite(STDERR, "No active organization in local database.\n"); exit(3); }

$fh = fopen($csvPath, 'rb');
$headers = fgetcsv($fh);
if (!$headers || !in_array('name', $headers, true) || !in_array('phone', $headers, true)) {
    fclose($fh); fwrite(STDERR, "CSV headers are invalid.\n"); exit(4);
}
$rows = [];
while (($values = fgetcsv($fh)) !== false) {
    if (count($values) !== count($headers)) continue;
    $row = array_combine($headers, $values);
    if (trim((string) ($row['name'] ?? '')) === '' || normalizePhone($row['phone'] ?? '') === '') continue;
    $rows[] = $row;
}
fclose($fh);

$existing = $pdo->prepare('SELECT code, name, normalized_name, phone, alternate_phone, notes FROM customers WHERE organization_id = ?');
$existing->execute([$org]);
$knownCodes = $knownExact = $knownPhones = [];
foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $customer) {
    $knownCodes[(string) $customer['code']] = true;
    $name = normalizeName($customer['normalized_name'] ?: $customer['name']);
    $phone = normalizePhone($customer['phone']);
    $knownExact[$name . '|' . $phone] = true;
    if ($phone !== '') $knownPhones[$phone] = ($knownPhones[$phone] ?? 0) + 1;
    $alt = normalizePhone($customer['alternate_phone']);
    if ($alt !== '') $knownPhones[$alt] = ($knownPhones[$alt] ?? 0) + 1;
}

$insert = $pdo->prepare('INSERT INTO customers (id, organization_id, code, name, normalized_name, phone, alternate_phone, address_text, credit_limit, payment_terms_days, status, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 0, 0, ?, ?, ?, ?)');
$now = date('Y-m-d H:i:s');
$seenExact = $seenSourcePhones = [];
$stats = ['source_rows' => count($rows), 'inserted' => 0, 'already_present' => 0, 'invalid' => 0, 'shared_phone_flagged' => 0];
$preview = [];

if ($commit) $pdo->beginTransaction();
try {
    foreach ($rows as $row) {
        $code = trim((string) ($row['code'] ?? ''));
        $name = trim((string) $row['name']);
        $phone = normalizePhone($row['phone'] ?? '');
        $normalizedName = normalizeName($row['normalized_name'] ?? $name);
        $exactKey = $normalizedName . '|' . $phone;

        if ($code !== '' && isset($knownCodes[$code])) { $stats['already_present']++; continue; }
        if (isset($knownExact[$exactKey]) || isset($seenExact[$exactKey])) { $stats['already_present']++; continue; }

        $alternate = normalizePhone($row['alternate_phone'] ?? '');
        $shared = str_contains((string) ($row['shared_phone_review'] ?? ''), 'مراجعة')
            || (($knownPhones[$phone] ?? 0) > 0)
            || (($seenSourcePhones[$phone] ?? 0) > 0);
        if ($shared) $stats['shared_phone_flagged']++;

        $extraPhones = trim((string) ($row['additional_phones_json'] ?? ''));
        $notes = 'جهة اتصال مستوردة من iPhone؛ لم يتم التحقق من كونها عميلاً فعلياً.';
        if ($shared) $notes .= ' رقم مشترك/يحتاج مراجعة قبل إرسال الفواتير.';
        if ($extraPhones !== '' && $extraPhones !== '[]') $notes .= ' أرقام إضافية محفوظة من المصدر: ' . $extraPhones;
        if ($alternate !== '') $notes .= ' رقم بديل: ' . $alternate . '.';
        $id = bin2hex(random_bytes(16));
        // SQLite-backed local app uses ULID string IDs. Build one without an external dependency.
        $time = (int) floor(microtime(true) * 1000);
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $ulid = '';
        for ($i = 9; $i >= 0; $i--) { $ulid = $alphabet[$time & 31] . $ulid; $time = intdiv($time, 32); }
        $bits = hex2bin($id);
        $buffer = 0; $bitCount = 0; $tail = '';
        foreach (str_split($bits) as $byte) {
            $buffer = ($buffer << 8) | ord($byte); $bitCount += 8;
            while ($bitCount >= 5) { $bitCount -= 5; $tail .= $alphabet[($buffer >> $bitCount) & 31]; }
        }
        if ($bitCount > 0) $tail .= $alphabet[($buffer << (5 - $bitCount)) & 31];
        $ulid .= substr($tail, 0, 16);
        if ($code === '') $code = 'IPHONE-' . strtoupper(substr(hash('sha256', $name . '|' . $phone), 0, 12));
        if ($commit) {
            $insert->execute([$ulid, $org, $code, $name, $normalizedName, $phone, $alternate !== '' ? $alternate : null, 'active', $notes, $now, $now]);
        }
        $stats['inserted']++;
        $knownCodes[$code] = true; $knownExact[$exactKey] = true; $seenExact[$exactKey] = true;
        $seenSourcePhones[$phone] = ($seenSourcePhones[$phone] ?? 0) + 1;
        if (count($preview) < 5) $preview[] = ['name' => $name, 'phone' => $phone, 'shared' => $shared];
    }
    if ($commit) $pdo->commit();
} catch (Throwable $e) {
    if ($commit && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Import failed and transaction rolled back: ' . $e->getMessage() . PHP_EOL);
    exit(5);
}

$stats['mode'] = $commit ? 'COMMITTED_TO_LOCAL_SQLITE_ONLY' : 'DRY_RUN_NO_CHANGES';
$stats['organization_id'] = $org;
$stats['final_customer_count'] = (int) $pdo->query('SELECT COUNT(*) FROM customers WHERE organization_id = ' . $pdo->quote($org))->fetchColumn();
$stats['preview'] = $preview;
echo json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
