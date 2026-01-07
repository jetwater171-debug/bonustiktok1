<?php
header('Content-Type: application/json');

// Habilita o log de erros
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

function custom_log($msg, $type = 'INFO') { 
    $logfile = __DIR__ . '/webhook_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    $separator = str_repeat('=', 60);
    $formatted = "\n$separator\n[$timestamp] [$type] $msg\n$separator\n";
    file_put_contents($logfile, $formatted, FILE_APPEND);
    error_log("[$type] $msg");
}

// Recebe o payload do webhook
$payload = file_get_contents('php://input');
$event = json_decode($payload, true);

// Log do payload recebido
custom_log("🔄 Iniciando processamento do webhook");
custom_log("📦 Payload recebido: " . $payload);

// Normalização dos dados do payload
// Prioriza busca dentro de 'data' conforme instrução, mas mantém fallback para raiz
$source = isset($event['data']) ? $event['data'] : $event;

$paymentId = $source['payment_code'] ?? $source['paymentId'] ?? $source['id'] ?? $source['transaction_id'] ?? $source['code'] ?? 
             $event['payment_code'] ?? $event['paymentId'] ?? null;
             
$status = $source['payment_status'] ?? $source['status'] ?? $source['current_status'] ?? $source['state'] ?? 
          $event['payment_status'] ?? $event['status'] ?? null;

// Verifica erro de decodificação JSON
if (json_last_error() !== JSON_ERROR_NONE) {
    custom_log("❌ Erro ao decodificar JSON: " . json_last_error_msg(), 'ERROR');
    http_response_code(400);
    echo json_encode(['error' => 'JSON inválido', 'details' => json_last_error_msg()]);
    exit;
}

// Verifica se o payload é válido
if (!$paymentId || !$status) {
    custom_log("❌ Payload inválido. ID ou Status não encontrados.", 'ERROR');
    custom_log("🔍 ID encontrado: " . ($paymentId ?? 'NÃO'), 'DEBUG');
    custom_log("🔍 Status encontrado: " . ($status ?? 'NÃO'), 'DEBUG');
    custom_log("🔍 Chaves disponíveis no JSON (Raiz): " . implode(', ', array_keys($event ?? [])), 'DEBUG');
    if (isset($event['data'])) {
        custom_log("🔍 Chaves disponíveis no JSON (Data): " . implode(', ', array_keys($event['data'])), 'DEBUG');
    }
    
    http_response_code(400);
    echo json_encode([
        'error' => 'Payload inválido - Campos obrigatórios ausentes',
        'received_keys_root' => array_keys($event ?? []),
        'received_keys_data' => isset($event['data']) ? array_keys($event['data']) : null,
        'missing' => [
            'payment_code/id' => $paymentId ? 'ok' : 'missing',
            'payment_status/status' => $status ? 'ok' : 'missing'
        ]
    ]);
    exit;
}

function getUpsellTitle($valor) {
    // Mapeamento de valores para nomes de upsell
    switch($valor) {
        case 3990:
            return 'Upsell 1';
        case 1970:
            return 'Upsell 2';
        case 1790:
            return 'Upsell 3';
        case 3980:
            return 'Upsell 5';
        case 2490:
            return 'Upsell 4';
        case 1890:
            return 'Upsell 6';
        case 6190:
            return 'Liberação de Benefício'; // Valor original do checkout
        case 2790:
            return 'Taxa de Verificação'; // Valor padrão do checkoutup
        default:
            return 'Produto ' . ($valor/100); // Para outros valores não mapeados
    }
}

try {
    error_log("[Webhook] ℹ️ Processando pagamento ID: " . $paymentId . " com status: " . $status);
    
    // Conecta ao SQLite
    $dbPath = __DIR__ . '/database.sqlite';
    $db = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    custom_log("[Webhook] ✅ Conexão com banco de dados estabelecida");

    // Normaliza o status para o formato do banco
    $novoStatus = (strtolower($status) === 'approved' || $status === 'APPROVED') ? 'paid' : $status;
    custom_log("🔄 Atualizando status para: " . $novoStatus);

    // Salva status em arquivo para leitura direta do frontend (sem banco de dados) 
    // Garante que o diretório existe
    if (!is_dir(__DIR__ . '/offer/transactions')) {
        mkdir(__DIR__ . '/offer/transactions', 0777, true);
    }
    
    $statusFile = __DIR__ . "/offer/transactions/{$paymentId}.json";
    $statusData = [
        'status' => $novoStatus,
        'updated_at' => date('c'),
        'transaction_id' => $paymentId,
        'raw_status' => $status
    ];
    file_put_contents($statusFile, json_encode($statusData));
    custom_log("📁 Status salvo em arquivo: " . $statusFile);

    // Atualiza o status do pagamento no banco de dados
    $stmt = $db->prepare("UPDATE pedidos SET status = :status, updated_at = :updated_at WHERE transaction_id = :transaction_id");
    
    $result = $stmt->execute([
        'status' => $novoStatus,
        'updated_at' => date('c'),
        'transaction_id' => $paymentId
    ]);

    if ($stmt->rowCount() === 0) {
        custom_log("⚠️ Nenhum pedido encontrado com o ID: " . $paymentId, 'WARNING');
        
        // Verifica se o pedido existe
        $checkStmt = $db->prepare("SELECT * FROM pedidos WHERE transaction_id = :transaction_id");
        $checkStmt->execute(['transaction_id' => $paymentId]);
        $pedidoExiste = $checkStmt->fetch();
        
        if ($pedidoExiste) {
            error_log("[Webhook] ℹ️ Pedido encontrado mas status não foi alterado. Status atual: " . $pedidoExiste['status']);
        } else {
            error_log("[Webhook] ❌ Pedido não existe no banco de dados");
        }
        
    } else {
        error_log("[Webhook] ✅ Status atualizado com sucesso no banco de dados");
    }

    // Responde imediatamente ao webhook
    http_response_code(200);
    echo json_encode(['success' => true]);
    
    // Fecha a conexão com o cliente
    if (function_exists('fastcgi_finish_request')) {
        error_log("[Webhook] 📤 Fechando conexão com o cliente via fastcgi_finish_request");
        fastcgi_finish_request();
    }
    
    // Continua o processamento em background
    if (strtolower($status) === 'approved') {
        error_log("[Webhook] ✅ Pagamento aprovado, iniciando processamento em background");

        // Busca os dados do pedido
        $stmt = $db->prepare("SELECT * FROM pedidos WHERE transaction_id = :transaction_id");
        $stmt->execute(['transaction_id' => $paymentId]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($pedido) {
            error_log("[Webhook] ✅ Dados do pedido recuperados do banco");
            
            // Decodifica os parâmetros UTM do banco
            $utmParams = json_decode($pedido['utm_params'], true);
            
            // Extrai os parâmetros UTM, garantindo que todos os campos necessários existam
            $trackingParameters = [
                'src' => $utmParams['utm_source'] ?? null,
                'sck' => $utmParams['sck'] ?? null,
                'utm_source' => $utmParams['utm_source'] ?? null,
                'utm_campaign' => $utmParams['utm_campaign'] ?? null,
                'utm_medium' => $utmParams['utm_medium'] ?? null,
                'utm_content' => $utmParams['utm_content'] ?? null,
                'utm_term' => $utmParams['utm_term'] ?? null,
                'fbclid' => $utmParams['fbclid'] ?? null,
                'gclid' => $utmParams['gclid'] ?? null,
                'ttclid' => $utmParams['ttclid'] ?? null,
                'xcod' => $utmParams['xcod'] ?? null
            ];

            // Remove valores null
            $trackingParameters = array_filter($trackingParameters, function($value) {
                return $value !== null;
            });

            // Obtém o título do produto
            $produtoTitulo = getUpsellTitle($pedido['valor']);
            
            $utmifyData = [
                'orderId' => $paymentId,
                'platform' => 'Mangofy',
                'paymentMethod' => 'pix',
                'status' => 'paid',
                'createdAt' => $pedido['created_at'],
                'approvedDate' => date('Y-m-d H:i:s'),
                'paidAt' => date('Y-m-d H:i:s'),
                'refundedAt' => null,
                'customer' => [
                    'name' => $pedido['nome'],
                    'email' => $pedido['email'],
                    'phone' => null,
                    'document' => [
                        'number' => $pedido['cpf'],
                        'type' => 'CPF'
                    ],
                    'country' => 'BR',
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? null
                ],
                'items' => [
                    [
                        'id' => uniqid('PROD_'),
                        'title' => $produtoTitulo,
                        'quantity' => 1,
                        'unitPrice' => $pedido['valor']
                    ]
                ],
                'amount' => $pedido['valor'],
                'fee' => [
                    'fixedAmount' => 0,
                    'netAmount' => $pedido['valor']
                ],
                'trackingParameters' => $trackingParameters,
                'isTest' => false
            ];

            // Envia para utmify.php
            try {
                $jsonValidation = json_encode($utmifyData);
                $GLOBALS['utmify_data'] = $utmifyData;
                $_POST['utmify_json'] = $jsonValidation;
                
                ob_start();
                $utmifyPath = __DIR__ . '/utmify.php';
                
                if (file_exists($utmifyPath)) {
                    include $utmifyPath;
                }
                ob_end_clean();
                
            } catch (Exception $utmifyException) {
                error_log("[Webhook] ❌ Erro ao executar utmify.php: " . $utmifyException->getMessage());
            }
            
            custom_log("[Webhook] ✅ Processamento em background concluído");
        } else {
            custom_log("[Webhook] ❌ Não foi possível recuperar os dados do pedido do banco");
        }
    }

} catch (Exception $e) {
    error_log("[Webhook] ❌ Erro: " . $e->getMessage());
    error_log("[Webhook] 🔍 Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor: ' . $e->getMessage()]);
}
?>