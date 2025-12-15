
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

<div class="widget" id="widget-<?php echo basename(__FILE__, '.php'); ?>" data-name="Conta Bancária | Extrato">
    <div class="panel_s">
        <div class="panel-body">
            <div class="widget-dragger"></div>

            <!-- Header: left = title + eye, right = logo -->
            <div class="asaas-widget-header" style="display:flex; align-items:center; justify-content:space-between;">
                <div style="display:flex; align-items:center;">
                    <h4 class="no-margin" style="margin:0; display:flex; align-items:center;">
                        <i class="fa fa-money" style="margin-right:8px;"></i>
                        <span style="font-weight:600;">Conta Bancária | Extrato</span>
                    </h4>

                    <!-- Inline onclick autossuficiente: não depende de funções externas -->
                    <button id="toggle-balance-visibility"
                            type="button"
                            class="btn btn-default btn-icon"
                            aria-label="Alternar exibição do saldo"
                            title="Mostrar / Ocultar Saldo"
                            style="margin-left:8px; padding:6px 8px; line-height:1; cursor:pointer; z-index:9999;"
                            onclick="(function(btn){try{var el=document.getElementById('balance-value'); if(!el){return false;} var masked='R$ ********'; var icon=btn.querySelector('i'); var current=(el.textContent||'').trim(); if(current===masked){ var real=el.getAttribute('data-real')||''; real=real.trim(); real=real.replace(/^\s*R\$\s*/i,'')||'0,00'; el.textContent='R$ '+real; if(icon){icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye');} try{localStorage.setItem('asaas_balance_visible_v2','1');}catch(e){} } else { el.textContent=masked; if(icon){icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash');} try{localStorage.setItem('asaas_balance_visible_v2','0');}catch(e){} } }catch(e){console&&console.error&&console.error(e);} return false; })(this);">
                        <i id="eye-icon" class="fa fa-eye-slash" aria-hidden="true"></i>
                    </button>
                </div>

                <div>
                    <img src="https://raw.githubusercontent.com/99labdev/n8n-nodes-asaas/HEAD/logo.png" alt="Asaas Logo" style="height:24px; cursor:pointer;" />
                </div>
            </div>

            <hr class="hr-panel-heading" />

            <?php if ($error_message): ?>
                <div class="alert alert-warning"><i class="fa fa-exclamation-triangle"></i> <?php echo $error_message; ?></div>
            <?php else: ?>

                <div class="row">
                    <div class="col-md-4">
                        <div class="panel_s">
                            <div class="panel-body text-center" style="background-color:#f9f9f9; padding:15px;">
                                <h5 class="text-muted" style="margin-top:0;">Comissões Weboox</h5>
                                <h3 class="text-primary bold" style="margin-bottom:0;">R$ <?php echo $commissions_balance_fmt; ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="panel_s">
                            <div class="panel-body text-center" style="background-color:#f9f9f9; padding:15px;">
                                <h5 class="text-muted" style="margin-top:0;">Recebidos</h5>
                                <h3 class="text-success bold" style="margin-bottom:0;">R$ <?php echo $received_total_fmt; ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="panel_s">
                            <div class="panel-body text-center" style="background-color:#f9f9f9; padding:15px;">
                                <h5 class="text-muted" style="margin-top:0;">Saldo Atual</h5>
                                <h3 class="text-success bold" style="margin-bottom:0;">
                                    <span id="balance-value" data-real="<?php echo htmlspecialchars($available_balance_fmt, ENT_QUOTES, 'UTF-8'); ?>">R$ ********</span>
                                </h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Period filters -->
                <div class="btn-group mtop10 mbot10" role="group">
                    <a href="?asaas_days=today" class="btn btn-sm <?php echo $days_filter=='today'?'btn-primary':'btn-default'; ?>">Hoje</a>
                    <a href="?asaas_days=7" class="btn btn-sm <?php echo $days_filter=='7'?'btn-primary':'btn-default'; ?>">7 Dias</a>
                    <a href="?asaas_days=current_month" class="btn btn-sm <?php echo $days_filter=='current_month'?'btn-primary':'btn-default'; ?>">Este Mês</a>
                    <a href="?asaas_days=previous_month" class="btn btn-sm <?php echo $days_filter=='previous_month'?'btn-primary':'btn-default'; ?>">Mês Anterior</a>
                </div>

                <!-- Exibe periodos (útil para confirmar) -->
                <p class="text-muted mtop5">Período exibido: <strong><?php echo $start_date; ?></strong> até <strong><?php echo $end_date; ?></strong></p>

                <hr />

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
                                    // Extrai data para exibição (fallbacks)
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
#toggle-balance-visibility { padding:6px 8px; line-height:1; margin-left:8px; cursor:pointer; z-index:9999; }
#toggle-balance-visibility .fa { font-size:16px; }
.asaas-widget-header h4 { margin-right:8px; }
</style>

<!-- Minimal init: sets initial visibility from localStorage (defensive, won't throw). -->
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
    try { console && console.error && console.error('asaas init error', e); } catch(_) {}
  }
})();
</script>
