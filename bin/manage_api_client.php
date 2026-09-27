<?php
// bin/manage_api_client.php — CLI tool to manage API client credentials for Lisa / Hermes Agent

if (php_sapi_name() !== 'cli') {
    die("Script ini hanya dapat dijalankan melalui terminal CLI.\n");
}

require_once __DIR__ . '/../app/Helpers/api_auth.php';

function printUsage(): void {
    echo "========================================================\n";
    echo "  GAJIKU — API Client Credential Manager (CLI)\n";
    echo "========================================================\n";
    echo "Usage:\n";
    echo "  php bin/manage_api_client.php list\n";
    echo "  php bin/manage_api_client.php create <name> <client_key> [scopes]\n";
    echo "  php bin/manage_api_client.php revoke <client_key>\n";
    echo "  php bin/manage_api_client.php regenerate <client_key>\n";
    echo "\n";
    echo "Contoh:\n";
    echo "  php bin/manage_api_client.php create \"Lisa (Hermes Agent)\" lisa_agent \"payroll:read,payroll:propose\"\n";
    echo "  php bin/manage_api_client.php list\n";
    echo "  php bin/manage_api_client.php revoke lisa_agent\n";
    echo "========================================================\n";
}

$action = $argv[1] ?? 'help';

$db = getDbConnection();

switch ($action) {
    case 'list':
        $clients = $db->query("SELECT id, name, client_key, scopes, status, last_used_at, created_at FROM api_clients ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo "\n=== DAFTAR KLIEN INTEGRASI API ===\n";
        if (empty($clients)) {
            echo "Belum ada API client yang terdaftar.\n";
            exit;
        }
        printf("%-4s | %-24s | %-16s | %-8s | %-28s | %-19s\n", "ID", "Nama", "Client Key", "Status", "Scopes", "Terakhir Digunakan");
        echo str_repeat("-", 100) . "\n";
        foreach ($clients as $c) {
            printf("%-4d | %-24s | %-16s | %-8s | %-28s | %-19s\n", 
                $c['id'], 
                substr($c['name'], 0, 24), 
                $c['client_key'], 
                $c['status'], 
                substr($c['scopes'], 0, 28),
                $c['last_used_at'] ?: 'Belum pernah'
            );
        }
        echo "\n";
        break;

    case 'create':
        $name = $argv[2] ?? '';
        $clientKey = $argv[3] ?? '';
        $scopes = $argv[4] ?? 'payroll:read,payroll:propose';

        if (empty($name) || empty($clientKey)) {
            echo "Error: Nama dan client_key wajib ditentukan.\n";
            printUsage();
            exit(1);
        }

        try {
            $created = createApiClient($name, $clientKey, $scopes);
            echo "\n[SUKSES] Klien API baru berhasil dibuat!\n";
            echo "--------------------------------------------------------\n";
            echo "ID         : {$created['id']}\n";
            echo "Nama       : {$created['name']}\n";
            echo "Client Key : {$created['client_key']}\n";
            echo "Scopes     : {$created['scopes']}\n";
            echo "Status     : {$created['status']}\n";
            echo "RAW TOKEN  : {$created['raw_token']}\n";
            echo "--------------------------------------------------------\n";
            echo "PENTING: Simpan RAW TOKEN di tempat yang aman (misalnya di env Hermes Lisa).\n";
            echo "Token hanya ditampilkan sekali ini saja dan disimpan dalam bentuk SHA-256 hash di database.\n";
            echo "Token TIDAK BOLEH di-commit ke Git!\n\n";
        } catch (\Throwable $e) {
            echo "Error: " . $e->getMessage() . "\n";
            exit(1);
        }
        break;

    case 'revoke':
        $clientKey = $argv[2] ?? '';
        if (empty($clientKey)) {
            echo "Error: Tentukan client_key yang ingin dicabut.\n";
            exit(1);
        }

        $stmt = $db->prepare("UPDATE api_clients SET status = 'revoked', updated_at = datetime('now', 'localtime') WHERE client_key = ?");
        $stmt->execute([$clientKey]);

        if ($stmt->rowCount() > 0) {
            echo "[SUKSES] Klien '{$clientKey}' telah dicabut (revoked) dan tidak dapat mengakses API lagi.\n";
        } else {
            echo "[INFO] Klien dengan client_key '{$clientKey}' tidak ditemukan.\n";
        }
        break;

    case 'regenerate':
        $clientKey = $argv[2] ?? '';
        if (empty($clientKey)) {
            echo "Error: Tentukan client_key yang ingin diganti tokennya.\n";
            exit(1);
        }

        $stmt = $db->prepare("SELECT * FROM api_clients WHERE client_key = ?");
        $stmt->execute([$clientKey]);
        $client = $stmt->fetch();

        if (!$client) {
            echo "Error: Klien '{$clientKey}' tidak ditemukan.\n";
            exit(1);
        }

        $rawToken = 'gjk_' . bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $rawToken);

        $stmtUp = $db->prepare("UPDATE api_clients SET token_hash = ?, status = 'active', updated_at = datetime('now', 'localtime') WHERE id = ?");
        $stmtUp->execute([$tokenHash, $client['id']]);

        echo "\n[SUKSES] Token untuk klien '{$clientKey}' berhasil diganti!\n";
        echo "--------------------------------------------------------\n";
        echo "Client Key : {$clientKey}\n";
        echo "NEW TOKEN  : {$rawToken}\n";
        echo "--------------------------------------------------------\n";
        echo "Token lama sudah tidak berlaku.\n\n";
        break;

    default:
        printUsage();
        break;
}
