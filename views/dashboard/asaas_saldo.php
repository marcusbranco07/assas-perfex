<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<?php
// Widget / painel de saldo Asaas - versão com correção do filtro "Mês Anterior"
// Alteração: após buscar transações, filtramos localmente para garantir que só
// apareçam transações entre $start_date 00:00:00 e $end_date 23:59:59 (inclusive).

$CI = &get_instance();
$CI->load->library('asaas_gateway');

// Filtros de período
$days_filter = isset($_GET['asaas_days']) ? $_GET['asaas_days'] : 'today';
switch ($days_filter) {
    case 'today':
    case '0':
        $start_date = $end_date = date('Y-m-d');
        break;
    case '7':
        $start_date = date('Y-m-d', strtotime('-6 days'));
        $end_date = date('Y-m-d');
        break;
    case '15':
        $start_date = date('Y-m-d', strtotime('-14 days'));
        $end_date = date('Y-m-d');
        break;
    case '30':
        $start_date = date('Y-m-d', strtotime('-29 days'));
        $end_date = date('Y-m-d');
        break;
    case 'current_month':
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
        break;
    case 'previous_month':
        // Usa DateTime para pegar primeiro e último dia do mês anterior
        $firstDayLastMonth = new DateTime('first day of last month');
        $start_date = $firstDayLastMonth->format('Y-m-d');
        $end_date   = $firstDayLastMonth->format('Y-m-t');
        break;
    default:
        if (is_array($days_filter) && isset($days_filter['startDate'], $days_filter['endDate'])) {
            $start_date = $days_filter['startDate'];
            $end_date = $days_filter['endDate'];
        } else {
            $start_date = $end_date = date('Y-m-d');
        }
        break;
}

// Init values
$commissions_balance = 0.0;
$received_total = 0.0;
$available_balance = 0.0;
$error_message = null;

// Get balance
$balance = $CI->asaas_gateway->get_account_balance();
if ($balance && isset($balance['balance'])) {
    $available_balance = $balance['balance'] ?? 0;
} else {
    $error_message = 'Não foi possível carregar o saldo da conta Asaas.';
}

// Prepare date_range for gateway (you may adapt if gateway expects only Y-m-d)
$start_date_time = $start_date . ' 00:00:00';
$end_date_time   = $end_date . ' 23:59:59';

$date_range = ['startDate' => $start_date_time, 'endDate' => $end_date_time];

// Get transactions (paginação feita internamente)
$transactions = $CI->asaas_gateway->get_financial_transactions(100, 0, $date_range);

// --- FILTRAGEM LOCAL PARA GARANTIR PERÍODO CORRETO ---
// Calcula timestamps limites
$start_ts = strtotime($start_date . ' 00:00:00');
$end_ts   = strtotime($end_date . ' 23:59:59');

// Helper: tenta extrair timestamp da transação olhando por chaves comuns
function tx_get_timestamp($tx) {
    // Keys que frequentemente contém data/hora
    $candidates = [
        'date', 'transactionDate', 'transaction_date', 'createdAt', 'created_at',
        'dateCreated', 'paymentDate', 'payment_date', 'scheduledDate', 'timestamp'
    ];
    foreach ($candidates as $k) {
        if (isset($tx[$k]) && $tx[$k]) {
            $ts = strtotime($tx[$k]);
            if ($ts !== false) return $ts;
        }
    }
    // fallback: procurar em todos os campos por algo que pareça data
    foreach ($tx as $v) {
        if (is_string($v)) {
            $maybe = strtotime($v);
            if ($maybe !== false) return $maybe;
        }
    }
    return false;
}

// Filtra apenas as transações dentro do intervalo
$filtered_transactions = [];
if ($transactions && isset($transactions['data']) && is_array($transactions['data'])) {
    foreach ($transactions['data'] as $tx) {
        $ts = tx_get_timestamp($tx);
        if ($ts === false) {
            // sem data reconhecível -> opcionalmente inclui ou exclui; aqui excluímos para segurança
            continue;
        }
        if ($ts >= $start_ts && $ts <= $end_ts) {
            $filtered_transactions[] = $tx;
        }
    }
} else {
    $filtered_transactions = [];
}

// Agora use $filtered_transactions para processamento e exibição
// Process transactions - only "Comissão recebida"
if (count($filtered_transactions) > 0) {
    foreach ($filtered_transactions as $transaction) {
        $value = 0.0;
        if (isset($transaction['value'])) {
            $value = (float)$transaction['value'];
        } elseif (isset($transaction['amount'])) {
            $value = (float)$transaction['amount'];
        } elseif (isset($transaction['netValue'])) {
            $value = (float)$transaction['netValue'];
        }

        if ($value > 0) {
            $received_total += $value;
        }

        $rawDescription = isset($transaction['description']) ? (string)$transaction['description'] : '';
        $cleanDescription = trim(strip_tags($rawDescription));
        $descLower = mb_strtolower($cleanDescription);

        $hasComissaoRecebida = false;
        if (mb_stripos($descLower, 'comissão recebida') !== false || mb_stripos($descLower, 'comissao recebida') !== false) {
            $hasComissaoRecebida = true;
        }

        if ($hasComissaoRecebida) {
            $commissions_balance += abs($value);
        } else {
            if (method_exists($CI->asaas_gateway, 'debug_enable') && $CI->asaas_gateway->debug_enable()) {
                $fullJson = json_encode($transaction);
                if ($fullJson !== false && (mb_stripos($fullJson, 'weboox') !== false || mb_stripos($fullJson, 'sprit') !== false)) {
                    log_activity('ASAAS_SALDO - transacao menciona WEBOOX/SPRIT mas NAO classificada como COMISSAO_RECEBIDA: ' . $fullJson);
                }
            }
        }
    }
}

// Format values
$commissions_balance_fmt = number_format($commissions_balance, 2, ',', '.');
$received_total_fmt = number_format($received_total, 2, ',', '.');
$available_balance_fmt = number_format($available_balance, 2, ',', '.');
?>

<style>
    .asaas-dashboard-widget {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 16px;
        padding: 30px;
        color: white;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        margin-bottom: 20px;
        position: relative;
        overflow: hidden;
    }
    
    .asaas-dashboard-widget::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
        pointer-events: none;
    }
    
    .asaas-widget-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        position: relative;
        z-index: 1;
    }
    
    .asaas-widget-logo-container {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .asaas-widget-logo {
        height: 35px;
        width: auto;
        object-fit: contain;
    }
    
    .asaas-widget-title {
        font-size: 22px;
        font-weight: 700;
        margin: 0;
        letter-spacing: 0.5px;
    }
    
    .asaas-period-filter {
        background: rgba(255, 255, 255, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.3);
        border-radius: 8px;
        padding: 8px 16px;
        color: white;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.3s ease;
        outline: none;
    }
    
    .asaas-period-filter:hover {
        background: rgba(255, 255, 255, 0.3);
    }
    
    .asaas-period-filter option {
        background: #764ba2;
        color: white;
    }
    
    .asaas-balance-cards {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        position: relative;
        z-index: 1;
    }
    
    .asaas-balance-card {
        background: rgba(255, 255, 255, 0.15);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 12px;
        padding: 25px 20px;
        transition: all 0.3s ease;
        text-align: center;
    }
    
    .asaas-balance-card:hover {
        transform: translateY(-5px);
        background: rgba(255, 255, 255, 0.2);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
    }
    
    .asaas-card-label {
        font-size: 11px;
        opacity: 0.9;
        margin-bottom: 10px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    
    .asaas-card-value {
        font-size: 32px;
        font-weight: 700;
        margin: 0;
        line-height: 1.2;
    }
    
    .asaas-card-currency {
        font-size: 20px;
        opacity: 0.9;
    }
    
    .asaas-error-message {
        background: rgba(255, 107, 107, 0.2);
        border: 1px solid rgba(255, 107, 107, 0.4);
        border-radius: 8px;
        padding: 15px;
        color: white;
        text-align: center;
        margin-bottom: 20px;
        position: relative;
        z-index: 1;
    }
    
    @media (max-width: 992px) {
        .asaas-balance-cards {
            grid-template-columns: 1fr;
        }
        
        .asaas-widget-header {
            flex-direction: column;
            gap: 15px;
            align-items: flex-start;
        }
        
        .asaas-card-value {
            font-size: 28px;
        }
    }
</style>

<div class="asaas-dashboard-widget">
    <div class="asaas-widget-header">
        <!-- ============================================
             LOGO DO WIDGET - ALTERE A URL DA IMAGEM AQUI
             ============================================
             Para trocar a logo, substitua a URL abaixo pela nova URL da sua imagem.
             Exemplo: https://seusite.com/caminho/para/nova-logo.png
             A imagem será exibida com altura de 35px (largura automática).
        -->
        <div class="asaas-widget-logo-container">
            <img src="https://escritoriovirtual.sistemadtjus.com.br/media/Imagem%20login%20admin/AsaasZylos-_1_.png" 
                 alt="Logo Asaas" 
                 class="asaas-widget-logo"
                 onerror="this.style.display='none'">
            <h3 class="asaas-widget-title">Dashboard Asaas</h3>
        </div>
        <!-- ============================================ -->
        
        <select class="asaas-period-filter" onchange="window.location.href='?asaas_days='+this.value">
            <option value="today" <?php echo $days_filter == 'today' || $days_filter == '0' ? 'selected' : ''; ?>>Hoje</option>
            <option value="7" <?php echo $days_filter == '7' ? 'selected' : ''; ?>>Últimos 7 dias</option>
            <option value="15" <?php echo $days_filter == '15' ? 'selected' : ''; ?>>Últimos 15 dias</option>
            <option value="30" <?php echo $days_filter == '30' ? 'selected' : ''; ?>>Últimos 30 dias</option>
            <option value="current_month" <?php echo $days_filter == 'current_month' ? 'selected' : ''; ?>>Mês Atual</option>
            <option value="previous_month" <?php echo $days_filter == 'previous_month' ? 'selected' : ''; ?>>Mês Anterior</option>
        </select>
    </div>
    
    <?php if ($error_message): ?>
        <div class="asaas-error-message">
            <i class="fa fa-exclamation-triangle"></i> <?php echo $error_message; ?>
        </div>
    <?php endif; ?>
    
    <div class="asaas-balance-cards">
        <div class="asaas-balance-card">
            <div class="asaas-card-label">Saldo Disponível</div>
            <h2 class="asaas-card-value">
                <span class="asaas-card-currency">R$</span><?php echo $available_balance_fmt; ?>
            </h2>
        </div>
        
        <div class="asaas-balance-card">
            <div class="asaas-card-label">Total Recebido</div>
            <h2 class="asaas-card-value">
                <span class="asaas-card-currency">R$</span><?php echo $received_total_fmt; ?>
            </h2>
        </div>
        
        <div class="asaas-balance-card">
            <div class="asaas-card-label">Comissões</div>
            <h2 class="asaas-card-value">
                <span class="asaas-card-currency">R$</span><?php echo $commissions_balance_fmt; ?>
            </h2>
        </div>
    </div>
</div>
