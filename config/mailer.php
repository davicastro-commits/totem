<?php
/**
 * mailer.php — Envio SMTP manual via sockets PHP puro (sem Composer/PHPMailer).
 *
 * Suporte:
 *  - Porta 587 : STARTTLS
 *  - Porta 465 : SSL/TLS direto (smtps)
 *  - Porta 25  : sem TLS
 *  - AUTH LOGIN (base64)
 *  - Corpo MIME multipart/alternative (text/plain + text/html)
 *
 * Uso:
 *   $cfg = ['host'=>'smtp.gmail.com','port'=>587,'user'=>'x@g.com',
 *           'pass'=>'senha','from'=>'x@g.com','from_nome'=>'Café'];
 *   $r = smtpSend('dest@ex.com', 'Assunto', '<b>HTML</b>', $cfg);
 *   if ($r !== true) echo "Erro: $r";
 */

/**
 * Envia e-mail via SMTP com autenticação LOGIN.
 *
 * @param string $to        Destinatário (pode ser "Nome <email>" ou só "email")
 * @param string $subject   Assunto
 * @param string $htmlBody  Corpo HTML
 * @param array  $config    ['host','port','user','pass','from','from_nome']
 * @return true|string      true em sucesso, string com mensagem de erro em falha
 */
function smtpSend(string $to, string $subject, string $htmlBody, array $config): bool|string
{
    $host     = $config['host']      ?? '';
    $port     = (int)($config['port'] ?? 587);
    $user     = $config['user']      ?? '';
    $pass     = $config['pass']      ?? '';
    $from     = $config['from']      ?? $user;
    $fromNome = $config['from_nome'] ?? '';

    if (empty($host)) return 'SMTP host não configurado.';
    if (empty($from)) return 'E-mail remetente não configurado.';
    if (empty($to))   return 'E-mail destinatário vazio.';

    // Extrai apenas o endereço de e-mail de strings como "Nome <email>"
    $fromAddr = _smtpExtractAddr($from);
    $toAddr   = _smtpExtractAddr($to);

    $timeout = 15;
    $ssl     = ($port === 465);
    $remote  = ($ssl ? 'ssl://' : '') . $host;

    // ── Abre conexão TCP ─────────────────────────────────────────────────────
    $errno  = 0;
    $errstr = '';
    $sock = @fsockopen($remote, $port, $errno, $errstr, $timeout);
    if (!$sock) {
        return "Não foi possível conectar a {$host}:{$port} — {$errstr} ({$errno})";
    }
    stream_set_timeout($sock, $timeout);

    // Helper: lê uma resposta SMTP (possivelmente multi-linha)
    $read = function() use ($sock): string {
        $buf = '';
        while (!feof($sock)) {
            $line = fgets($sock, 512);
            if ($line === false) break;
            $buf .= $line;
            // Linha simples ou última linha de multi-linha: 4o char é espaço
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $buf;
    };

    // Helper: envia comando e retorna resposta
    $cmd = function(string $command) use ($sock, $read): string {
        fwrite($sock, $command . "\r\n");
        return $read();
    };

    // Helper: verifica código de resposta esperado
    $expect = function(string $resp, string $code) use ($sock): bool|string {
        if (strncmp($resp, $code, strlen($code)) === 0) return true;
        fclose($sock);
        return "Resposta SMTP inesperada (esperava {$code}): " . trim($resp);
    };

    // ── Saudação inicial ─────────────────────────────────────────────────────
    $greeting = $read();
    $chk = $expect($greeting, '220');
    if ($chk !== true) return $chk;

    // ── EHLO ─────────────────────────────────────────────────────────────────
    $ehloResp = $cmd('EHLO ' . gethostname());
    $chk = $expect($ehloResp, '250');
    if ($chk !== true) return $chk;

    // ── STARTTLS (apenas porta 587) ──────────────────────────────────────────
    if ($port === 587) {
        $tlsResp = $cmd('STARTTLS');
        $chk = $expect($tlsResp, '220');
        if ($chk !== true) return $chk;

        if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($sock);
            return 'Falha ao negociar TLS (STARTTLS).';
        }

        // Repete EHLO após TLS
        $ehloResp = $cmd('EHLO ' . gethostname());
        $chk = $expect($ehloResp, '250');
        if ($chk !== true) return $chk;
    }

    // ── AUTH LOGIN ───────────────────────────────────────────────────────────
    if (!empty($user)) {
        $authResp = $cmd('AUTH LOGIN');
        $chk = $expect($authResp, '334');
        if ($chk !== true) return $chk;

        $userResp = $cmd(base64_encode($user));
        $chk = $expect($userResp, '334');
        if ($chk !== true) return 'Credencial SMTP inválida (usuário): ' . trim($userResp);

        $passResp = $cmd(base64_encode($pass));
        $chk = $expect($passResp, '235');
        if ($chk !== true) return 'Credencial SMTP inválida (senha): ' . trim($passResp);
    }

    // ── MAIL FROM ────────────────────────────────────────────────────────────
    $r = $cmd("MAIL FROM:<{$fromAddr}>");
    $chk = $expect($r, '250');
    if ($chk !== true) return $chk;

    // ── RCPT TO ──────────────────────────────────────────────────────────────
    $r = $cmd("RCPT TO:<{$toAddr}>");
    $chk = $expect($r, '250');
    if ($chk !== true) return $chk;

    // ── DATA ─────────────────────────────────────────────────────────────────
    $r = $cmd('DATA');
    $chk = $expect($r, '354');
    if ($chk !== true) return $chk;

    // Monta o e-mail MIME
    $boundary = '----=_Part_' . md5(uniqid('', true));
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $fromHeader = empty($fromNome)
        ? $fromAddr
        : '=?UTF-8?B?' . base64_encode($fromNome) . '?= <' . $fromAddr . '>';

    // Versão text/plain simples: strip tags
    $plainBody = wordwrap(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $htmlBody)), 76, "\n", true);

    $headers  = "From: {$fromHeader}\r\n";
    $headers .= "To: {$to}\r\n";
    $headers .= "Subject: {$encodedSubject}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "X-Mailer: CafeComunhao-PHP-Mailer/1.0\r\n";
    $headers .= "Date: " . date('r') . "\r\n";

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($plainBody)) . "\r\n";

    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";

    $body .= "--{$boundary}--\r\n";

    // Protege pontos solitários no início de linha (transparência RFC 5321)
    $message = $headers . "\r\n" . $body;
    $message = preg_replace('/^\.$/m', '..', $message);

    fwrite($sock, $message);
    $r = $cmd('.');
    $chk = $expect($r, '250');
    if ($chk !== true) return $chk;

    // ── QUIT ─────────────────────────────────────────────────────────────────
    $cmd('QUIT');
    fclose($sock);

    return true;
}

/**
 * Extrai o endereço de e-mail de uma string como "Nome <email@ex.com>" ou "email@ex.com".
 */
function _smtpExtractAddr(string $str): string
{
    if (preg_match('/<([^>]+)>/', $str, $m)) {
        return trim($m[1]);
    }
    return trim($str);
}
