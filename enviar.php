<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$status = '';
$mensagemFeedback = '';

function clientIp(): string
{
  $cfIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
  if ($cfIp !== '' && filter_var($cfIp, FILTER_VALIDATE_IP)) {
    return $cfIp;
  }

  $remote = $_SERVER['REMOTE_ADDR'] ?? '';
  return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : 'unknown';
}

function isAllowedHost(string $host): bool
{
  $host = strtolower($host);
  return in_array($host, [
    'www.grupoednave.com.br',
    'grupoednave.com.br',
    'localhost',
    '127.0.0.1',
  ], true);
}

function isAllowedSource(?string $url): bool
{
  if ($url === null || $url === '') {
    return false;
  }

  $parts = parse_url($url);
  if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
    return false;
  }

  $scheme = strtolower($parts['scheme']);
  $host = strtolower($parts['host']);

  if ($host === 'localhost' || $host === '127.0.0.1') {
    return in_array($scheme, ['http', 'https'], true);
  }

  return $scheme === 'https' && isAllowedHost($host);
}

function requestSourceAllowed(): bool
{
  $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
  if ($origin !== '') {
    return isAllowedSource($origin);
  }

  return isAllowedSource($_SERVER['HTTP_REFERER'] ?? '');
}

function fieldLength(string $value): int
{
  return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function sanitizeLine(string $value): string
{
  return trim(str_replace(["\r", "\n", "\0"], ' ', $value));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Método não permitido.');
}

if (!requestSourceAllowed()) {
  http_response_code(403);
  $status = 'error';
  $mensagemFeedback = 'Não foi possível validar a origem do envio. Abra o formulário no site e tente novamente.';
} else {
  $rateLimitWindow = 600;
  $rateLimitMax = 3;
  $now = time();
  $ipKey = hash('sha256', clientIp());
  $rateFile = __DIR__ . '/storage/rate_limit.json';

  if (!is_dir(__DIR__ . '/storage')) {
    @mkdir(__DIR__ . '/storage', 0750, true);
  }

  $rateLimited = true;
  $lockHandle = @fopen($rateFile, 'c+');
  if ($lockHandle && flock($lockHandle, LOCK_EX)) {
    $raw = stream_get_contents($lockHandle);
    $attempts = $raw ? (json_decode($raw, true) ?: []) : [];
    if (!is_array($attempts)) {
      $attempts = [];
    }

    foreach ($attempts as $key => $timestamps) {
      if (!is_array($timestamps)) {
        unset($attempts[$key]);
        continue;
      }
      $attempts[$key] = array_values(array_filter($timestamps, static fn($t) => is_int($t) && ($now - $t) < $rateLimitWindow));
      if ($attempts[$key] === []) {
        unset($attempts[$key]);
      }
    }

    $rateLimited = count($attempts[$ipKey] ?? []) >= $rateLimitMax;
    if (!$rateLimited) {
      $attempts[$ipKey][] = $now;
    }

    ftruncate($lockHandle, 0);
    rewind($lockHandle);
    fwrite($lockHandle, json_encode($attempts));
    fflush($lockHandle);
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    @chmod($rateFile, 0600);
  }

  if ($rateLimited) {
    http_response_code(429);
    $status = 'error';
    $mensagemFeedback = 'Você atingiu o limite de envios. Aguarde alguns minutos antes de tentar novamente.';
  } else {
    $hp = trim((string) ($_POST['empresa'] ?? ''));
    if ($hp !== '') {
      http_response_code(204);
      exit;
    }

    $nome = sanitizeLine((string) ($_POST['nome'] ?? ''));
    $email = sanitizeLine((string) ($_POST['email'] ?? ''));
    $mensagem = trim(str_replace("\0", '', (string) ($_POST['mensagem'] ?? '')));
    $lgpd = (string) ($_POST['lgpd'] ?? '');

    $turnstileOk = false;
    $turnstileToken = (string) ($_POST['cf-turnstile-response'] ?? '');
    $turnstileSecret = (string) ($_ENV['TURNSTILE_SECRET'] ?? '');

    if ($turnstileToken !== '' && $turnstileSecret !== '' && function_exists('curl_init')) {
      $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
      curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
          'secret'   => $turnstileSecret,
          'response' => $turnstileToken,
          'remoteip' => clientIp(),
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_PROTOCOLS => defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
      ]);
      $turnstileRaw = curl_exec($ch);
      $curlOk = $turnstileRaw !== false && curl_errno($ch) === 0;
      curl_close($ch);

      $turnstileData = $curlOk ? json_decode($turnstileRaw, true) : null;
      $turnstileOk = is_array($turnstileData)
        && !empty($turnstileData['success'])
        && isAllowedHost((string) ($turnstileData['hostname'] ?? ''));
    }

    if (!$turnstileOk) {
      http_response_code(400);
      $status = 'error';
      $mensagemFeedback = 'Não foi possível confirmar que você não é um robô. Atualize a página e tente novamente.';
    } elseif ($nome === '' || $email === '' || $mensagem === '') {
      http_response_code(400);
      $status = 'error';
      $mensagemFeedback = 'Por favor, preencha todos os campos do formulário.';
    } elseif ($lgpd !== '1') {
      http_response_code(400);
      $status = 'error';
      $mensagemFeedback = 'Para enviar a mensagem, é necessário concordar com a Política de Privacidade.';
    } elseif (fieldLength($nome) > 120 || fieldLength($email) > 120 || fieldLength($mensagem) > 3000) {
      http_response_code(400);
      $status = 'error';
      $mensagemFeedback = 'Um ou mais campos excedem o tamanho permitido. Revise o texto e tente novamente.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      http_response_code(400);
      $status = 'error';
      $mensagemFeedback = 'O e-mail informado não parece ser válido. Verifique e tente novamente.';
    } else {
      $destinoEmail = 'ednave@grupoednave.com.br';
      $destinoNome  = 'Ednave / Edlopes - Contacto';

      $workspaceUser = (string) ($_ENV['SMTP_USER'] ?? '');
      $appPassword   = (string) ($_ENV['SMTP_PASS'] ?? '');

      if ($workspaceUser === '' || $appPassword === '') {
        http_response_code(500);
        $status = 'error';
        $mensagemFeedback = 'Não foi possível enviar sua mensagem no momento. Por favor, tente mais tarde.';
        error_log('Erro de configuração: SMTP_USER ou SMTP_PASS ausentes no .env');
      } else {
        $assunto = 'Novo contato do site (Edlopes)';

        $bodyHtml = "<h2>Novo contato do site do Grupo Ednave / Edlopes</h2>";
        $bodyHtml .= "<p><strong>Nome:</strong> " . htmlspecialchars($nome, ENT_QUOTES, 'UTF-8') . "</p>";
        $bodyHtml .= "<p><strong>Email:</strong> " . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . "</p>";
        $bodyHtml .= "<p><strong>Mensagem:</strong><br>" . nl2br(htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8')) . "</p>";

        $bodyTxt = "Novo contato do site\n\n";
        $bodyTxt .= "Nome: " . $nome . "\n";
        $bodyTxt .= "Email: " . $email . "\n\n";
        $bodyTxt .= "Mensagem:\n" . $mensagem . "\n";

        $mail = new PHPMailer(true);

        try {
          $mail->isSMTP();
          $mail->Host = 'smtp.gmail.com';
          $mail->SMTPAuth = true;
          $mail->Username = $workspaceUser;
          $mail->Password = $appPassword;
          $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
          $mail->Port = 587;
          $mail->CharSet = 'UTF-8';
          $mail->Timeout = 15;
          $mail->SMTPDebug = 0;

          $mail->setFrom($workspaceUser, 'Formulário do Site');
          $mail->addReplyTo($email, $nome);
          $mail->addAddress($destinoEmail, $destinoNome);

          $mail->isHTML(true);
          $mail->Subject = $assunto;
          $mail->Body    = $bodyHtml;
          $mail->AltBody = $bodyTxt;

          $mail->send();
          $status = 'success';
          $mensagemFeedback = "Obrigado, <strong>" . htmlspecialchars($nome, ENT_QUOTES, 'UTF-8') . "</strong>! Sua mensagem foi enviada com sucesso e em breve entraremos em contato.";
        } catch (Exception $e) {
          http_response_code(500);
          $status = 'error';
          $mensagemFeedback = "Não foi possível enviar sua mensagem no momento. Por favor, tente mais tarde.";
          error_log('SMTP Error: falha ao enviar e-mail do formulário de contato');
        }
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Contato | Edlopes Transportes</title>
  <link rel="icon" href="favicon.ico" type="image/x-icon">
  <link rel="stylesheet" href="css/layout.css">
  <link rel="stylesheet" href="css/feedback.css">
</head>
<body class="feedback-page">
  <div class="feedback-card">
    <?php if ($status === 'success'): ?>
      <p class="status-tag success">// Envio confirmado</p>
      <h1>Mensagem enviada</h1>
    <?php else: ?>
      <p class="status-tag error">// Falha no envio</p>
      <h1>Não foi possível enviar</h1>
    <?php endif; ?>

    <p><?php echo $mensagemFeedback; ?></p>

    <a href="faleconosco.html" class="btn">Voltar ao formulário</a>
  </div>
</body>
</html>
