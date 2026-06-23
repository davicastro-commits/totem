<?php
/**
 * PIX EMV QR Code payload builder (BR Code / PIX Bacen spec).
 *
 * Generates a valid static PIX payload that any PIX-compatible
 * banking app can scan to initiate a payment.
 *
 * Usage:
 *   $payload = pixBuildPayload(
 *       chave:        '00.307.447/0001-08',
 *       valor:        25.90,
 *       beneficiario: 'Cafe Comunhao',
 *       cidade:       'Sao Paulo',
 *       txid:         'PED0042'
 *   );
 */

declare(strict_types=1);

function pixTlv(string $id, string $value): string
{
    return $id . str_pad((string)strlen($value), 2, '0', STR_PAD_LEFT) . $value;
}

function pixCrc16(string $str): string
{
    $crc = 0xFFFF;
    for ($i = 0, $len = strlen($str); $i < $len; $i++) {
        $crc ^= ord($str[$i]) << 8;
        for ($j = 0; $j < 8; $j++) {
            $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
            $crc &= 0xFFFF;
        }
    }
    return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
}

/**
 * Build a static PIX EMV payload.
 *
 * @param  string $chave        PIX key (CPF, CNPJ, email, phone, random UUID)
 * @param  float  $valor        Amount in BRL (0 = any amount, customer types it)
 * @param  string $beneficiario Recipient name (max 25 ASCII chars)
 * @param  string $cidade       Recipient city (max 15 ASCII chars)
 * @param  string $txid         Transaction ID shown in the transfer (max 25 chars, alphanumeric)
 */
function pixBuildPayload(
    string $chave,
    float  $valor,
    string $beneficiario,
    string $cidade,
    string $txid = '***'
): string {
    // Sanitize
    $beneficiario = mb_substr(preg_replace('/[^\x20-\x7E]/', '', $beneficiario), 0, 25);
    $cidade       = mb_substr(preg_replace('/[^\x20-\x7E]/', '', $cidade),       0, 15);
    $txid         = mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $txid ?: '***'), 0, 25) ?: '***';

    if (empty($beneficiario)) $beneficiario = 'Loja';
    if (empty($cidade))       $cidade       = 'Brasil';

    // ID 26: Merchant Account Info (PIX)
    $mai = pixTlv('00', 'br.gov.bcb.pix')
         . pixTlv('01', $chave);
    $field26 = pixTlv('26', $mai);

    // ID 62: Additional Data
    $field62 = pixTlv('62', pixTlv('05', $txid));

    // Compose payload (without CRC)
    $payload = pixTlv('00', '01')                        // Payload format
             . pixTlv('01', '12')                        // Point of initiation: 12=single use, 11=reusable
             . $field26                                  // PIX key
             . pixTlv('52', '0000')                      // MCC (not determined)
             . pixTlv('53', '986')                       // Currency BRL
             . ($valor > 0
                 ? pixTlv('54', number_format($valor, 2, '.', ''))
                 : '')                                   // Amount (optional)
             . pixTlv('58', 'BR')                        // Country
             . pixTlv('59', $beneficiario)               // Merchant name
             . pixTlv('60', $cidade)                     // Merchant city
             . $field62                                  // Reference label
             . '6304';                                   // CRC placeholder

    return $payload . pixCrc16($payload);
}
