<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Config\Config;
use App\Config\Database;

$basePath = dirname(__DIR__);
$config = Config::fromEnvFile($basePath . DIRECTORY_SEPARATOR . '.env');
if (!in_array($config->get('APP_ENV', 'production'), ['local', 'testing'], true)) {
    fwrite(STDERR, "Seed de desarrollo bloqueado fuera de APP_ENV=local.\n");
    exit(1);
}

$pdo = Database::connect($config);
$credentialsPath = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dev-credentials.local';

$accounts = [
    [
        'email' => 'admin.general@neumacontrol.local',
        'nombres' => 'Ada',
        'apellidos' => 'General',
        'rol' => 'ADMIN_GENERAL',
        'cliente' => false,
    ],
    [
        'email' => 'gestor.demo@neumacontrol.local',
        'nombres' => 'Gael',
        'apellidos' => 'Gestor',
        'rol' => 'GESTOR_NEUMATICOS',
        'cliente' => true,
    ],
];

$pdo->beginTransaction();
try {
    $clienteId = ensureDemoClient($pdo);
    $lines = [
        'Credenciales locales de desarrollo. No usar en producción.',
        'Generadas con password_hash(). Este archivo no debe versionarse.',
        'cliente_demo_id=' . $clienteId,
    ];
    $created = 0;

    foreach ($accounts as $account) {
        $existingId = findUserId($pdo, $account['email']);
        if ($existingId !== null) {
            $lines[] = $account['email'] . ' ' . $account['rol'] . ' ya_existia';
            continue;
        }

        $password = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('No se pudo generar el hash de contraseña.');
        }

        $insert = $pdo->prepare(
            'INSERT INTO usuarios (nombres, apellidos, email, password_hash, estado, eliminado)
             VALUES (:nombres, :apellidos, :email, :password_hash, :estado, 0)'
        );
        $insert->execute([
            'nombres' => $account['nombres'],
            'apellidos' => $account['apellidos'],
            'email' => $account['email'],
            'password_hash' => $hash,
            'estado' => 'ACTIVO',
        ]);
        $userId = (int) $pdo->lastInsertId();
        assignRole($pdo, $userId, $account['rol']);
        if ($account['cliente']) {
            assignClient($pdo, $userId, $clienteId);
        }
        $lines[] = $account['email'] . ' ' . $account['rol'] . ' ' . $password;
        $created++;
    }

    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Seed falló.\n");
    exit(1);
}

if ($created > 0) {
    file_put_contents($credentialsPath, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
    echo "Usuarios de desarrollo creados: {$created}. Credenciales en storage/dev-credentials.local\n";
    exit(0);
}

echo "Los usuarios de desarrollo ya existían. No se modificaron contraseñas.\n";
exit(0);

function ensureDemoClient(PDO $pdo): int
{
    $razon = 'Cliente Demostración NeumaControl';
    $find = $pdo->prepare(
        'SELECT id FROM clientes WHERE razon_social = :razon AND eliminado = 0 ORDER BY id ASC LIMIT 1'
    );
    $find->execute(['razon' => $razon]);
    $id = $find->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }

    $insert = $pdo->prepare(
        'INSERT INTO clientes (razon_social, nombre_comercial, estado, eliminado)
         VALUES (:razon, :comercial, :estado, 0)'
    );
    $insert->execute([
        'razon' => $razon,
        'comercial' => 'Demo NeumaControl',
        'estado' => 'ACTIVO',
    ]);

    return (int) $pdo->lastInsertId();
}

function findUserId(PDO $pdo, string $email): ?int
{
    $statement = $pdo->prepare('SELECT id FROM usuarios WHERE email = :email LIMIT 1');
    $statement->execute(['email' => $email]);
    $id = $statement->fetchColumn();

    return $id === false ? null : (int) $id;
}

function assignRole(PDO $pdo, int $userId, string $codigo): void
{
    $role = $pdo->prepare('SELECT id FROM roles WHERE codigo = :codigo AND activo = 1 LIMIT 1');
    $role->execute(['codigo' => $codigo]);
    $roleId = $role->fetchColumn();
    if ($roleId === false) {
        throw new RuntimeException('Rol de desarrollo no disponible.');
    }
    $insert = $pdo->prepare(
        'INSERT INTO usuario_roles (usuario_id, rol_id) VALUES (:usuario_id, :rol_id)'
    );
    $insert->bindValue('usuario_id', $userId, PDO::PARAM_INT);
    $insert->bindValue('rol_id', (int) $roleId, PDO::PARAM_INT);
    $insert->execute();
}

function assignClient(PDO $pdo, int $userId, int $clienteId): void
{
    $insert = $pdo->prepare(
        'INSERT INTO usuario_clientes (usuario_id, cliente_id, fecha_inicio, fecha_fin, activo)
         VALUES (:usuario_id, :cliente_id, CURRENT_DATE, NULL, 1)'
    );
    $insert->bindValue('usuario_id', $userId, PDO::PARAM_INT);
    $insert->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
    $insert->execute();
}
