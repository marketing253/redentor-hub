<?php
/**
 * Envio de e-mail por SMTP autenticado (caixa da Hostinger).
 * Usado pelo cadastro de alunos (api) e pelos lembretes (avisos.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/Exception.php';
require_once __DIR__ . '/lib/PHPMailer.php';
require_once __DIR__ . '/lib/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

/**
 * @return array{0:bool,1:string}  [enviado, mensagem de erro]
 */
function enviaEmail(array $cfg, string $para, string $assunto, string $corpoTexto,
                    ?string $corpoHtml = null, bool $diagnostico = false): array {
    if (!filter_var($para, FILTER_VALIDATE_EMAIL)) return [false, 'E-mail inválido: ' . $para];
    $s = $cfg['smtp'] ?? [];
    if (empty($s['usuario'])) return [false, 'SMTP não configurado no config.php'];

    $m = new PHPMailer(true);
    $trilha = '';
    try {
        $m->isSMTP();
        if ($diagnostico) {
            $m->SMTPDebug   = 2;
            $m->Debugoutput = function ($str) use (&$trilha) { $trilha .= trim($str) . "\n"; };
        }
        $m->Host       = $s['host'] ?? 'smtp.hostinger.com';
        $m->Port       = (int)($s['porta'] ?? 465);
        $m->SMTPAuth   = true;
        $m->Username   = $s['usuario'];
        $m->Password   = $s['senha'] ?? '';
        $m->SMTPSecure = ((int)($s['porta'] ?? 465) === 587)
                       ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        $m->CharSet    = 'UTF-8';
        $m->Timeout    = 20;

        $m->setFrom($s['usuario'], (string)($s['nome'] ?? 'Auxílio Graduação'));
        if (!empty($s['responder_para'])) $m->addReplyTo($s['responder_para']);
        $m->addAddress($para);
        $m->Subject = $assunto;

        if ($corpoHtml !== null) {
            $m->isHTML(true);
            $m->Body    = $corpoHtml;
            $m->AltBody = $corpoTexto;
        } else {
            $m->Body = $corpoTexto;
        }
        $m->send();
        return [true, $diagnostico ? $trilha : ''];
    } catch (Throwable $e) {
        $msg = $m->ErrorInfo ?: $e->getMessage();
        return [false, $diagnostico ? "$msg\n\n--- conversa com o servidor ---\n$trilha" : $msg];
    }
}

/** Molde HTML com a identidade da empresa. */
function moldeEmail(string $titulo, string $miolo, string $url = ''): string {
    $botao = $url === '' ? '' :
        '<p style="margin:26px 0 0"><a href="' . htmlspecialchars($url) . '"
          style="background:#3B4192;color:#fff;text-decoration:none;padding:12px 22px;
          border-radius:8px;display:inline-block;font-weight:600">Acessar o sistema</a></p>';
    return '<div style="font-family:Segoe UI,Arial,sans-serif;background:#f4f6f9;padding:24px">
      <div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;
        border:1px solid #dde3ea">
        <div style="background:#3B4192;border-bottom:3px solid #D9A93F;padding:16px 22px;color:#fff">
          <b style="font-size:16px;letter-spacing:.4px">Auto Viação Redentor</b><br>
          <span style="font-size:12px;color:#c6cbee">Auxílio Graduação</span>
        </div>
        <div style="padding:22px;color:#16202c;font-size:15px;line-height:1.6">
          <h2 style="margin:0 0 12px;font-size:17px;color:#3B4192">' . htmlspecialchars($titulo) . '</h2>'
          . $miolo . $botao .
        '</div>
        <div style="padding:14px 22px;background:#f4f6f9;color:#5b6775;font-size:12px">
          Mensagem automática — não responda a este e-mail.
        </div>
      </div></div>';
}
