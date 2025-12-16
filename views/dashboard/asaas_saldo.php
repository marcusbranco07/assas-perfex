
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

            <!-- Header com título, botão olho e logo -->
            <div style="display:flex; align-items:center; justify-content:space-between;">
                <div style="display:flex; align-items:center;">
                    <h4 class="no-margin" style="margin:0; display:flex; align-items:center;">
                        <i class="fa fa-money" style="margin-right:8px; color:#667eea;"></i>
                        <span style="font-weight:600;">Conta Bancária | Extrato</span>
                    </h4>

                    <!-- Botão de olho para mostrar/ocultar saldo -->
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

                <!-- Logo do Asaas -->
                <div>
                    <img src="https://raw.githubusercontent.com/99labdev/n8n-nodes-asaas/HEAD/logo.png" alt="Asaas Logo" style="height:24px; cursor:pointer;" />
                </div>
            </div>

            <hr class="hr-panel-heading" />

            <?php if ($error_message): ?>
                <div class="alert alert-warning"><i class="fa fa-exclamation-triangle"></i> <?php echo $error_message; ?></div>
            <?php else: ?>

                <!-- Cards de métricas com gradiente moderno e animações -->
                <div class="row">
                    <div class="col-md-4">
                        <!-- Inline gradient + color garantem legibilidade independentemente do CSS do tema -->
                        <div class="asaas-metric-card panel_s"
                             style="background: linear-gradient(135deg, #4f66f0 6%, #6b4bd6 100%); color: #ffffff; border-radius: 16px; border: none; transition: all 0.3s cubic-bezier(0.4,0,0.2,1); position: relative; overflow: hidden;">
                            <div class="panel-body text-center" style="background: transparent; color: inherit; padding:25px; position: relative; z-index: 1;">
                                <h5 style="margin-top:0; font-size:12px; text-transform: uppercase; letter-spacing: 2px; font-weight: 700; color: rgba(255,255,255,0.95); opacity: 0.95;">Comissões Weboox</h5>
                                <h3 class="bold" style="margin-bottom:0; font-size: 36px; color: #ffffff; font-weight: 800; margin-top: 10px;">R$ <?php echo $commissions_balance_fmt; ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="asaas-metric-card panel_s"
                             style="background: linear-gradient(135deg, #4f66f0 6%, #6b4bd6 100%); color: #ffffff; border-radius: 16px; border: none; transition: all 0.3s cubic-bezier(0.4,0,0.2,1); position: relative; overflow: hidden;">
                            <div class="panel-body text-center" style="background: transparent; color: inherit; padding:25px; position: relative; z-index: 1;">
                                <h5 style="margin-top:0; font-size:12px; text-transform: uppercase; letter-spacing: 2px; font-weight: 700; color: rgba(255,255,255,0.95); opacity: 0.95;">Recebidos</h5>
                                <h3 class="bold" style="margin-bottom:0; font-size: 36px; color: #ffffff; font-weight: 800; margin-top: 10px;">R$ <?php echo $received_total_fmt; ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="asaas-metric-card panel_s"
                             style="background: linear-gradient(135deg, #4f66f0 6%, #6b4bd6 100%); color: #ffffff; border-radius: 16px; border: none; transition: all 0.3s cubic-bezier(0.4,0,0.2,1); position: relative; overflow: hidden;">
                            <div class="panel-body text-center" style="background: transparent; color: inherit; padding:25px; position: relative; z-index: 1;">
                                <h5 style="margin-top:0; font-size:12px; text-transform: uppercase; letter-spacing: 2px; font-weight: 700; color: rgba(255,255,255,0.95); opacity: 0.95;">Saldo Atual</h5>
                                <h3 class="bold" style="margin-bottom:0; font-size: 36px; font-weight: 800; margin-top: 10px; color: #ffffff;">
                                    <span id="balance-value" data-real="<?php echo htmlspecialchars($available_balance_fmt, ENT_QUOTES, 'UTF-8'); ?>" style="color: #ffffff;">R$ ********</span>
                                </h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtros de período com cores modernas -->
                <div class="btn-group mtop10 mbot10" role="group">
                    <a href="?asaas_days=today" class="btn btn-sm <?php echo $days_filter=='today'?'btn-primary':'btn-default'; ?>">Hoje</a>
                    <a href="?asaas_days=7" class="btn btn-sm <?php echo $days_filter=='7'?'btn-primary':'btn-default'; ?>">7 Dias</a>
                    <a href="?asaas_days=current_month" class="btn btn-sm <?php echo $days_filter=='current_month'?'btn-primary':'btn-default'; ?>">Este Mês</a>
                    <a href="?asaas_days=previous_month" class="btn btn-sm <?php echo $days_filter=='previous_month'?'btn-primary':'btn-default'; ?>">Mês Anterior</a>
                </div>

                <!-- Exibe período -->
                <p class="text-muted mtop5">Período exibido: <strong><?php echo $start_date; ?></strong> até <strong><?php echo $end_date; ?></strong></p>

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
/* ---------- Correção final de contraste e tema moderno (escopo por ID) ---------- */
:root{
  --card-grad-from: #4f66f0; /* azul principal */
  --card-grad-to:   #6b4bd6; /* roxo-azulado */
  --card-radius: 16px;
  --root-radius: 18px;
  --text-glow: rgba(6,12,40,0.22);
  --transition-fast: 240ms cubic-bezier(.2,.9,.3,1);
}

/* Ensure widget font stacking and base */
#<?php echo $widget_id; ?> { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }

/* panel root - subtle rounded white background to match admin and a soft outer shadow */
#<?php echo $widget_id; ?> .panel_s {
    border-radius: var(--root-radius) !important;
    padding: 14px !important;
    background: #ffffff; /* keep panel white so rest of admin remains consistent */
    box-shadow: 0 10px 30px rgba(15,23,42,0.06);
    border: 1px solid rgba(15,23,42,0.04);
    overflow: visible;
    position: relative;
}

/* Panel-body - transparent so cards show gradient, but remains readable */
#<?php echo $widget_id; ?> .panel-body {
    position: relative;
    z-index: 2;
    background: transparent;
    border-radius: 12px;
    padding: 16px;
    color: #111827;
}

/* Ensure any internal .panel-body inside cards does not override color */
#<?php echo $widget_id; ?> .asaas-metric-card .panel-body {
    background: transparent !important;
    color: inherit !important;
}

/* Hover effect - lift card */
#<?php echo $widget_id; ?> .asaas-metric-card:hover {
    transform: translateY(-10px) scale(1.02);
    box-shadow: 0 36px 110px rgba(20,28,80,0.18);
}

/* Eye toggle - keep visible on white panel */
#<?php echo $widget_id; ?> #toggle-balance-visibility {
    transition: all 180ms ease;
    border: 1px solid rgba(15,23,42,0.06) !important;
    background: rgba(255,255,255,0.98) !important;
    color: #0f172a !important;
    border-radius: 8px !important;
    padding: 6px 8px !important;
    box-shadow: 0 6px 18px rgba(15,23,42,0.04);
}
#<?php echo $widget_id; ?> #toggle-balance-visibility .fa {
    color: #0f172a !important;
}

/* Filters - small visual nicety */
#<?php echo $widget_id; ?> .btn-group .btn {
    border-radius: 10px !important;
    font-weight:700;
    margin-right:6px;
    padding:8px 12px;
}
#<?php echo $widget_id; ?> .btn-group .btn.btn-primary {
    background: linear-gradient(90deg, rgba(79,102,240,0.12), rgba(107,75,214,0.08));
    border-color: rgba(79,102,240,0.18);
    color: #0f172a;
}

/* Table styling */
#<?php echo $widget_id; ?> .table-responsive {
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 8px 30px rgba(3,10,40,0.06);
    margin-top: 12px;
}
#<?php echo $widget_id; ?> .table thead {
    background: linear-gradient(90deg, var(--card-grad-from), var(--card-grad-to));
    color: #fff !important;
}
#<?php echo $widget_id; ?> .table {
    background: #fff;
    color: #111827;
}
#<?php echo $widget_id; ?> .table th,
#<?php echo $widget_id; ?> .table td {
    padding: 12px 14px;
    vertical-align: middle;
}
#<?php echo $widget_id; ?> .table tbody tr:hover {
    background-color: rgba(99,102,241,0.04) !important;
    transform: translateY(-2px);
}

/* Alerts color fix */
#<?php echo $widget_id; ?> .alert {
    color: #111827;
}

/* Responsive adjustments */
@media (max-width: 900px) {
    #<?php echo $widget_id; ?> .col-md-4 { margin-bottom: 12px; }
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
    try { console && console.error && console.error('asaas init error', e); } catch(_) {}
  }
})();
</script>

<!-- Lightweight visual tilt for metric cards (non-invasive) -->
<script>
(function(){
  try {
    var cards = document.querySelectorAll('#<?php echo $widget_id; ?> .asaas-metric-card');
    if(!cards || cards.length === 0) return;
    cards.forEach(function(card){
      var rect = card.getBoundingClientRect();
      var updateRect = function(){ rect = card.getBoundingClientRect(); };
      window.addEventListener('resize', updateRect);

      var onMove = function(e){
        var clientX = e.touches ? e.touches[0].clientX : e.clientX;
        var clientY = e.touches ? e.touches[0].clientY : e.clientY;
        var x = (clientX - rect.left) / rect.width - 0.5;
        var y = (clientY - rect.top) / rect.height - 0.5;
        var rx = (-y * 4).toFixed(2);
        var ry = (x * 7).toFixed(2);
        card.style.transform = 'translateZ(10px) rotateX(' + rx + 'deg) rotateY(' + ry + 'deg) scale(1.02)';
        card.style.transition = 'transform 120ms linear';
      };
      var onLeave = function(){
        card.style.transform = '';
        card.style.transition = '';
      };

      card.addEventListener('mousemove', onMove);
      card.addEventListener('touchmove', onMove, {passive:true});
      card.addEventListener('mouseleave', onLeave);
      card.addEventListener('touchend', onLeave);
      card.addEventListener('blur', onLeave);
    });
  } catch(e) {
    try { console && console.warn && console.warn('tilt init failed', e); } catch(_) {}
  }
})();
</script>
