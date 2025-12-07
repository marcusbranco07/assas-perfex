<?php

if (!defined('BASEPATH')) exit('No direct script access allowed');

class Callback extends APP_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('asaas_gateway'); 
    }

    public function index()
    {
        // 1. Verifica se a requisição é POST (padrão de Webhook)
        if (strcasecmp($_SERVER['REQUEST_METHOD'], 'POST') == 0) {

            // 2. Configura a API Key (Sandbox ou Produção)
            $sandbox = $this->asaas_gateway->getSetting('sandbox');
            if ($sandbox == '0') {
                $api_key = $this->asaas_gateway->decryptSetting('api_key');
                $api_url = "https://api.asaas.com";
            } else {
                $api_key = $this->asaas_gateway->decryptSetting('api_key_sandbox');
                $api_url = "https://sandbox.asaas.com";
            }

            // 3. Recebe e decodifica o payload do Asaas
            $response = trim(file_get_contents("php://input"));
            $content = json_decode($response);
            
            // Verificação de segurança: Checa se as propriedades críticas existem
            if (!isset($content->payment->externalReference) || !isset($content->payment->status) || !isset($content->payment->id)) {
                http_response_code(400); // Bad Request
                die('Dados do Asaas incompletos.');
            }
            
            $externalReference = $content->payment->externalReference;
            $status = $content->payment->status;
            $billingType = $content->payment->billingType;
            $transactionId = $content->payment->id; // ID ÚNICO da transação no Asaas

            // 4. Busca a fatura no banco de dados do Perfex usando a hash (externalReference)
            $this->db->where('hash', $externalReference);
            $invoice = $this->db->get(db_prefix() . 'invoices')->row();

            if ($invoice) {
                
                // Processa o pagamento APENAS nos status de CONFIRMAÇÃO
                if ($status == "RECEIVED" || $status == "RECEIVED_IN_CASH") {
                    
                    // 🚨 PASSO 1: VERIFICAÇÃO DE DUPLICIDADE
                    $this->db->where('transactionid', $transactionId);
                    $existing_payment = $this->db->get(db_prefix() . 'invoicepaymentrecords')->row();
                    
                    if ($existing_payment) {
                        log_activity('Asaas: Callback ignorado para fatura ' . $invoice->id . '. Pagamento (ID: ' . $transactionId . ') já registrado.');
                        echo 'Asaas: Pagamento já registrado. OK';
                        return;
                    }

                    // Se a fatura não estiver paga (status != 2)
                    if ($invoice->status !== "2") {
                        
                        $valorPagoTotal = $content->payment->value; // Valor Bruto (Fatura + Juros/Multa)
                        $valorFaturaOriginal = $invoice->total; // Valor que está no Perfex

                        // 🟢 PASSO 2: REGISTRO EM AJUSTE DE JUROS E MULTA
                        if ($valorPagoTotal > $valorFaturaOriginal) {
                            $diferenca = $valorPagoTotal - $valorFaturaOriginal;

                            // Adiciona o valor do ajuste no campo `adjustment`
                            $this->db->where('id', $invoice->id);
                            $this->db->set('adjustment', 'adjustment + ' . $diferenca, false);
                            $this->db->update(db_prefix() . 'invoices');

                            log_activity('Asaas: Ajuste registrado na fatura ' . $invoice->id . ' no valor de R$ ' . $diferenca . ' (juros/multa).');
                        }
                        
                        // 5. Aplica o pagamento no Perfex
                        $this->asaas_gateway->addPayment([
                            'amount'          => $valorPagoTotal, 
                            'invoiceid'       => $invoice->id,
                            'paymentmode'     => 'Asaas',
                            'paymentmethod'   => $billingType,
                            'transactionid'   => $transactionId,
                        ]);

                        log_activity('Asaas: Confirmação de pagamento realizada para a fatura ' . $invoice->id . ', ID Asaas: ' . $transactionId);
                        echo 'Asaas: Pagamento registrado com sucesso. OK';
                        
                    } else {
                        log_activity('Asaas: Callback ignorado para fatura ' . $invoice->id . '. Fatura já estava paga (status 2).');
                        echo 'Asaas: Fatura já paga. OK';
                    }

                } else if ($status == "PAYMENT_OVERDUE") {
                    // 🟢 Tratar o evento PAYMENT_OVERDUE para evitar interrupção
                    log_activity('Asaas: Evento PAYMENT_OVERDUE recebido para a fatura ' . $invoice->id . '. Nenhuma ação necessária.');
                    http_response_code(200); // Retorna sucesso para o Asaas
                    echo 'Asaas: Evento PAYMENT_OVERDUE tratado corretamente.';
                } else if ($status == "CONFIRMED" || $status == "PAYMENT_CREATED") {
                    // Loga outros status sem registrar o pagamento
                    log_activity('Asaas: Estado da cobrança da fatura ' . $invoice->id . ', Status: ' . $status);
                    echo 'Asaas: Status recebido (' . $status . '). OK';
                }
                
            } else {
                log_activity('Asaas: Fatura não encontrada para o hash ' . $externalReference . ' na base de dados.');
                // 🟢 Tratar o caso de fatura não encontrada para PAYMENT_OVERDUE
                if ($status == "PAYMENT_OVERDUE") {
                    http_response_code(200); // Retorne HTTP 200 para evitar penalização
                    echo 'Asaas: Fatura não encontrada para PAYMENT_OVERDUE. OK';
                    return;
                }

                http_response_code(404);
                echo 'Asaas: Fatura não encontrada';
            }
        }
    }
}