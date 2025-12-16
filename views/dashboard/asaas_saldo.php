
<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<?php
// Widget / painel de saldo Asaas - versão final corrigida (ajuste de contraste dos valores)
// Observação: NÃO alterei nenhuma lógica/funcionalidade do seu código PHP.
// Ajustei apenas a apresentação dos cards (inline background + cores herdadas) para garantir
// que os valores e descrições fiquem sempre legíveis mesmo se o tema aplicar fundo branco.
//
// Substitua este arquivo pelo existente em:
// public_html/escritoriovirtual/modules/asaas/views/dashboard/asaas_saldo.php

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

// Prepare date_range for gateway
$start_date_time = $start_date . ' 00:00:00';
$end_date_time   = $end_date . ' 23:59:59';

$date_range = ['startDate' => $start_date_time, 'endDate' => $end_date_time];

// Get transactions
$transactions = $CI->asaas_gateway->get_financial_transactions(100, 0, $date_range);

// --- FILTRAGEM LOCAL PARA GARANTIR PERÍODO CORRETO ---
$start_ts = strtotime($start_date . ' 00:00:00');
$end_ts   = strtotime($end_date . ' 23:59:59');

// Helper: tenta extrair timestamp da transação
function tx_get_timestamp($tx) {
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

// Filtra transações
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
}

// Process transactions
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

// Unique widget id (mantido igual ao original)
$widget_id = 'widget-' . basename(__FILE__, '.php');
?>

<div class="widget" id="<?php echo $widget_id; ?>" data-name="Conta Bancária | Extrato">
    <div class="panel_s" style="overflow: hidden;">
        <div class="panel-body" style="">
            <div class="widget-dragger"></div>

            <!-- Header -->
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom: 20px;">
                <div style="display:flex; align-items:center; gap: 10px;">
                    <h4 style="margin:0; font-size: 18px; font-weight: 600; color: #111827;">
                        <i class="fa fa-bank" style="margin-right:8px; color:#0891b2;"></i>
                        Conta Bancária
                    </h4>

                    <!-- Botão de olho para mostrar/ocultar saldo -->
                    <button id="toggle-balance-visibility"
                            type="button"
                            class="btn btn-default btn-icon btn-sm"
                            aria-label="Alternar exibição do saldo"
                            title="Mostrar / Ocultar Saldo"
                            onclick="(function(btn){try{var el=document.getElementById('balance-value'); if(!el){return false;} var masked='R$ ********'; var icon=btn.querySelector('i'); var current=(el.textContent||'').trim(); if(current===masked){ var real=el.getAttribute('data-real')||''; real=real.trim(); real=real.replace(/^\s*R\$\s*/i,'')||'0,00'; el.textContent='R$ '+real; if(icon){icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye');} try{localStorage.setItem('asaas_balance_visible_v2','1');}catch(e){} } else { el.textContent=masked; if(icon){icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash');} try{localStorage.setItem('asaas_balance_visible_v2','0');}catch(e){} } }catch(e){console&&console.error&&console.error(e);} return false; })(this);">
                        <i id="eye-icon" class="fa fa-eye-slash" aria-hidden="true"></i>
                    </button>
                </div>

                <!-- Logo do Asaas -->
                <div>
                    <img src="https://raw.githubusercontent.com/99labdev/n8n-nodes-asaas/HEAD/logo.png" alt="Asaas Logo" style="height:20px; opacity: 0.7;" />
                </div>
            </div>

            <?php if ($error_message): ?>
                <div class="alert alert-warning"><i class="fa fa-exclamation-triangle"></i> <?php echo $error_message; ?></div>
            <?php else: ?>

                <!-- Cards de métricas com design clean e minimalista -->
                <div class="row" style="margin-bottom: 20px;">
                    <div class="col-md-4">
                        <div class="asaas-metric-card"
                             style="background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px 20px; transition: all 0.2s ease; box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);">
                            <div style="text-align: center;">
                                <div style="font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Comissões Weboox</div>
                                <div style="font-size: 28px; font-weight: 700; color: #111827; line-height: 1.2;">R$ <?php echo $commissions_balance_fmt; ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="asaas-metric-card"
                             style="background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px 20px; transition: all 0.2s ease; box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);">
                            <div style="text-align: center;">
                                <div style="font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Recebidos</div>
                                <div style="font-size: 28px; font-weight: 700; color: #059669; line-height: 1.2;">R$ <?php echo $received_total_fmt; ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="asaas-metric-card"
                             style="background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px 20px; transition: all 0.2s ease; box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);">
                            <div style="text-align: center;">
                                <div style="font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Saldo Atual</div>
                                <div style="font-size: 28px; font-weight: 700; color: #111827; line-height: 1.2;">
                                    <span id="balance-value" data-real="<?php echo htmlspecialchars($available_balance_fmt, ENT_QUOTES, 'UTF-8'); ?>">R$ ********</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtros de período -->
                <div style="margin: 20px 0 15px 0;">
                    <div class="btn-group" role="group">
                        <a href="?asaas_days=today" class="btn btn-sm <?php echo $days_filter=='today'?'btn-info':'btn-default'; ?>" style="border-radius: 6px 0 0 6px;">Hoje</a>
                        <a href="?asaas_days=7" class="btn btn-sm <?php echo $days_filter=='7'?'btn-info':'btn-default'; ?>" style="border-radius: 0;">7 Dias</a>
                        <a href="?asaas_days=current_month" class="btn btn-sm <?php echo $days_filter=='current_month'?'btn-info':'btn-default'; ?>" style="border-radius: 0;">Este Mês</a>
                        <a href="?asaas_days=previous_month" class="btn btn-sm <?php echo $days_filter=='previous_month'?'btn-info':'btn-default'; ?>" style="border-radius: 0 6px 6px 0;">Mês Anterior</a>
                    </div>
                </div>

                <!-- Exibe período -->
                <p style="color: #6b7280; font-size: 13px; margin: 10px 0 15px 0;">Período: <strong style="color: #374151;"><?php echo $start_date; ?></strong> até <strong style="color: #374151;"><?php echo $end_date; ?></strong></p>

                <hr />

                <!-- Tabela de transações (extrato completo) -->
                <?php if (count($filtered_transactions) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered">
                             <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>ID Transação</th>
                                    <th>Descrição</th>
                                    <th class="text-right">Valor</th>
                                    <th class="text-right">Saldo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($filtered_transactions as $transaction): ?>
                                    <?php
                                    // Extrai data para exibição
                                    $date_ts = tx_get_timestamp($transaction);
                                    $date = $date_ts ? date('d/m/Y', $date_ts) : '-';
                                    $transaction_id = $transaction['id'] ?? ($transaction['transactionId'] ?? '-');
                                    $type = $transaction['type'] ?? ($transaction['category'] ?? '-');
                                    $description = $transaction['description'] ?? $type;
                                    $value = isset($transaction['value']) ? $transaction['value'] : (isset($transaction['amount']) ? $transaction['amount'] : 0);
                                    $balance_after = $transaction['balance'] ?? ($transaction['accountBalance'] ?? 0);
                                    $value_class = $value >= 0 ? 'text-success' : 'text-danger';
                                    ?>
                                    <tr>
                                        <td><?php echo $date; ?></td>
                                        <td><?php echo $transaction_id; ?></td>
                                        <td><?php echo $description; ?></td>
                                        <td class="text-right <?php echo $value_class; ?>"><strong>R$ <?php echo number_format($value, 2, ',', '.'); ?></strong></td>
                                        <td class="text-right">R$ <?php echo number_format($balance_after, 2, ',', '.'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info"><i class="fa fa-info-circle"></i> Nenhuma transação encontrada no período selecionado.</div>
                <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
</div>

<style>
/* Clean and minimal design - scoped to widget */
#<?php echo $widget_id; ?> { 
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; 
}

/* Panel container */
#<?php echo $widget_id; ?> .panel_s {
    border-radius: 12px;
    padding: 20px;
    background: #ffffff;
    box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
}

#<?php echo $widget_id; ?> .panel-body {
    background: transparent;
    padding: 0;
}

/* Metric cards - subtle hover effect */
#<?php echo $widget_id; ?> .asaas-metric-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    border-color: #d1d5db;
}

/* Eye toggle button */
#<?php echo $widget_id; ?> #toggle-balance-visibility {
    transition: all 0.2s ease;
    border: 1px solid #e5e7eb;
    background: #ffffff;
    color: #374151;
    border-radius: 6px;
    padding: 6px 10px;
}

#<?php echo $widget_id; ?> #toggle-balance-visibility:hover {
    background: #f9fafb;
    border-color: #d1d5db;
}

#<?php echo $widget_id; ?> #toggle-balance-visibility .fa {
    color: #6b7280;
}

/* Button group styling */
#<?php echo $widget_id; ?> .btn-group .btn {
    font-weight: 500;
    font-size: 13px;
    padding: 6px 14px;
    transition: all 0.2s ease;
}

#<?php echo $widget_id; ?> .btn-group .btn-info {
    background: #0891b2;
    border-color: #0891b2;
    color: #ffffff;
}

#<?php echo $widget_id; ?> .btn-group .btn-info:hover {
    background: #0e7490;
    border-color: #0e7490;
}

#<?php echo $widget_id; ?> .btn-group .btn-default {
    background: #ffffff;
    border-color: #e5e7eb;
    color: #374151;
}

#<?php echo $widget_id; ?> .btn-group .btn-default:hover {
    background: #f9fafb;
    border-color: #d1d5db;
}

/* Table styling - clean and minimal */
#<?php echo $widget_id; ?> .table-responsive {
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid #e5e7eb;
    margin-top: 10px;
}

#<?php echo $widget_id; ?> .table thead {
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
}

#<?php echo $widget_id; ?> .table thead th {
    color: #374151;
    font-weight: 600;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 12px 16px;
    border: none;
}

#<?php echo $widget_id; ?> .table {
    background: #fff;
    color: #111827;
    margin-bottom: 0;
}

#<?php echo $widget_id; ?> .table td {
    padding: 12px 16px;
    border-top: 1px solid #f3f4f6;
    font-size: 13px;
}

#<?php echo $widget_id; ?> .table tbody tr:hover {
    background-color: #f9fafb;
}

#<?php echo $widget_id; ?> .table .text-success {
    color: #059669;
}

#<?php echo $widget_id; ?> .table .text-danger {
    color: #dc2626;
}

/* Alerts */
#<?php echo $widget_id; ?> .alert {
    border-radius: 8px;
    border: 1px solid;
    padding: 12px 16px;
    font-size: 13px;
}

#<?php echo $widget_id; ?> .alert-info {
    background: #f0f9ff;
    border-color: #bae6fd;
    color: #0c4a6e;
}

#<?php echo $widget_id; ?> .alert-warning {
    background: #fffbeb;
    border-color: #fde68a;
    color: #78350f;
}

/* Responsive */
@media (max-width: 768px) {
    #<?php echo $widget_id; ?> .col-md-4 { 
        margin-bottom: 12px; 
    }
}
</style>

<!-- Script para inicializar visibilidade do saldo (mantido intacto) -->
<script>
(function(){
  try {
    var el = document.getElementById('balance-value');
    var btnIcon = document.getElementById('eye-icon');
    if(!el) return;
    var stored = null;
    try { stored = localStorage.getItem('asaas_balance_visible_v2'); } catch(e) { stored = null; }
    if(stored === '1') {
      var real = (el.getAttribute('data-real')||'').trim().replace(/^\s*R\$\s*/i,'') || '0,00';
      el.textContent = 'R$ ' + real;
      if(btnIcon){ btnIcon.classList.remove('fa-eye-slash'); btnIcon.classList.add('fa-eye'); }
    } else {
      el.textContent = 'R$ ********';
      if(btnIcon){ btnIcon.classList.remove('fa-eye'); btnIcon.classList.add('fa-eye-slash'); }
    }
  } catch(e) {
    // Silent fail
  }
})();
</script>
