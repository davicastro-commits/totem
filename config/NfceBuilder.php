<?php
/**
 * NfceBuilder — Gerador de XML NFC-e (modelo 65) seguindo schema 4.00
 * Fase 2 — dados fictícios para homologação/teste
 */
class NfceBuilder
{
    // Códigos IBGE de UF
    private const UF_CODES = [
        'AC'=>12,'AL'=>27,'AP'=>16,'AM'=>13,'BA'=>29,'CE'=>23,'DF'=>53,
        'ES'=>32,'GO'=>52,'MA'=>21,'MT'=>51,'MS'=>50,'MG'=>31,'PA'=>15,
        'PB'=>25,'PR'=>41,'PE'=>26,'PI'=>22,'RJ'=>33,'RN'=>24,'RS'=>43,
        'RO'=>11,'RR'=>14,'SC'=>42,'SP'=>35,'SE'=>28,'TO'=>17,
    ];

    // Município padrão (DF)
    private const MUN_DF = '5300108';

    // URLs QR Code por UF (homologação)
    private const QR_URLS_HOM = [
        'DF' => 'https://www.sefaz.df.gov.br/sat-nfe/nfce/qrcode',
        'SP' => 'https://homologacao.nfce.fazenda.sp.gov.br/consulta',
        'MG' => 'https://hnfce.fazenda.mg.gov.br/consultaWeb/consultaNFCe.aspx',
        'RJ' => 'https://homologacao.nfce.fazenda.rj.gov.br/consulta',
        'RS' => 'https://www.sefaz.rs.gov.br/NFCE/NFCE-COM-PORT-PROD-01/listaQR.aspx',
        'PR' => 'https://homologacao.nfce.sefa.pr.gov.br/nfce/qrcode',
    ];
    private const QR_URLS_PROD = [
        'DF' => 'https://www.sefaz.df.gov.br/sat-nfe/nfce/qrcode',
        'SP' => 'https://www.nfce.fazenda.sp.gov.br/consulta',
        'MG' => 'https://nfce.fazenda.mg.gov.br/consultaWeb/consultaNFCe.aspx',
        'RJ' => 'https://nfce.fazenda.rj.gov.br/consulta',
        'RS' => 'https://www.sefaz.rs.gov.br/NFCE/NFCE-COM-PORT-PROD-01/listaQR.aspx',
        'PR' => 'https://nfce.sefa.pr.gov.br/nfce/qrcode',
    ];

    // Formas de pagamento → código NF-e
    private const TPAG = [
        'dinheiro' => '01', 'cheque' => '02', 'credito' => '03',
        'debito'   => '04', 'voucher'=> '05', 'pix'     => '17',
        'outros'   => '99',
    ];

    private array $cfg;
    private array $pedido;
    private array $itens;
    private string $chave  = '';
    private string $cNF    = '';
    private string $dhEmi  = '';

    public function __construct(array $cfg, array $pedido, array $itens)
    {
        $this->cfg    = $cfg;
        $this->pedido = $pedido;
        $this->itens  = $itens;
        $this->dhEmi  = date('Y-m-d\TH:i:sP');
    }

    // ─── Chave de acesso (44 dígitos) ──────────────────────────────────────
    public function gerarChave(): string
    {
        $uf     = strtoupper($this->cfg['uf'] ?? 'DF');
        $cUF    = str_pad((string)(self::UF_CODES[$uf] ?? 53), 2, '0', STR_PAD_LEFT);
        $AAMM   = date('ym');
        $CNPJ   = str_pad(preg_replace('/\D/', '', $this->cfg['cnpj'] ?? '00000000000191'), 14, '0', STR_PAD_LEFT);
        $mod    = '65';
        $serie  = str_pad(preg_replace('/\D/', '', $this->cfg['serie'] ?? '001'), 3, '0', STR_PAD_LEFT);
        $nNF    = str_pad((string)($this->pedido['numero'] ?? 1), 9, '0', STR_PAD_LEFT);
        $tpEmis = '1';
        $this->cNF = str_pad((string)rand(10000000, 99999999), 8, '0', STR_PAD_LEFT);

        $sem_dv = $cUF . $AAMM . $CNPJ . $mod . $serie . $nNF . $tpEmis . $this->cNF;
        $dv     = $this->calcularDV($sem_dv);
        $this->chave = $sem_dv . $dv;
        return $this->chave;
    }

    private function calcularDV(string $chave): string
    {
        $pesos  = [2,3,4,5,6,7,8,9,2,3,4,5,6,7,8,9,2,3,4,5,6,7,8,9,
                   2,3,4,5,6,7,8,9,2,3,4,5,6,7,8,9,2,3,4];
        $soma   = 0;
        $digits = array_reverse(str_split($chave));
        foreach ($digits as $i => $d) {
            $soma += (int)$d * ($pesos[$i] ?? 2);
        }
        $resto = $soma % 11;
        return $resto < 2 ? '0' : (string)(11 - $resto);
    }

    // ─── QR Code (hash com CSC) ────────────────────────────────────────────
    public function gerarQrCode(): string
    {
        if (!$this->chave) $this->gerarChave();

        $csc    = $this->cfg['csc']    ?? 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF';
        $cscId  = str_pad($this->cfg['csc_id'] ?? '1', 6, '0', STR_PAD_LEFT);
        $tpAmb  = ($this->cfg['ambiente'] ?? 'homologacao') === 'producao' ? '1' : '2';

        $hashStr = $this->chave . '|' . $tpAmb . '|' . $cscId . '|' . $csc;
        $hash    = strtoupper(sha1($hashStr));

        $uf   = strtoupper($this->cfg['uf'] ?? 'DF');
        $urls = $tpAmb === '2' ? self::QR_URLS_HOM : self::QR_URLS_PROD;
        $base = $urls[$uf] ?? "https://www.sefaz.{$uf}.gov.br/nfce/qrcode";

        return $base . '?p=' . urlencode($this->chave . '|' . $tpAmb . '|' . $cscId . '|' . $hash);
    }

    public function gerarUrlChave(): string
    {
        $uf    = strtoupper($this->cfg['uf'] ?? 'DF');
        $tpAmb = ($this->cfg['ambiente'] ?? 'homologacao') === 'producao' ? 'producao' : 'homologacao';
        return "https://www.nfe.fazenda.gov.br/portal/consultaRecaptcha.aspx?tipoConsulta=completa&tipoConteudo=7PhJ+gAVw2g=&nfe={$this->chave}";
    }

    // ─── XML completo ───────────────────────────────────────────────────────
    public function gerarXml(): string
    {
        if (!$this->chave) $this->gerarChave();

        $qrCode  = $this->gerarQrCode();
        $urlChave= $this->gerarUrlChave();
        $tpAmb   = ($this->cfg['ambiente'] ?? 'homologacao') === 'producao' ? '1' : '2';
        $uf      = strtoupper($this->cfg['uf'] ?? 'DF');
        $cUF     = self::UF_CODES[$uf] ?? 53;
        $crt     = $this->cfg['regime'] ?? '1'; // 1=SN, 2=LPresumido, 3=LReal
        $CNPJ    = str_pad(preg_replace('/\D/', '', $this->cfg['cnpj'] ?? '00000000000191'), 14, '0', STR_PAD_LEFT);
        $IE      = $this->cfg['ie'] ?? 'ISENTO';
        $serie   = str_pad(preg_replace('/\D/', '', $this->cfg['serie'] ?? '001'), 3, '0', STR_PAD_LEFT);
        $nNF     = str_pad((string)($this->pedido['numero'] ?? 1), 9, '0', STR_PAD_LEFT);
        $total   = number_format((float)($this->pedido['total'] ?? 0), 2, '.', '');
        $loja    = htmlspecialchars($this->cfg['nome_loja'] ?? 'CAFÉ COMUNHÃO');

        // Pagamento
        $pgto     = strtolower($this->pedido['forma_pagamento'] ?? 'dinheiro');
        $tPag     = self::TPAG[$pgto] ?? '99';
        $vPag     = $total;

        // Itens
        $detXml = '';
        $nItem  = 0;
        $vProdTotal = 0;
        foreach ($this->itens as $item) {
            $nItem++;
            $vProd = number_format((float)($item['subtotal'] ?? ($item['quantidade'] * $item['preco_unitario'])), 2, '.', '');
            $vProdTotal += (float)$vProd;
            $qCom  = number_format((float)($item['quantidade'] ?? 1), 4, '.', '');
            $vUnit = number_format((float)($item['preco_unitario'] ?? 0), 10, '.', '');
            $nome  = mb_strtoupper(htmlspecialchars(substr($item['nome_produto'] ?? $item['nome'] ?? 'PRODUTO', 0, 120)));
            $ncm   = preg_replace('/\D/', '', $item['ncm'] ?? '21069090');
            $cfop  = $item['cfop'] ?? '5102';

            // ICMS por CRT
            $icmsXml = $crt === '1'
                ? "<ICMS><ICMSSN400><orig>0</orig><CSOSN>400</CSOSN></ICMSSN400></ICMS>"
                : "<ICMS><ICMS00><orig>0</orig><CST>00</CST><modBC>3</modBC><vBC>0.00</vBC><pICMS>0.00</pICMS><vICMS>0.00</vICMS></ICMS00></ICMS>";

            $detXml .= <<<DET
            <det nItem="{$nItem}">
              <prod>
                <cProd>{$nItem}</cProd>
                <cEAN>SEM GTIN</cEAN>
                <xProd>{$nome}</xProd>
                <NCM>{$ncm}</NCM>
                <CFOP>{$cfop}</CFOP>
                <uCom>UN</uCom>
                <qCom>{$qCom}</qCom>
                <vUnCom>{$vUnit}</vUnCom>
                <vProd>{$vProd}</vProd>
                <cEANTrib>SEM GTIN</cEANTrib>
                <uTrib>UN</uTrib>
                <qTrib>{$qCom}</qTrib>
                <vUnTrib>{$vUnit}</vUnTrib>
                <indTot>1</indTot>
              </prod>
              <imposto>
                {$icmsXml}
                <PIS><PISNT><CST>07</CST></PISNT></PIS>
                <COFINS><COFINSNT><CST>07</CST></COFINSNT></COFINS>
              </imposto>
            </det>
            DET;
        }
        $vProdFmt = number_format($vProdTotal, 2, '.', '');

        $infAdic = $tpAmb === '2'
            ? '<infAdic><infCpl>NFC-e emitida em ambiente de HOMOLOGACAO (teste). Sem validade fiscal.</infCpl></infAdic>'
            : '<infAdic><infCpl>Obrigado pela preferencia! Café Comunhão.</infCpl></infAdic>';

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
  <NFe xmlns="http://www.portalfiscal.inf.br/nfe">
    <infNFe Id="NFe{$this->chave}" versao="4.00">
      <ide>
        <cUF>{$cUF}</cUF>
        <cNF>{$this->cNF}</cNF>
        <natOp>VENDA AO CONSUMIDOR</natOp>
        <mod>65</mod>
        <serie>{$serie}</serie>
        <nNF>{$nNF}</nNF>
        <dhEmi>{$this->dhEmi}</dhEmi>
        <tpNF>1</tpNF>
        <idDest>1</idDest>
        <cMunFG>{self::MUN_DF}</cMunFG>
        <tpImp>4</tpImp>
        <tpEmis>1</tpEmis>
        <cDV>{$this->chave[43]}</cDV>
        <tpAmb>{$tpAmb}</tpAmb>
        <finNFe>1</finNFe>
        <indFinal>1</indFinal>
        <indPres>1</indPres>
        <procEmi>0</procEmi>
        <verProc>Café Comunhão v1.0</verProc>
      </ide>
      <emit>
        <CNPJ>{$CNPJ}</CNPJ>
        <xNome>{$loja}</xNome>
        <xFant>{$loja}</xFant>
        <enderEmit>
          <xLgr>SCS Quadra 4 Bloco A</xLgr>
          <nro>S/N</nro>
          <xBairro>Asa Sul</xBairro>
          <cMun>{self::MUN_DF}</cMun>
          <xMun>Brasilia</xMun>
          <UF>{$uf}</UF>
          <CEP>70304000</CEP>
          <cPais>1058</cPais>
          <xPais>Brasil</xPais>
        </enderEmit>
        <IE>{$IE}</IE>
        <CRT>{$crt}</CRT>
      </emit>
      {$detXml}
      <total>
        <ICMSTot>
          <vBC>0.00</vBC><vICMS>0.00</vICMS><vICMSDeson>0.00</vICMSDeson>
          <vFCP>0.00</vFCP><vBCST>0.00</vBCST><vST>0.00</vST>
          <vFCPST>0.00</vFCPST><vFCPSTRet>0.00</vFCPSTRet>
          <vProd>{$vProdFmt}</vProd><vFrete>0.00</vFrete><vSeg>0.00</vSeg>
          <vDesc>0.00</vDesc><vII>0.00</vII><vIPI>0.00</vIPI>
          <vIPIDevol>0.00</vIPIDevol><vPIS>0.00</vPIS><vCOFINS>0.00</vCOFINS>
          <vOutro>0.00</vOutro><vNF>{$total}</vNF>
        </ICMSTot>
      </total>
      <transp><modFrete>9</modFrete></transp>
      <pag>
        <detPag>
          <tPag>{$tPag}</tPag>
          <vPag>{$vPag}</vPag>
        </detPag>
      </pag>
      {$infAdic}
      <infNFeSupl>
        <qrCode><![CDATA[{$qrCode}]]></qrCode>
        <urlChave>{$urlChave}</urlChave>
      </infNFeSupl>
    </infNFe>
    <Signature xmlns="http://www.w3.org/2000/09/xmldsig#">
      <!-- Assinatura mock — substituir por assinatura real com certificado A1 na Fase 3 -->
      <SignedInfo><CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>
      <SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"/>
      <Reference URI="#NFe{$this->chave}"><Transforms><Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/>
      <Transform Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/></Transforms>
      <DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
      <DigestValue>MOCK_DIGEST_FASE2_TESTE</DigestValue></Reference></SignedInfo>
      <SignatureValue>MOCK_SIGNATURE_FASE2_TESTE</SignatureValue>
    </Signature>
  </NFe>
</nfeProc>
XML;
        return $xml;
    }

    public function getChave(): string  { return $this->chave; }
    public function getDhEmi(): string  { return $this->dhEmi; }
}
