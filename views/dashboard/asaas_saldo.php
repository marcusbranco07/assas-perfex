<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<?php
// Carrega a biblioteca do Asaas
$CI = &get_instance();
$CI->load->library('asaas_gateway');

// Obtém o saldo e as transações
$balance = $CI->asaas_gateway->get_account_balance();
$transactions = $CI->asaas_gateway->get_financial_transactions(10, 0);

// Valores padrão caso a API falhe
$split_balance = 0;
$pending_balance = 0;
$available_balance = 0;
$error_message = null;

if ($balance && isset($balance['balance'])) {
    $available_balance = $balance['balance'] ?? 0;
} else {
    $error_message = 'Não foi possível carregar o saldo da conta Asaas.';
}

// Verifica se há dados de split e pending (a API pode não retornar esses campos)
if ($balance) {
    $split_balance = $balance['splitBalance'] ?? 0;
    $pending_balance = $balance['pendingBalance'] ?? 0;
}
?>

<div class="widget" id="widget-<?php echo basename(__FILE__, '.php'); ?>" data-name="<?php echo _l('Saldo Asaas'); ?>">
    <div class="panel_s">
        <div class="panel-body">
            <div class="widget-dragger"></div>
            
            <h4 class="no-margin">
                <i class="fa fa-money"></i> Saldo Asaas
            </h4>
            <hr class="hr-panel-heading" />

            <?php if ($error_message): ?>
                <div class="alert alert-warning">
                    <i class="fa fa-exclamation-triangle"></i> <?php echo $error_message; ?>
                </div>
            <?php else: ?>
                <!-- Cards de Saldo -->
                <div class="row">
                    <div class="col-md-4">
                        <div class="panel_s">
                            <div class="panel-body text-center" style="background-color: #f9f9f9; padding: 15px;">
                                <h5 class="text-muted" style="margin-top: 0;">Split em Repasses</h5>
                                <h3 class="text-primary bold" style="margin-bottom: 0;">
                                    R$ <?php echo number_format($split_balance, 2, ',', '.'); ?>
                                </h3>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="panel_s">
                            <div class="panel-body text-center" style="background-color: #f9f9f9; padding: 15px;">
                                <h5 class="text-muted" style="margin-top: 0;">Aguardando Pagamentos</h5>
                                <h3 class="text-warning bold" style="margin-bottom: 0;">
                                    R$ <?php echo number_format($pending_balance, 2, ',', '.'); ?>
                                </h3>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="panel_s">
                            <div class="panel-body text-center" style="background-color: #f9f9f9; padding: 15px;">
                                <h5 class="text-muted" style="margin-top: 0;">Saldo Atual</h5>
                                <h3 class="text-success bold" style="margin-bottom: 0;">
                                    R$ <?php echo number_format($available_balance, 2, ',', '.'); ?>
                                </h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabela de Extrato -->
                <h4 class="mtop20">Extrato Detalhado</h4>
                <hr />
                
                <?php if ($transactions && isset($transactions['data']) && count($transactions['data']) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Referência</th>
                                    <th>Descrição</th>
                                    <th class="text-right">Valor</th>
                                    <th class="text-right">Saldo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transactions['data'] as $transaction): ?>
                                    <?php
                                    $date = isset($transaction['date']) ? date('d/m/Y', strtotime($transaction['date'])) : '-';
                                    $type = $transaction['type'] ?? '-';
                                    $description = $transaction['description'] ?? $type;
                                    $value = $transaction['value'] ?? 0;
                                    $balance_after = $transaction['balance'] ?? 0;
                                    
                                    // Define a classe de cor baseado no tipo de transação
                                    $value_class = $value >= 0 ? 'text-success' : 'text-danger';
                                    ?>
                                    <tr>
                                        <td><?php echo $date; ?></td>
                                        <td><?php echo $type; ?></td>
                                        <td><?php echo $description; ?></td>
                                        <td class="text-right <?php echo $value_class; ?>">
                                            <strong>R$ <?php echo number_format($value, 2, ',', '.'); ?></strong>
                                        </td>
                                        <td class="text-right">
                                            R$ <?php echo number_format($balance_after, 2, ',', '.'); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fa fa-info-circle"></i> Nenhuma transação encontrada no momento.
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
    .bold {
        font-weight: bold;
    }
    .mtop20 {
        margin-top: 20px;
    }
</style>
