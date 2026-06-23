<?php
/**
 * ESC/POS printer driver — sends to networked thermal printer via TCP.
 * Compatible with: Epson TM series, Bematech MP series, Elgin i9.
 *
 * Usage:
 *   $esc = new EscPos(42); // 80mm paper = 42 cols
 *   $esc->init()->center()->bold(true)->line('MINHA LOJA')->bold(false)
 *       ->divider()->row('Produto x2', 'R$ 20,00')->cut()
 *       ->send('192.168.1.100');
 */

declare(strict_types=1);

class EscPos
{
    // ── Commands ───────────────────────────────────────────────────────
    const INIT        = "\x1B\x40";
    const CENTER      = "\x1B\x61\x01";
    const LEFT        = "\x1B\x61\x00";
    const RIGHT       = "\x1B\x61\x02";
    const BOLD_ON     = "\x1B\x45\x01";
    const BOLD_OFF    = "\x1B\x45\x00";
    const DOUBLE_ON   = "\x1D\x21\x11";  // Double height+width
    const DOUBLE_OFF  = "\x1D\x21\x00";
    const LF          = "\x0A";
    const CUT_FULL    = "\x1D\x56\x00";
    const CUT_PARTIAL = "\x1D\x56\x41\x03";
    const CODEPAGE    = "\x1B\x74\x02";  // CP850 Multilingual

    private string $buf  = '';
    private int    $cols = 42;

    public function __construct(int $cols = 42)
    {
        $this->cols = $cols;
    }

    public function init(): static
    {
        $this->buf .= self::INIT . self::CODEPAGE;
        return $this;
    }

    public function center(): static { $this->buf .= self::CENTER; return $this; }
    public function left():   static { $this->buf .= self::LEFT;   return $this; }
    public function right():  static { $this->buf .= self::RIGHT;  return $this; }

    public function bold(bool $on): static
    {
        $this->buf .= $on ? self::BOLD_ON : self::BOLD_OFF;
        return $this;
    }

    public function big(bool $on): static
    {
        $this->buf .= $on ? self::DOUBLE_ON : self::DOUBLE_OFF;
        return $this;
    }

    /** Write raw text (UTF-8 converted to CP850). */
    public function text(string $text): static
    {
        $encoded = @iconv('UTF-8', 'CP850//TRANSLIT//IGNORE', $text);
        $this->buf .= ($encoded !== false) ? $encoded : $text;
        return $this;
    }

    /** Write text + newline. */
    public function line(string $text = ''): static
    {
        return $this->text($text . self::LF);
    }

    /** Horizontal divider. */
    public function divider(string $char = '-'): static
    {
        return $this->line(str_repeat($char, $this->cols));
    }

    /** Two-column row (left text, right text). */
    public function row(string $left, string $right): static
    {
        $leftLen  = mb_strlen($left,  'UTF-8');
        $rightLen = mb_strlen($right, 'UTF-8');
        $spaces   = max(1, $this->cols - $leftLen - $rightLen);
        return $this->line($left . str_repeat(' ', $spaces) . $right);
    }

    /** Center a line by padding. */
    public function centerLine(string $text): static
    {
        $len = mb_strlen($text, 'UTF-8');
        $pad = max(0, intval(($this->cols - $len) / 2));
        return $this->text(str_repeat(' ', $pad))->line($text);
    }

    public function feed(int $lines = 1): static
    {
        $this->buf .= str_repeat(self::LF, $lines);
        return $this;
    }

    public function cut(bool $partial = true): static
    {
        $this->buf .= $partial ? self::CUT_PARTIAL : self::CUT_FULL;
        return $this;
    }

    /**
     * Print QR Code via GS ( k sequence (ESC/POS standard).
     * Works on most Epson TM, Bematech, Elgin, Daruma, etc.
     *
     * @param string $data    URL or text to encode
     * @param int    $size    Module size 1–8 (default 4 ~ ~50mm)
     */
    public function qrCode(string $data, int $size = 4): static
    {
        $encoded = @iconv('UTF-8', 'CP850//TRANSLIT//IGNORE', $data) ?: $data;
        $len     = strlen($encoded) + 3;
        $pL      = $len & 0xFF;
        $pH      = ($len >> 8) & 0xFF;

        // Select model 2
        $this->buf .= "\x1D\x28\x6B\x04\x00\x31\x41\x32\x00";
        // Set module size
        $this->buf .= "\x1D\x28\x6B\x03\x00\x31\x43" . chr($size);
        // Error correction level M
        $this->buf .= "\x1D\x28\x6B\x03\x00\x31\x45\x31";
        // Store data
        $this->buf .= "\x1D\x28\x6B" . chr($pL) . chr($pH) . "\x31\x50\x30" . $encoded;
        // Print
        $this->buf .= "\x1D\x28\x6B\x03\x00\x31\x51\x30";

        return $this;
    }

    /** Get raw ESC/POS bytes. */
    public function getBytes(): string
    {
        return $this->buf;
    }

    /**
     * Send to networked printer via TCP socket.
     * @throws RuntimeException on connection failure
     */
    public function send(string $ip, int $port = 9100, int $timeoutSec = 3): bool
    {
        if (empty($ip)) {
            throw new RuntimeException('IP da impressora não configurado.');
        }

        $sock = @fsockopen($ip, $port, $errno, $errstr, $timeoutSec);
        if (!$sock) {
            throw new RuntimeException("Impressora {$ip}:{$port} inacessível — {$errstr} ({$errno})");
        }

        $bytes = $this->buf;
        while (strlen($bytes) > 0) {
            $sent = fwrite($sock, $bytes);
            if ($sent === false) break;
            $bytes = substr($bytes, $sent);
        }

        fclose($sock);
        return true;
    }
}


/**
 * Build and return an ESC/POS receipt for a given order array.
 * Order array shape matches what api/pedido.php returns.
 */
function buildEscPosReceipt(array $pedido, array $cfg, int $cols = 42): EscPos
{
    $esc      = new EscPos($cols);
    $nomeLoja = mb_strtoupper($cfg['loja_nome']    ?? 'LOJA',     'UTF-8');
    $cnpj     = $cfg['loja_cnpj']     ?? '';
    $endereco = $cfg['loja_endereco'] ?? '';
    $telefone = $cfg['loja_telefone'] ?? '';
    $statusBase = $cfg['loja_url']    ?? '';   // e.g. http://192.168.1.10/totem

    $numero   = $pedido['numero']          ?? $pedido['numero_pedido'] ?? '';
    $data     = $pedido['criado_em']       ?? date('d/m/Y H:i');
    $total    = (float)($pedido['total']   ?? 0);
    $itens    = $pedido['itens']           ?? [];
    $pgFmt    = [
        'pix'      => 'Pagamento Instantaneo (PIX)',
        'credito'  => 'Cartao de Credito',
        'debito'   => 'Cartao de Debito',
        'dinheiro' => 'Dinheiro',
    ][$pedido['forma_pagamento'] ?? ''] ?? strtoupper($pedido['forma_pagamento'] ?? '');

    // ── Cabeçalho ──────────────────────────────────────────────────────
    $esc->init()
        ->center()
        ->bold(true)->big(true)->line($nomeLoja)->big(false)->bold(false);

    if ($cnpj)     $esc->line('CNPJ: ' . $cnpj);
    if ($endereco) $esc->line($endereco);
    if ($telefone) $esc->line('Tel: ' . $telefone);

    $esc->line()
        ->bold(true)->centerLine('** CUPOM NAO FISCAL **')->bold(false)
        ->divider('=');

    // ── Identificação do pedido ────────────────────────────────────────
    $esc->left()
        ->row('Pedido: #' . $numero, $data)
        ->line('Consumo: ' . ($pedido['tipo_consumo'] === 'local' ? 'COMER AQUI' : 'PARA VIAGEM'));

    if (!empty($pedido['cpf'])) {
        $esc->line('CPF: ' . $pedido['cpf']);
    }

    $esc->divider('=');

    // ── Tabela de itens ────────────────────────────────────────────────
    // Header row: # | Descricao | Qtd | Un | Vl Unit | Total
    $totalItens = 0;
    $seq = 1;

    $esc->bold(true);
    $hdr = str_pad('#', 4) . str_pad('Descricao', $cols - 22) . str_pad('Qtd', 4) . str_pad('Vl Unit', 8, ' ', STR_PAD_LEFT) . str_pad('Total', 6, ' ', STR_PAD_LEFT);
    $esc->line(mb_substr($hdr, 0, $cols, 'UTF-8'))->bold(false)->divider();

    foreach ($itens as $item) {
        $nome    = $item['nome_produto'] ?? $item['nome'] ?? '';
        $qty     = (int)($item['quantidade'] ?? 1);
        $unit    = (float)($item['preco_unitario'] ?? ($item['subtotal'] ?? 0) / max(1, $qty));
        $sub     = (float)($item['subtotal'] ?? $unit * $qty);
        $obs     = $item['obs'] ?? '';
        $totalItens += $qty;

        $seqStr  = str_pad((string)$seq++, 3, '0', STR_PAD_LEFT) . ' ';
        $maxNome = $cols - 22;
        $nomeShort = mb_strtoupper(mb_substr($nome, 0, $maxNome, 'UTF-8'), 'UTF-8');
        $unitStr = 'R$' . number_format($unit, 2, ',', '.');
        $subStr  = 'R$' . number_format($sub,  2, ',', '.');

        // Line 1: seq + name
        $line = $seqStr . str_pad($nomeShort, $maxNome);
        $line .= str_pad((string)$qty, 4);
        $line .= str_pad($unitStr, 8, ' ', STR_PAD_LEFT);
        $line .= str_pad($subStr,  6, ' ', STR_PAD_LEFT);
        $esc->line(mb_substr($line, 0, $cols, 'UTF-8'));

        if ($obs) {
            $esc->line('    Obs: ' . mb_strtoupper(mb_substr($obs, 0, $cols - 9, 'UTF-8'), 'UTF-8'));
        }
    }

    $esc->divider('=');

    // ── Totais ────────────────────────────────────────────────────────
    $esc->row('QTD. TOTAL DE ITENS', (string)$totalItens)
        ->bold(true)
        ->row('VALOR TOTAL R$', number_format($total, 2, ',', '.'))
        ->bold(false)
        ->divider('=');

    // ── Pagamento ─────────────────────────────────────────────────────
    $esc->line('FORMA DE PAGAMENTO')
        ->row($pgFmt, 'Valor Pago')
        ->row('', number_format($total, 2, ',', '.'))
        ->divider('=');

    // ── QR de rastreio ────────────────────────────────────────────────
    if ($statusBase && $numero) {
        $statusUrl = rtrim($statusBase, '/') . '/status/?p=' . $numero;
        $esc->center()
            ->line('Acompanhe seu pedido:')
            ->qrCode($statusUrl, 4)
            ->feed(1)
            ->line(mb_substr($statusUrl, 0, $cols, 'UTF-8'))
            ->divider();
    }

    $esc->center()
        ->line('Obrigado pela preferencia!')
        ->line('Volte sempre!')
        ->feed(4)
        ->cut();

    return $esc;
}
