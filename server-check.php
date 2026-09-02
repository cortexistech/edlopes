<?php
/**
 * Diagnóstico seguro do servidor — apague este arquivo após o uso.
 *
 * Uso: https://seusite.com.br/server-check.php?token=SEU_TOKEN
 * Defina SERVER_CHECK_TOKEN no .env (ou altere $fallbackToken abaixo).
 */
header('Content-Type: text/plain; charset=UTF-8');

$fallbackToken = 'altere-este-token-antes-de-usar';

require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$expected = $_ENV['SERVER_CHECK_TOKEN'] ?? $fallbackToken;
$provided = $_GET['token'] ?? '';

if ($provided === '' || !hash_equals($expected, $provided)) {
  http_response_code(403);
  exit("Acesso negado.\n");
}

$ok = static fn(bool $v): string => $v ? 'OK' : 'FALHOU';

echo "=== Diagnóstico Edlopes (server-check) ===\n\n";
echo 'PHP: ' . PHP_VERSION . "\n";
echo 'curl: ' . $ok(extension_loaded('curl')) . "\n";
echo 'mbstring: ' . $ok(extension_loaded('mbstring')) . "\n";
echo 'openssl: ' . $ok(extension_loaded('openssl')) . "\n";
echo 'PHPMailer: ' . $ok(class_exists(PHPMailer\PHPMailer\PHPMailer::class)) . "\n\n";

echo "Variáveis (.env):\n";
echo '  SMTP_USER: ' . (!empty($_ENV['SMTP_USER']) ? 'configurado' : 'AUSENTE') . "\n";
echo '  SMTP_PASS: ' . (!empty($_ENV['SMTP_PASS']) ? 'configurado' : 'AUSENTE') . "\n";
echo '  TURNSTILE_SECRET: ' . (!empty($_ENV['TURNSTILE_SECRET']) ? 'configurado' : 'AUSENTE') . "\n\n";

$storageDir = __DIR__ . '/storage';
if (!is_dir($storageDir)) {
  @mkdir($storageDir, 0755, true);
}
$testFile = $storageDir . '/check_' . bin2hex(random_bytes(4)) . '.tmp';
$writable = @file_put_contents($testFile, 'ok') !== false;
if ($writable) {
  @unlink($testFile);
}
echo 'storage/ gravável: ' . $ok($writable) . "\n";
echo 'vendor/autoload.php: ' . $ok(is_file(__DIR__ . '/vendor/autoload.php')) . "\n";
echo 'enviar.php: ' . $ok(is_file(__DIR__ . '/enviar.php')) . "\n";
echo '.htaccess: ' . $ok(is_file(__DIR__ . '/.htaccess')) . "\n\n";

echo "Concluído em " . date('c') . "\n";
echo "\n>>> Apague server-check.php do servidor após verificar.\n";
