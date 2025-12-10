#!/usr/bin/php
<?php
/**
 * Servidor GT06 em PHP (CLI)
 * Desenvolvedor. Fernando Ramos
 * (27) 99751-6427
 */

/* chamar comando via terminal:
 *   php -q gt06.php
 *
 * ou
 *   setsid nohup php gt06.php > /dev/null 2>&1 &
 *        
 * Verificar processos utilizando a porta escolhida: sudo lsof -i :7095
 * Finalizar processo: sudo kill -9 4021 (<<-pid do processo em execução)
*/

//exibit erros
error_reporting(E_ALL);
//mantem execução continua
set_time_limit(0);
//ignora fechamento da tela pelo usuário
ignore_user_abort(true);

/*DEFINE FUSO HORÁRIO BRASIL*/
date_default_timezone_set('America/Sao_Paulo');


// ================== CONFIGURAÇÃO ==================
$address = '0.0.0.0';  // IP do servidor
$port    = 7095;              // Porta configurada no rastreador

// Diretórios para logs e comandos
$baseDir   = __DIR__;
$logsDir   = $baseDir . '/logs';
$cmdDir    = $baseDir . '/comandos/arquivos';

// Tamanho máximo de cada arquivo de log de IMEI (15 MB)
define('GT06_MAX_LOG_BYTES', 20 * 1024 * 1024);

// Garante que os diretórios existem
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0777, true);
}
if (!is_dir($cmdDir)) {
    @mkdir($cmdDir, 0777, true);
}
// ==================================================

// --------- LOG GLOBAL EM ARQUIVO + TELA ---------
// Arquivo de log
$logFile = $logsDir . '/Log_Gt06.log';

// Abre o arquivo em modo leitura/escrita
$logHandle = fopen($logFile, 'a+');
if (!$logHandle) {
    die("Não foi possível criar/abrir o arquivo de log: {$logFile}\n");
}

function logMsg(string $msg): void
{
    global $logHandle, $logFile;

    clearstatcache();
    $currentSize = filesize($logFile);

    // Se estourou o limite definido na constante
    if ($currentSize !== false && $currentSize > (GT06_MAX_LOG_BYTES * 2)) {

        // Lê todas as linhas
        $lines = file($logFile, FILE_IGNORE_NEW_LINES);

        // Remove ~15% das linhas antigas
        $removeCount = intval(count($lines) * 0.15);
        if ($removeCount < 1) $removeCount = 1;

        $lines = array_slice($lines, $removeCount);

        // Reescreve arquivo reduzido
        file_put_contents($logFile, implode(PHP_EOL, $lines) . PHP_EOL);

        // Reabre o handle
        fclose($logHandle);
        $logHandle = fopen($logFile, 'a+');
    }

    // Linha a ser gravada
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;

    echo $line;

    if ($logHandle) {
        fwrite($logHandle, $line);
    }
}

/**
 * Log específico por IMEI, com rotação a 15 MB
 */
function deviceLogMsg(string $imei, string $msg): void
{
    global $logsDir;
    if ($imei === '') {
        return;
    }

    $file = $logsDir . '/Log_' . $imei . '.log';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;

    // Escreve
    file_put_contents($file, $line, FILE_APPEND);

    // Verifica tamanho e aplica rotação simples (mantém o final do arquivo)
    $size = @filesize($file);
    if ($size !== false && $size > GT06_MAX_LOG_BYTES) {
        $keepBytes = (int) (GT06_MAX_LOG_BYTES * 0.8); // mantém ~80% mais recente
        $fh = @fopen($file, 'r');
        if ($fh) {
            // Move o ponteiro para os últimos $keepBytes
            if ($keepBytes < $size) {
                fseek($fh, -$keepBytes, SEEK_END);
            }
            $data = fread($fh, $keepBytes);
            fclose($fh);

            // Opcional: ajusta para começar na próxima quebra de linha
            $pos = strpos($data, "\n");
            if ($pos !== false) {
                $data = substr($data, $pos + 1);
            }

            // Sobrescreve o arquivo com o trecho mais recente
            file_put_contents($file, $data);
        }
    }
}
// -----------------------------------------

$server = stream_socket_server("tcp://{$address}:{$port}", $errno, $errstr);
if (!$server) {
    logMsg("Erro ao criar servidor: $errstr ($errno)");
    exit(1);
}

logMsg("GT06 server ouvindo em {$address}:{$port}");

logMsg("Arquivo de log global: {$logFile}");

stream_set_blocking($server, false);

// Conexões e metadados por cliente
$clients        = []; // [id => resource]
$clientInfo     = []; // [id => ['peer' => string, 'imei' => ?string]]
$pendingCommands = []; // [imei => ['command' => 'BLOQUEAR'/'LIBERAR', 'file' => path, 'sent_at' => timestamp]]

while (true) {
    $read   = $clients;
    $read[] = $server;
    $write  = $except = null;

    if (stream_select($read, $write, $except, null) === false) {
        logMsg("Erro em stream_select");
        break;
    }

    // Nova conexão
    if (in_array($server, $read, true)) {
        $client = @stream_socket_accept($server, 0);
        if ($client) {
            $id = (int)$client;
            $clients[$id] = $client;
            stream_set_blocking($client, false);
            $peer = stream_socket_get_name($client, true);

            $clientInfo[$id] = [
                'peer' => $peer,
                'imei' => null,
                'cache' => ['acc_status'  => null, 'cut_status' => null, 'alarm_status' => null, 'charge_status' => null, 'low_batt_status' => null, 'voltage_level' => null, 'gsm_signal' => null, 'last_update' => time()]
            ];

            logMsg("Novo cliente conectado: #{$id} ({$peer})");
        }
        // remove o servidor da lista de leitura
        $serverIndex = array_search($server, $read, true);
        unset($read[$serverIndex]);
    }

    // Dados dos clientes
    foreach ($read as $client) {
        $id   = (int)$client;
        $data = @fread($client, 2048);

        if ($data === '' || $data === false) {
            // conexão fechada
            $peer = $clientInfo[$id]['peer'] ?? @stream_socket_get_name($client, true);
            logMsg("Cliente #{$id} desconectado ({$peer})");
            @fclose($client);
            unset($clients[$id], $clientInfo[$id]);
            continue;
        }

        if (strlen($data) === 0) {
            continue;
        }

        handleGt06Data($client, $data);
    }
}

/**
 * Trata o buffer recebido (pode conter 1 ou mais pacotes GT06).
 */
function handleGt06Data($client, string $data): void
{
    global $clientInfo, $pendingCommands, $cmdDir;

    $id  = (int)$client;
    $hex = bin2hex($data);
    #logMsg("RX BRUTO (#{$id}): {$hex}");

    // Quebra caso venham múltiplos pacotes no mesmo buffer
    $frames = splitGt06Frames($data);

    foreach ($frames as $frame) {
        $hexFrame = bin2hex($frame);
        logMsg("RX (#{$id}): {$hexFrame}");

        if (strlen($hexFrame) < 20) {
            logMsg("Frame muito pequeno para ser GT06 válido.");
            continue;
        }

        // GT06 clássico começa com 0x7878
        if (substr($hexFrame, 0, 4) !== '7878') {
            logMsg("Início diferente de 0x7878, ignorando.");
            continue;
        }

        // Byte 2 = length
        $lenHex = substr($hexFrame, 4, 2);
        $len    = hexdec($lenHex);

        // Protocolo
        $protocolHex = substr($hexFrame, 6, 2);

        // Tamanho total esperado do pacote: 2 (start) + 1 (len) + len + 2 (stop)
        $expectedTotalBytes = 2 + 1 + $len + 2;
        $expectedTotalHex   = $expectedTotalBytes * 2;

        if (strlen($hexFrame) < $expectedTotalHex) {
            logMsg("Frame incompleto. Esperado {$expectedTotalBytes} bytes, recebido "
                . (strlen($hexFrame) / 2) . " bytes");
            continue;
        }

        // SN começa a 4 bytes do final (SN(2)+CRC(2)) dentro da área de 'len'
        $lenHexStart   = 4;
        $snStartOffset = $len - 4;
        $snStartHexPos = $lenHexStart + $snStartOffset * 2;
        $serialHex     = substr($hexFrame, $snStartHexPos, 4);

        // IMEI (se já conhecido para este cliente)
        $imei = $clientInfo[$id]['imei'] ?? null;

        logMsg("PROTO: {$protocolHex}, SN: {$serialHex}, IMEI atual: " . ($imei ?: 'N/D'));

        // Estrutura base para JSON
        $frameInfo = [
            'imei'      => $imei,
            'protocol'  => $protocolHex,
            'serial'    => $serialHex,
            'raw'       => $hexFrame,
            'client_id' => $id
        ];

        switch ($protocolHex) {
            case '01': // Login
                logMsg(">> Pacote de LOGIN");

                // Tenta extrair IMEI do pacote de login
                $parsedImei = parseGt06ImeiFromLogin($hexFrame);
                if ($parsedImei !== null) {
                    $imei = $parsedImei;
                    $clientInfo[$id]['imei'] = $parsedImei;
                    logMsg("IMEI detectado no login (#{$id}): {$parsedImei}");
                    deviceLogMsg($parsedImei, "LOGIN recebido. Frame: {$hexFrame}");
                } else {
                    logMsg("Não foi possível extrair IMEI do login.");
                }

                $frameInfo['imei'] = $imei;

                // Monta o ACK
                $ackHex = buildGt06Ack('01', $serialHex);
                sendHex($client, $ackHex);

                break;

            case '13': // Status / Heartbeat
                logMsg(">> Pacote de HEARTBEAT/STATUS");
                
                //$imei=$clientInfo[$id]['imei'] ?? null;
                
                // Se já tivermos IMEI deste cliente, log específico
                if ($imei) {
                    deviceLogMsg($imei, "HEARTBEAT recebido. Frame: {$hexFrame}");
                    logMsg("HEARTBEAT recebido. Frame: {$hexFrame}");
                }
        
                // Decodificar o conteúdo completo do heartbeat
                $heartbeat = parseGt06Heartbeat($hexFrame);
                $frameInfo['heartbeat'] = $heartbeat;
        
                if (!isset($heartbeat['error'])) {
                    
                    // SALVA O STATUS COMPLETO NO CACHE ---
                    $clientInfo[$id]['cache'] = [
                        'acc_status'      => $heartbeat['acc_status'],
                        'cut_status'      => $heartbeat['cut_status'],
                        'alarm_status'    => $heartbeat['alarm_status'],
                        'charge_status'   => $heartbeat['charge_status'],
                        'low_batt_status' => $heartbeat['low_batt_status'],
                        'voltage_level'   => $heartbeat['voltage_level'],
                        'gsm_signal'      => $heartbeat['gsm_signal'],
                        'last_update'     => time(),
                    ];
                    
                    // Imprime o status principal no log
                    logMsg(sprintf(
                        "STATUS HEARTBEAT: ACC=%s, CUT=%s, GPS=%s, ALARME=%s, BATT=%s/%s, SINAL=%s (%d)",
                        $heartbeat['acc_status'],
                        $heartbeat['cut_status'],
                        $heartbeat['gps_status'],
                        $heartbeat['alarm_status'],
                        $heartbeat['voltage_level'],
                        $heartbeat['low_batt_status'],
                        $heartbeat['gsm_signal'],
                        $heartbeat['gsm_signal_dec']
                    ));
                } else {
                     logMsg("Falha ao decodificar heartbeat: " . $heartbeat['error']);
                }
        
                // ACK
                $ackHex = buildGt06Ack('13', $serialHex);
                sendHex($client, $ackHex);
                break;

            case '12': // Localização (formato antigo)
                logMsg(">> Pacote de LOCALIZAÇÃO 12 (protocolo {$protocolHex})");
                #78781f12190c040e211dc902bdd978054673d800980002d406c56d00aa33005023c30d0a
                $loc = parseGt06Location_12($client, $hexFrame);
                if ($loc !== null) {
                    
                    
                    //cache
                    $loc['acc_status'] =$clientInfo[$id]['cache']['acc_status'];
                    $loc['cut_status']      = $clientInfo[$id]['cache']['cut_status'];
                    $loc['alarm_status']    = $clientInfo[$id]['cache']['alarm_status'];
                    $loc['charge_status']   = $clientInfo[$id]['cache']['charge_status'];
                    $loc['low_batt_status'] = $clientInfo[$id]['cache']['low_batt_status'];
                     $loc['voltage_level']   = $clientInfo[$id]['cache']['voltage_level'];
                     $loc['gsm_signal']      = $clientInfo[$id]['cache']['gsm_signal'];
                    //fim cache
                    
                    logMsg(sprintf(
                        // FORMATO CORRIGIDO: Use %s para as strings 'acc_status' e 'gps_status'
                        "LOCALIZAÇÃO decodificada: %s lat=%.6f lon=%.6f vel=%dkm/h curso=%d acc=%s gps_status=%s",
                        $loc['datetime'],
                        $loc['lat'],
                        $loc['lon'],
                        $loc['speed'],
                        $loc['course'],
                        $loc['acc_status'],  // Corresponde ao primeiro %s (acc)
                        $loc['gps_status']   // Corresponde ao segundo %s (gps_status)
                    ));
                    
                    /*'acc_status' => $acc_status, // NOVO CAMPO: ON/OFF
        'gps_status'*/
                    // Prepara JSON
                    $frameInfo['location'] = $loc;
                } else {
                    logMsg("Falha ao decodificar localização (frame curto ou inválido).");
                }

                if ($imei) {
                    deviceLogMsg($imei, "LOCALIZAÇÃO: " . json_encode($loc, JSON_UNESCAPED_SLASHES));
                }

                // ACK para o protocolo correspondente (12 ou 22)
                $ackHex = buildGt06Ack($protocolHex, $serialHex);
                sendHex($client, $ackHex);
                break;
                
            case '22': // Localização estendida (GPS + LBS / Protocolo 0x22)
                logMsg(">> Pacote de LOCALIZAÇÃO 22 (protocolo {$protocolHex})");
                #78781f12190c040e211dc902bdd978054673d800980002d406c56d00aa33005023c30d0a
                $loc = parseGt06Location($client, $hexFrame);
                if ($loc !== null) {
                    logMsg(sprintf(
                        // FORMATO CORRIGIDO: Use %s para as strings 'acc_status' e 'gps_status'
                        "LOCALIZAÇÃO decodificada: %s lat=%.6f lon=%.6f vel=%dkm/h curso=%d acc=%s gps_status=%s",
                        $loc['datetime'],
                        $loc['lat'],
                        $loc['lon'],
                        $loc['speed'],
                        $loc['course'],
                        $loc['acc_status'],  // Corresponde ao primeiro %s (acc)
                        $loc['gps_status']   // Corresponde ao segundo %s (gps_status)
                    ));
                    
                    /*'acc_status' => $acc_status, // NOVO CAMPO: ON/OFF
        'gps_status'*/
                    // Prepara JSON
                    $frameInfo['location'] = $loc;
                } else {
                    logMsg("Falha ao decodificar localização (frame curto ou inválido).");
                }

                if ($imei) {
                    deviceLogMsg($imei, "LOCALIZAÇÃO: " . json_encode($loc, JSON_UNESCAPED_SLASHES));
                }

                // ACK para o protocolo correspondente (12 ou 22)
                $ackHex = buildGt06Ack($protocolHex, $serialHex);
                sendHex($client, $ackHex);
                break;

            case '21': // Resposta de comando (GPRS)
            case '15': // Outra forma de resposta (dependendo do modelo)
                logMsg(">> Pacote de RESPOSTA DE COMANDO (PROTO {$protocolHex})");
                #787812150a454c41593d4661696c2100010075eaad0d0a
                $cmdResp = parseGt06CommandResponse($hexFrame);
                $frameInfo['command_response'] = $cmdResp;

                if ($imei && isset($cmdResp['info_text']) &&
    (str_contains(strtolower($cmdResp['info_text']), 'success') ||
     str_contains(strtolower($cmdResp['info_text']), 'ok'))) {
                    deviceLogMsg($imei, "RESPOSTA DE COMANDO: " . json_encode($cmdResp, JSON_UNESCAPED_SLASHES));

                    // Se existe comando pendente para este IMEI, consideramos confirmado
                    if (isset($pendingCommands[$imei])) {
                        $cmdData = $pendingCommands[$imei];
                        deviceLogMsg($imei, "COMANDO '{$cmdData['command']}' confirmado pelo rastreador (PROTO {$protocolHex}).");
                        logMsg("Comando pendente para IMEI {$imei} confirmado. Removendo arquivo de comando.");

                        // Remove arquivo de comando
                        if (!empty($cmdData['file']) && file_exists($cmdData['file'])) {
                            @unlink($cmdData['file']);
                        }
                        
                        unset($pendingCommands[$imei]);
                        
                        //se o comando for BATERIA, aguarda 15 segundos e envia comando de bloqueio.
                        if($cmdData['command']=='BATERIA'){
                            sleep(15);
                            checkAndSendPendingCommandForClient($client, $imei, 'BLOQUEAR');
                        }
                        

                        
                    }
                }

                // ACK genérico, se necessário (ajusta conforme o manual se outro ACK for exigido)
                // Muitos firmwares não exigem ACK extra aqui, então podemos pular.
                break;

            default:
                logMsg(">> Protocolo não tratado ainda: {$protocolHex}");
                break;
        }

        // JSON do frame (para debug / consumo externo)
        logMsg('JSON: ' . json_encode($frameInfo, JSON_UNESCAPED_SLASHES));
        logMsg('--');

        // Log por IMEI (se conhecido)
        if (!empty($imei)) {
            deviceLogMsg($imei, 'JSON: ' . json_encode($frameInfo, JSON_UNESCAPED_SLASHES));
        }

        // Depois de tratar o pacote, verifica se há comandos a enviar
        if (!empty($imei)) {
            checkAndSendPendingCommandForClient($client, $imei);
        }
    }
}

/**
 * Verifica se há arquivo de comando para este IMEI e envia (BLOQUEAR / LIBERAR)
 */
function checkAndSendPendingCommandForClient($client, string $imei, $cmm = null): void
{
    global $clientInfo, $cmdDir, $pendingCommands;
    
    $id = (int)$client; // ID do Cliente/Conexão
    
    $cmdFile = $cmdDir . '/' . $imei;
    
    if(!empty($cmm)){
        file_put_contents($cmdFile = $cmdDir . '/' . $imei, $cmm);
        $content = $cmm;
    }else{
        if (!file_exists($cmdFile)) {
            return;
        }

        $content = trim(strtoupper(file_get_contents($cmdFile)));
    }
    
    if ($content !== 'BLOQUEAR' && $content !== 'LIBERAR') {
        deviceLogMsg($imei, "Arquivo de comando inválido: '{$content}'");
        return;
    }
    
    // --- 1. GESTÃO DO SN SEQUENCIAL ---
    // Pega o último SN de comando enviado (ou inicializa em 1)
    $currentSN = $clientInfo[$id]['command_sn'] ?? 1;
    
    // Monta o texto do comando conforme o tipo
    $cmdText = buildCommandText($content);

    // Monta pacote GT06 protocolo 0x80
    $packetHex = buildGt06CommandPacket($cmdText, $currentSN);

    sendHex($client, $packetHex);

    deviceLogMsg($imei, "COMANDO '{$content}' enviado ao rastreador. Texto: '{$cmdText}'. Pacote: {$packetHex}");
    logMsg("Comando '{$content}' enviado para IMEI {$imei}.");
    
    // --- 3. INCREMENTA O SN PARA O PRÓXIMO COMANDO ---
    // Garante que o SN não ultrapasse 0xFFFF (limite de 2 bytes) e reinicia em 1 se zerar.
    $nextSN = ($currentSN + 1) & 0xFFFF;
    $clientInfo[$id]['command_sn'] = ($nextSN === 0) ? 1 : $nextSN; 

    // Marca como pendente aguardando resposta (0x21 / 0x15)
    $pendingCommands[$imei] = [
        'command' => $content,
        'file'    => $cmdFile,
        'sent_at' => time(),
    ];
}

/**
 * Monta o texto do comando para BLOQUEAR / LIBERAR / BATERIA.
 * Aqui usamos DYD/HFYD + senha, conforme padrão comum em GT06.
 * Ajuste se seu manual usar outro tipo de comando.
 */
function buildCommandText(string $type): string
{
    switch ($type) {
        case 'BLOQUEAR':
            // comando de bloqueio
            return 'Relay,1#';

        case 'LIBERAR':
            // comando de desbloqueio
            return 'Relay,0#';
        
        case 'BATERIA':
            // comando de desbloqueio temporário 10 segundos. para checar bateria
            return 'Relay,0#';
            
        default:
            return '';
    }
}

/**
 * Quebra o buffer em frames individuais começando em 0x7878 e terminando em 0x0D0A.
 */
function splitGt06Frames(string $data): array
{
    $frames       = [];
    $startPattern = "\x78\x78"; // 0x7878
    $stopPattern  = "\x0D\x0A"; // 0x0D0A

    $offset = 0;
    $len    = strlen($data);

    while ($offset < $len) {
        $startPos = strpos($data, $startPattern, $offset);
        if ($startPos === false) {
            break;
        }

        $stopPos = strpos($data, $stopPattern, $startPos);
        if ($stopPos === false) {
            // ainda não chegou o fim do pacote
            break;
        }

        $frameLen = $stopPos + 2 - $startPos; // +2 para incluir 0D0A
        $frames[] = substr($data, $startPos, $frameLen);
        $offset   = $stopPos + 2;
    }

    return $frames;
}

/**
 * Monta ACK para login / heartbeat / localização etc.
 * Estrutura: 78 78 05 [PROTO] [SN_hi] [SN_lo] [CRC_hi] [CRC_lo] 0D 0A
 */
function buildGt06Ack(string $protocolHex, string $serialHex): string
{
    // Length (1 byte) = protocolo(1) + SN(2) + CRC(2) = 5
    $lenHex      = '05';
    $crcInputHex = $lenHex . $protocolHex . $serialHex;

    $crcHex    = crc16_x25($crcInputHex); // 4 hex chars
    $packetHex = '7878' . $crcInputHex . $crcHex . '0d0a';

    logMsg("ACK ENVIADO: {$packetHex}");
    return $packetHex;
}

/**
 * Monta pacote de comando (PROTO 0x80).
 * Estrutura assumida:
 * 78 78 [LEN] 80 [SN_hi] [SN_lo] [COMANDO ASCII...] [CRC_hi] [CRC_lo] 0D 0A
 * LEN = 1 (proto) + 2 (SN) + n (tamanho do comando) + 2 (CRC)
 * CRC calculado sobre: LEN + 80 + SN + COMANDO
 */
function buildGt06CommandPacket(string $commandText, int $serial): string
{
    // ... (Serial Hex) ...
    $serialHex = str_pad(dechex($serial), 4, '0', STR_PAD_LEFT);

    // 1. Comando ASCII para HEX
    $cmdHex = bin2hex($commandText);
    $cmdLen = strlen($cmdHex) / 2; // Comprimento em bytes do CONTEÚDO do comando

    // 2. Comprimento da Informação de Comando (CMD_INFO_LEN)
    // 1 (Cmd Len field) + 4 (4 Bytes Reservados) + $cmdLen (Comando)
    // ATENÇÃO: O campo CMD_INFO_LEN é o tamanho do que vem DEPOIS dele no bloco 0x80.
    // O modelo do manual indica que o campo CMD_INFO_LEN é 1 byte.
    $cmdInfoLen = 4 + $cmdLen; // 4 bytes reservados + comprimento do comando em si
    $cmdInfoLenHex = str_pad(dechex($cmdInfoLen), 2, '0', STR_PAD_LEFT);
    
    // 3. 4 Bytes Reservados (fixos)
    $reservedBytes = '00000000'; 
    
    // 4. Packet Length (LEN)
    // LEN = 1 (proto 0x80) + 1 (cmdInfoLen field) + 4 (reservado) + $cmdLen (comando) + 2 (serial) + 2 (CRC)
    $len = 1 + 1 + 4 + $cmdLen + 2 + 2; 
    $lenHex = str_pad(dechex($len), 2, '0', STR_PAD_LEFT);

    // 5. Monta a string de entrada para o CRC, na ordem CORRETA:
    // [LEN] [PROTO 80] [CMD_INFO_LEN] [RESERVADO] [COMANDO] [SN]
    $crcInputHex = $lenHex . '80' . $cmdInfoLenHex . $reservedBytes . $cmdHex . $serialHex;

    // 6. Calcula o CRC-16/X.25
    $crcHex = crc16_x25($crcInputHex);

    // 7. Monta o pacote final: 7878 [LEN] 80 [CMD_INFO_LEN] [RESERVADO] [COMANDO] [SN] [CRC] 0D0A
    $packetHex = '7878' . $crcInputHex . $crcHex . '0d0a';

    logMsg("PACOTE COMANDO ENVIADO: {$packetHex} (CMD: {$commandText}, SN: {$serialHex})");

    return $packetHex;
}


/**
 * Decodifica pacote de localização (0x12 ou 0x22) básico:
 * data/hora, lat, lon, velocidade, course.
 * OBS: Para o 0x22 completo há mais campos (LBS, I/O, etc.),
 * que podem ser adicionados depois usando o manual.
 */
 
function parseGt06Location($client, string $hexFrame): ?array
{
    global $clientInfo;
    // 78781f12190c040e211dc902bdd978054673d800980002d406c56d00aa33005023c30d0a
    
    $id = (int)$client;
    
    // start(2) + len(1) + proto(1) = 4 bytes = 8 hex
    // info começa no índice 8 (hex)
    $infoStart = 8;
    $minInfoHexLen = 12 + 8 + 8 + 2 + 4 + 2 + 18; // 6 + 4 + 4 + 1 + 2 + 1 + 9 bytes = 31 bytes (62 hex)
    
    // O frame RAW enviado tem 27 bytes de conteúdo (54 hex). 
    // Usaremos a posição relativa dos campos.
    
    $contentHexLen = strlen($hexFrame) - 16; // Retira 8 chars de start/len/proto e 8 chars de seq/crc/stop
    if ($contentHexLen < 54) { // Pelo menos 27 bytes de dados (excluindo LBS e outros)
        return null;
    }

    // Data/hora
    $dtHex = substr($hexFrame, $infoStart, 12);
    $yy = bcdToDec(substr($dtHex, 0, 2));
    $mm = bcdToDec(substr($dtHex, 2, 2));
    $dd = bcdToDec(substr($dtHex, 4, 2));
    $hh = bcdToDec(substr($dtHex, 6, 2));
    $mi = bcdToDec(substr($dtHex, 8, 2));
    $ss = bcdToDec(substr($dtHex,10, 2));

    // Muitos dispositivos usam ano 00–99 => 2000+yy
    $year = 2000 + $yy;
    $datetime = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $mm, $dd, $hh, $mi, $ss);

    // --- 2. Latitude e Longitude ---
    $latHex = substr($hexFrame, $infoStart + 12, 8);
    $lonHex = substr($hexFrame, $infoStart + 20, 8);
    $latRaw = hexdec($latHex);
    $lonRaw = hexdec($lonHex);
    $lat = $latRaw / 1800000.0;
    $lon = $lonRaw / 1800000.0;

    // --- 3. Speed ---
    $speedHex = substr($hexFrame, $infoStart + 28, 2);
    $speed    = hexdec($speedHex); // km/h

    // --- 4. Course/Status (2 bytes) ---
    $courseStatusHex = substr($hexFrame, $infoStart + 30, 4);
    $cs              = hexdec($courseStatusHex);

    // Course: 10 bits inferiores (Bit 0-9)
    $course = $cs & 0x03FF;
    $isWest  = (bool)($cs & 0x0400); // Bit 10 (0x0400): Oeste (W)
    $isSouth = (bool)($cs & 0x0800); // Bit 11 (0x0800): Sul (S)
    
    // Status (Extração de bits do campo Course/Status)
    $acc_mask = 0x0020; // Bit 5
    $gps_mask = 0x0010; // Bit 4
    $acc_status = (bool)($cs & $acc_mask) ? 'ON' : 'OFF';
    $gps_status = (bool)($cs & $gps_mask) ? 'POSICIONADO (GPS)' : 'LBS/NÃO POSICIONADO';

    // Aplica o sinal (Latitude e Longitude)
    if ($isSouth) {
        $lat = -$lat;
    }
    if ($isWest) {
        $lon = -$lon;
    }

    // --- 5. Satellites (1 byte) ---
    $satHex = substr($hexFrame, $infoStart + 34, 2);
    $satellites = hexdec($satHex);

    // --- 6. Terminal Information Content (1 byte) ---
    // No seu frame de 27 bytes, é o último byte de conteúdo.
    $terminalInfoStart = $infoStart + 52; // 6+8+8+2+4+2+24 bytes = 54 (32 bytes de conteúdo)
    
    // Ajuste para o seu frame de 27 bytes: 
    // O byte de Terminal Info é o penúltimo byte do seu bloco de LBS/Info de 10 bytes (índice 60-61).
    $terminalInfoStart = $infoStart + 52; 
    $terminalInfoHex = substr($hexFrame, $terminalInfoStart, 2);
    $terminalInfoDec = hexdec($terminalInfoHex); 

    // Status (Extração de bits do campo Terminal Information Content)
    // Bit 0: Relay/Fuel Cut (0: OFF/Desbloqueado, 1: ON/Bloqueado)
    $cut_mask = 0x01;
    $isCutOn = (bool)($terminalInfoDec & $cut_mask);
    $cut_status = $isCutOn ? 'ON' : 'OFF'; // ON = bloqueado OFF = desbloqueado
    
    // Bit 5: Alarm Status (0x20)
    $alarm_mask = 0x20; 
    $alarm_status = (bool)($terminalInfoDec & $alarm_mask) ? 'ALARME ATIVO' : 'NORMAL';
    
    // Bit 3: Charging Status (0x08)
    $charge_mask = 0x08;
    $charge_status = (bool)($terminalInfoDec & $charge_mask) ? 'CARREGANDO' : 'NÃO CARREGANDO';
    
    // Bit 7: Low Battery (0x80)
    $low_batt_mask = 0x80;
    $low_batt_status = (bool)($terminalInfoDec & $low_batt_mask) ? 'BATERIA BAIXA' : 'BATERIA OK';
    
    
    $clientInfo[$id]['cache']['acc_status']=$acc_status;
    $clientInfo[$id]['cache']['cut_status']=$cut_status;
    $clientInfo[$id]['cache']['alarm_status']=$alarm_status;
    $clientInfo[$id]['cache']['charge_status']=$charge_status;
    $clientInfo[$id]['cache']['low_batt_status']=$low_batt_status;
    $clientInfo[$id]['cache']['last_update']     = time();
    
    return [
        'datetime'      => $datetime,
        'lat'           => $lat,
        'lon'           => $lon,
        'speed'         => $speed,
        'course'        => $course,
        'satellites'    => $satellites,
        'acc_status'    => $acc_status,
        'gps_status'    => $gps_status,
        'cut_status'    => $cut_status,
        'alarm_status'  => $alarm_status,
        'charge_status' => $charge_status,
        'low_batt_status' => $low_batt_status,
        'voltage_level'   => $clientInfo[$id]['cache']['voltage_level'], //cache
        'gsm_signal'      => $clientInfo[$id]['cache']['gsm_signal'] //cache
    ];
}



function parseGt06Location_12($client, string $hexFrame): ?array
{
    
    global $clientInfo;
    
    // Exemplo:
    // 78781f12190c040e211dc902bdd978054673d800980002d406c56d00aa33005023c30d0a

    $id = (int)$client;
    
    // start(2) + len(1) + proto(1) = 4 bytes = 8 hex chars
    $infoStart = 8;

    // Comprimento mínimo de um pacote 0x12 (GPS + LBS), em hex chars
    $contentHexLen = strlen($hexFrame) - 16; // ainda funciona para esse formato
    if ($contentHexLen < 54) {
        // Pacote muito curto pra ser um 0x12 completo
        return null;
    }

    // --- 1. Data/hora (6 bytes a partir de infoStart) ---
    $dtHex = substr($hexFrame, $infoStart, 12);
    $yy = bcdToDec(substr($dtHex, 0, 2));
    $mm = bcdToDec(substr($dtHex, 2, 2));
    $dd = bcdToDec(substr($hexFrame, $infoStart + 4, 2));
    $hh = bcdToDec(substr($hexFrame, $infoStart + 6, 2));
    $mi = bcdToDec(substr($hexFrame, $infoStart + 8, 2));
    $ss = bcdToDec(substr($hexFrame, $infoStart + 10, 2));

    $year     = 2000 + $yy;
    $datetime = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $mm, $dd, $hh, $mi, $ss);

    // --- 2. gps-quantity (1 byte) ---
    // Byte imediatamente após a data/hora
    $gpsQtyHex = substr($hexFrame, $infoStart + 12, 2);
    $gpsQty    = hexdec($gpsQtyHex);

    // Bits [3:0] = número de satélites (quando GPS fix, > 0)
    $satellites = $gpsQty & 0x0F;

    // --- 3. Latitude e Longitude ---
    // Pelo protocolo:
    // latitude 4 bytes logo após gps-quantity
    // longitude 4 bytes logo após latitude
    // Cada byte = 2 chars em HEX:
    // latitude começa em infoStart + 14 (7º byte após início do bloco)
    $latHex = substr($hexFrame, $infoStart + 14, 8);  // 4 bytes
    $lonHex = substr($hexFrame, $infoStart + 22, 8);  // 4 bytes

    $latRaw = hexdec($latHex);
    $lonRaw = hexdec($lonHex);

    // Manual Concox: dividir por 1.800.000 para obter graus decimais
    $lat = $latRaw / 1800000.0;
    $lon = $lonRaw / 1800000.0;

    // --- 4. Velocidade (1 byte) ---
    // Após lat(4 bytes=8 hex) + lon(4 bytes=8 hex) = 16 hex
    $speedHex = substr($hexFrame, $infoStart + 30, 2);
    $speed    = hexdec($speedHex); // km/h

    // --- 5. Course / Status (2 bytes) ---
    $courseStatusHex = substr($hexFrame, $infoStart + 32, 4);
    $cs              = hexdec($courseStatusHex);

    // Course: bits [9:0]
    $course = $cs & 0x03FF;

    // Bits conforme manual:
    // Bit[11] = 0 leste, 1 oeste
    // Bit[10] = 0 sul, 1 norte
    $isWest  = (bool)($cs & 0x0800); // 1 << 11
    $isNorth = (bool)($cs & 0x0400); // 1 << 10

    $hemisphereLat = $isNorth ? 'N' : 'S';
    $hemisphereLon = $isWest  ? 'W' : 'E';

    // Bit[12] = GPS fix (1) / não fix (0)
    $gpsFixed    = (bool)($cs & 0x1000);
    $gps_status  = $gpsFixed ? 'POSICIONADO (GPS)' : 'LBS/NÃO POSICIONADO';

    // Aplica o sinal de acordo com o hemisfério
    if ($hemisphereLat === 'S') {
        $lat = -$lat;
    }
    if ($hemisphereLon === 'W') {
        $lon = -$lon;
    }

    // --- 6. OBS: Terminal Info, ACC, corte combustível etc. ---
    // Esses status NÃO vêm no pacote 0x12.
    // Eles vêm em heartbeat/status (ex.: protocolo 0x13 ou pacotes estendidos).
    // Para não quebrar o código que já consome esses campos, devolvo como "desconhecido".
    
    

    return [
        'datetime'        => $datetime,
        'lat'             => $lat,
        'lon'             => $lon,
        'hemisphere_lat'  => $hemisphereLat, // 'N' ou 'S'
        'hemisphere_lon'  => $hemisphereLon, // 'E' ou 'W'
        'speed'           => $speed,
        'course'          => $course,
        'satellites'      => $satellites,
        'gps_status'      => $gps_status,
        'acc_status'      => $clientInfo[$id]['cache']['acc_status'] ?? null,
        'cut_status'      => $clientInfo[$id]['cache']['cut_status'] ?? null,
        'alarm_status'    => $clientInfo[$id]['cache']['alarm_status'] ?? null,
        'charge_status'   => $clientInfo[$id]['cache']['charge_status'] ?? null,
        'low_batt_status' => $clientInfo[$id]['cache']['low_batt_status'] ?? null,
        'voltage_level'   => $clientInfo[$id]['cache']['voltage_level'] ?? null,
        'gsm_signal'      => $clientInfo[$id]['cache']['gsm_signal'] ?? null
    ];
}



/**
 * Decodificação básica de HEARTBEAT (0x13).
 * Dependendo do firmware, o primeiro byte de "info" traz ACC, modo de energia etc.
 * Aqui apenas retornamos os bytes crus para futura análise.
 */

function parseGt06Heartbeat(string $hexFrame): array
{
    // Exemplo de frame:
    // 78780a13c60504000100528f750d0a
    //
    // 78 78   - start
    // 0a      - len
    // 13      - protocol (heartbeat)
    // c6      - Terminal Info
    // 05      - Voltage
    // 04      - GSM signal
    // 00      - Language/Alarm extension
    // 01 00   - Serial
    // 52 8f   - CRC
    // 0d 0a   - stop

    // start(2) + len(1) + proto(1) = 4 bytes = 8 hex chars
    $infoStart = 8;

    // Posição onde o SN começa (mantido caso queira usar depois)
    $lenHex        = substr($hexFrame, 4, 2);
    $len           = hexdec($lenHex);
    $lenHexStart   = 4;
    $snStartOffset = $len - 4; // Subtrai SN (2 bytes) + CRC (2 bytes)
    $snStartHexPos = $lenHexStart + $snStartOffset * 2;

    // A informação de status (Terminal Info, Voltage, GSM, Alarm/Lang)
    // normalmente ocupa 4 bytes (8 caracteres hex).
    $infoHex = substr($hexFrame, $infoStart, 8); // [TerminalInfo][Voltage][GSM][Alarm/Lang]

    if (strlen($infoHex) < 8) {
        return ['error' => 'Frame de Heartbeat incompleto.'];
    }

    // --- 1. Terminal Information Content (1 byte) ---
    $terminalInfoHex = substr($infoHex, 0, 2);
    $terminalInfoDec = hexdec($terminalInfoHex);

    // De acordo com o manual GT06, bits do Terminal Info:
    // Bit7: 1 = óleo/eletricidade DESLIGADOS (corte ativo), 0 = ligados
    // Bit6: 1 = GPS tracking ON, 0 = OFF
    // Bit5~3: tipo de alarme (100 SOS, 011 Low batt, 010 Power Cut, 001 Shock, 000 Normal)
    // Bit2: 1 = carregando, 0 = não
    // Bit1: 1 = ACC alto (ligado), 0 = desligado
    // Bit0: 1 = armado, 0 = desarmado

    // Bit7: Fuel/Electric Cut
    $cut_status_raw = (bool)($terminalInfoDec & 0x80);
    $cut_status     = $cut_status_raw ? 'CUT ON (Bloqueado)' : 'CUT OFF (Desbloqueado)';

    // Bit6: GPS tracking ON/OFF
    $gps_track_raw = (bool)($terminalInfoDec & 0x40);
    $gps_status    = $gps_track_raw ? 'POSICIONADO (GPS)' : 'LBS/NÃO POSICIONADO';

    // Bits 5~3: tipo de alarme
    $alarm_bits = ($terminalInfoDec >> 3) & 0x07;
    switch ($alarm_bits) {
        case 0b100:
            $alarm_status = 'SOS';
            break;
        case 0b011:
            $alarm_status = 'ALARME BATERIA BAIXA';
            break;
        case 0b010:
            $alarm_status = 'ALARME CORTE ENERGIA';
            break;
        case 0b001:
            $alarm_status = 'ALARME IMPACTO';
            break;
        default:
            $alarm_status = 'NORMAL';
            break;
    }

    // Bit2: Charging (1 = carregando)
    $charge_status_raw = (bool)($terminalInfoDec & 0x04);
    $charge_status     = $charge_status_raw ? 'CARREGANDO' : 'NÃO CARREGANDO';

    // Bit1: ACC (1 = ON)
    $acc_status_raw = (bool)($terminalInfoDec & 0x02);
    $acc_status     = $acc_status_raw ? 'ON' : 'OFF';

    // Bit0: Armado/Desarmado
    $armed_raw  = (bool)($terminalInfoDec & 0x01);
    $armed_flag = $armed_raw ? 'ARMADO' : 'DESARMADO';

    // Para manter compatibilidade com seu retorno anterior de "low_batt_status":
    // vamos derivar da combinação alarm_bits (011 = bateria baixa).
    $low_batt_status = ($alarm_bits === 0b011)
        ? 'BATERIA BAIXA (Interna)'
        : 'BATERIA OK';

    // --- 2. Voltage Level (1 byte) ---
    $voltageHex = substr($infoHex, 2, 2);
    $voltageDec = hexdec($voltageHex);

    $voltageLevel = 'Desconhecido';
    switch ($voltageDec) {
        case 0:
            $voltageLevel = 'Sem energia externa';
            break;
        case 1:
            $voltageLevel = 'Extremamente Baixa (1)';
            break;
        case 2:
            $voltageLevel = 'Muito Baixa (2)';
            break;
        case 3:
            $voltageLevel = 'Baixa (3)';
            break;
        case 4:
            $voltageLevel = 'Média (4)';
            break;
        case 5:
            $voltageLevel = 'Alta (5)';
            break;
        case 6:
            $voltageLevel = 'Total (6)';
            break;
        default:
            $voltageLevel = 'Desconhecido';
            break;
    }

    // --- 3. GSM Signal Strength (1 byte) ---
    $gsmHex = substr($infoHex, 4, 2);
    $gsmDec = hexdec($gsmHex);

    $gsmSignal = 'Desconhecido';
    switch ($gsmDec) {
        case 0:
            $gsmSignal = 'Sem Sinal';
            break;
        case 1:
            $gsmSignal = 'Extremamente Fraco';
            break;
        case 2:
            $gsmSignal = 'Muito Fraco';
            break;
        case 3:
            $gsmSignal = 'Bom';
            break;
        case 4:
            $gsmSignal = 'Forte';
            break;
        default:
            $gsmSignal = 'Desconhecido';
            break;
    }

    // --- 4. Alarm/Language (1 byte) ---
    $alarmLangHex = substr($infoHex, 6, 2);

    return [
        'raw_info_hex'       => $infoHex,

        // Status decodificados do Terminal Info Byte
        'acc_status'         => $acc_status,
        'cut_status'         => $cut_status,
        'gps_status'         => $gps_status,
        'alarm_status'       => $alarm_status,
        'charge_status'      => $charge_status,
        'low_batt_status'    => $low_batt_status,

        // Informações adicionais
        'armed_status'       => $armed_flag,

        // Informações de Bateria e Sinal
        'voltage_level_dec'  => $voltageDec,
        'voltage_level'      => $voltageLevel,
        'gsm_signal_dec'     => $gsmDec,
        'gsm_signal'         => $gsmSignal,
        'alarm_language_hex' => $alarmLangHex,
    ];
}



/**
 * Decodificação simples de resposta de comando (0x21 / 0x15).
 * Em muitos firmwares, o texto da resposta fica após cabeçalho e antes de SN.
 */
function parseGt06CommandResponse(string $hexFrame): array
{
    // start(2) + len(1) + proto(1) = 4 bytes = 8 hex
    $infoStart = 8;

    $lenHex = substr($hexFrame, 4, 2);
    $len    = hexdec($lenHex);
    $lenHexStart   = 4;
    $snStartOffset = $len - 4;
    $snStartHexPos = $lenHexStart + $snStartOffset * 2;

    // Conteúdo entre infoStart e SN
    $infoHex = substr($hexFrame, $infoStart, $snStartHexPos - $infoStart);
    $infoBin = @hex2bin($infoHex);
    $infoText = $infoBin !== false ? preg_replace('/[^\P{C}\n\r\t]+/u', '', $infoBin) : '';

    return [
        'info_hex'  => $infoHex,
        'info_text' => $infoText,
    ];
}

/**
 * Extrai IMEI de um pacote de login (0x01).
 * Estrutura típica: 78 78 [len] 01 [IMEI(8 bytes BCD)] ...
 */
function parseGt06ImeiFromLogin(string $hexFrame): ?string
{
    // start(2) + len(1) + proto(1)
    $infoStart = 8;

    // IMEI em BCD geralmente ocupa 8 bytes (16 hex)
    if (strlen($hexFrame) < $infoStart + 16) {
        return null;
    }

    $imeiBcdHex = substr($hexFrame, $infoStart, 16);

    $imeiDigits = '';
    for ($i = 0; $i < strlen($imeiBcdHex); $i += 2) {
        $b = hexdec(substr($imeiBcdHex, $i, 2));
        $hi = ($b & 0xF0) >> 4;
        $lo = $b & 0x0F;

        if ($hi !== 0x0F) {
            $imeiDigits .= (string)$hi;
        }
        if ($lo !== 0x0F) {
            $imeiDigits .= (string)$lo;
        }
    }

    // Alguns dispositivos adicionam zeros extras; pode ser necessário aparar.
    $imeiDigits = ltrim($imeiDigits, '0');
    if ($imeiDigits === '') {
        return null;
    }

    return $imeiDigits;
}

/**
 * Converte BCD (ex: "19") para decimal (ex: 19).
 */
function bcdToDec(string $hex): int
{
    $v = hexdec($hex);
    return (($v >> 4) * 10) + ($v & 0x0F);
}

/**
 * CRC-16/X.25 (CRC-ITU) com reflexão, usado no GT06.
 * - Polinômio: 0x1021
 * - Init: 0xFFFF
 * - RefIn: true
 * - RefOut: true
 * - XOROut: 0xFFFF
 * Entrada em HEX, saída em HEX (4 chars).
 */
function crc16_x25(string $hexString): string
{
    $data = hex2bin($hexString);
    if ($data === false) {
        return '0000';
    }

    $crc  = 0xFFFF;
    $poly = 0x1021;

    $len = strlen($data);
    for ($i = 0; $i < $len; $i++) {
        $b = ord($data[$i]);
        $b = reflect8($b);
        $crc ^= ($b << 8);
        for ($bit = 0; $bit < 8; $bit++) {
            if ($crc & 0x8000) {
                $crc = (($crc << 1) ^ $poly) & 0xFFFF;
            } else {
                $crc = ($crc << 1) & 0xFFFF;
            }
        }
    }

    $crc = reflect16($crc) ^ 0xFFFF;

    return strtolower(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
}

/**
 * Reflete 8 bits.
 */
function reflect8(int $b): int
{
    $r = 0;
    for ($i = 0; $i < 8; $i++) {
        if ($b & (1 << $i)) {
            $r |= (1 << (7 - $i));
        }
    }
    return $r & 0xFF;
}

/**
 * Reflete 16 bits.
 */
function reflect16(int $w): int
{
    $r = 0;
    for ($i = 0; $i < 16; $i++) {
        if ($w & (1 << $i)) {
            $r |= (1 << (15 - $i));
        }
    }
    return $r & 0xFFFF;
}

/**
 * Envia string hex pela conexão.
 */
function sendHex($client, string $hex): void
{
    $bin = hex2bin($hex);
    if ($bin === false) {
        logMsg("Erro ao converter hex para bin (sendHex)");
        return;
    }
    @fwrite($client, $bin);
}
