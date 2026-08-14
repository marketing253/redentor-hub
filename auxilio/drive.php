<?php
/**
 * Google Drive — obtenção de token e upload de arquivo.
 * Dois modos, definidos em config.php → 'drive' => ['modo' => ...]
 *
 *  'oauth'   — usa client_id, client_secret e refresh_token de uma conta Google
 *              (é o modo do Hub; os arquivos ficam no Drive dessa conta)
 *  'servico' — usa o JSON de uma conta de serviço do Google Cloud
 *              ATENÇÃO: conta de serviço não tem espaço próprio no Drive.
 *              Só funciona se a pasta estiver num Drive compartilhado (Shared Drive).
 */
declare(strict_types=1);

/** @return array{0:?string,1:string} [token, erro] */
function driveToken(array $cfg): array {
    $d = $cfg['drive'] ?? [];
    $modo = (string)($d['modo'] ?? 'oauth');

    if ($modo === 'oauth') {
        foreach (['client_id', 'client_secret', 'refresh_token'] as $k) {
            if (empty($d[$k])) return [null, "Falta '$k' no bloco drive do config.php"];
        }
        $post = [
            'client_id'     => $d['client_id'],
            'client_secret' => $d['client_secret'],
            'refresh_token' => $d['refresh_token'],
            'grant_type'    => 'refresh_token',
        ];
    } else {
        $arq = (string)($d['arquivo_credencial'] ?? '');
        if (!is_file($arq)) return [null, "JSON da conta de serviço não encontrado em $arq"];
        $j = json_decode((string)file_get_contents($arq), true);
        if (empty($j['private_key']) || empty($j['client_email'])) {
            return [null, 'JSON da conta de serviço inválido'];
        }
        $agora = time();
        $cab   = rtrim(strtr(base64_encode((string)json_encode(['alg' => 'RS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
        $corpo = rtrim(strtr(base64_encode((string)json_encode([
            'iss'   => $j['client_email'],
            'scope' => (string)($d['escopo'] ?? 'https://www.googleapis.com/auth/drive.file'),
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $agora + 3600,
            'iat'   => $agora,
        ])), '+/', '-_'), '=');
        $assinatura = '';
        if (!openssl_sign("$cab.$corpo", $assinatura, $j['private_key'], 'sha256WithRSAEncryption')) {
            return [null, 'Falha ao assinar o token (openssl)'];
        }
        $jwt  = "$cab.$corpo." . rtrim(strtr(base64_encode($assinatura), '+/', '-_'), '=');
        $post = ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt];
    }

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
    ]);
    $r = curl_exec($ch);
    if ($r === false) { $e = curl_error($ch); curl_close($ch); return [null, "Conexão: $e"]; }
    curl_close($ch);
    $j = json_decode((string)$r, true);
    if (empty($j['access_token'])) {
        return [null, 'Google recusou: ' . substr((string)$r, 0, 200)];
    }
    return [$j['access_token'], ''];
}

/** Envia um arquivo para a pasta indicada. @return array{0:bool,1:string} [ok, id ou erro] */
function driveEnvia(array $cfg, string $caminho, string $nome, string $mime = 'application/zip'): array {
    if (!is_file($caminho)) return [false, "Arquivo não encontrado: $caminho"];
    [$token, $erro] = driveToken($cfg);
    if (!$token) return [false, $erro];

    $pasta = (string)(($cfg['drive']['pasta_id']) ?? '');
    $meta  = ['name' => $nome];
    if ($pasta !== '') $meta['parents'] = [$pasta];

    $lim  = '-------' . bin2hex(random_bytes(8));
    $body = "--$lim\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n"
          . json_encode($meta) . "\r\n"
          . "--$lim\r\nContent-Type: $mime\r\n\r\n"
          . file_get_contents($caminho) . "\r\n--$lim--";

    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart'
                  . '&supportsAllDrives=true&fields=id,name');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Content-Type: multipart/related; boundary=$lim",
            'Content-Length: ' . strlen($body),
        ],
    ]);
    $r    = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($r === false) { $e = curl_error($ch); curl_close($ch); return [false, "Conexão: $e"]; }
    curl_close($ch);
    $j = json_decode((string)$r, true);
    if ($http >= 300 || empty($j['id'])) return [false, "HTTP $http — " . substr((string)$r, 0, 250)];
    return [true, (string)$j['id']];
}
