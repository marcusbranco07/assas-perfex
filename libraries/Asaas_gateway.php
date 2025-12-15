<?php 
defined('BASEPATH') or exit('No direct script access allowed');
class Asaas_gateway extends App_gateway {
    protected $ci;
    public function __construct()
    {
        parent::__construct();
        $this->ci = &get_instance();
        $this->ci->load->library("asaas/base_api");
        $this->setId('asaas');
        $this->setName('Asaas');
        $this->setSettings(array(
            array(
                'name' => 'api_key',
                'encrypted' => true,
                'label' => 'Api key Produção',
                'type' => 'input',
            ),
            array(
                'name' => 'api_key_sandbox',
                'encrypted' => true,
                'label' => 'Api key Sandbox',
                'type' => 'input',
            ),
            array(
                'name' => 'useragente',
                'label' => 'Nome da aplicação',
                'type' => 'input',
            ),
            array(
                'name' => 'sandbox',
                'label' => 'Sandbox',
                'type' => 'yes_no',
                'default_value' => 1,
            ),
            array(
                'name' => 'debug',
                'label' => 'debug',
                'type' => 'yes_no',
                'default_value' => 0,
            ),
            array(
                'name' => 'currencies',
                'label' => 'settings_paymentmethod_currencies',
                'default_value' => 'BRL'
            ),
            array(
                'name' => 'description',
                'label' => 'settings_paymentmethod_description',
                'type' => 'textarea',
                'default_value' => 'Pagamento da Fatura {invoice_number}',
            ),
            array(
                'name' => 'installmentCount',
                'label' => 'Limite de parcelas',
                'type' => 'input',
                'default_value' => 1,
            ),
            array(
                'name' => 'delete_charge',
                'label' => 'Deletar cobrança da fatura no Asaas',
                'type' => 'yes_no',
                'default_value' => 0,
            ),
            array(
                'name' => 'update_charge',
                'label' => 'Atualizar cobrança da fatura no Asaas',
                'type' => 'yes_no',
                'default_value' => 0,
            ),
            array(
                'name' => 'disable_charge_notification',
                'label' => 'Desativar notificações de cobrança',
                'type' => 'yes_no',
                'default_value' => 1,
            )
        ));
    }

    public function process_payment($data)
    {
        // Valida entrada
        if(empty($data) || !isset($data['invoice']) || empty($data['invoice'])) {
            return;
        }

        $invoice = $data['invoice']->id;

        // Detecta cenário de baixa manual:
        // Em alguns fluxos do Perfex, ao registrar um pagamento manual a integração
        // do gateway pode chamar process_payment ou outro código. Para garantir,
        // aceitaremos flags no array $data ou na requisição POST para indicar
        // que este é um pagamento manual registrado no Perfex.
        $is_manual = false;
        if (isset($data['is_manual']) && $data['is_manual']) {
            $is_manual = true;
        } elseif (isset($data['manual_payment']) && $data['manual_payment']) {
            $is_manual = true;
        } elseif (isset($data['mark_as_paid']) && $data['mark_as_paid']) {
            $is_manual = true;
        } else {
            // Tenta detectar via POST caso seja chamada via controller
            if (isset($this->ci->input) && method_exists($this->ci->input, 'post')) {
                $post_manual = $this->ci->input->post('is_manual');
                if (!empty($post_manual)) {
                    $is_manual = true;
                }
            }
        }

        // Se for baixa manual, atualiza a cobrança no Asaas para RECEIVED (pago em dinheiro)
        if ($is_manual) {
            $this->mark_invoice_paid_on_asaas($invoice);
            // Não continuar com o fluxo de criação/atualização de cobrança padrão
            return;
        }

        // =======================================================================
        // --- RESTANTE DO FLUXO ORIGINAL PARA CRIAÇÃO/ATUALIZAÇÃO DE COBRANÇA ---
        // =======================================================================
        
        // =========================================================================
        // --- CONFIGURAÇÃO MANUAL/FIXA DO SPLIT 3 (NÃO VISÍVEL NA FATURA) ---
        // VOCÊ DEVE PREENCHER ESTES CAMPOS:
        // Se a porcentagem for 0 ou vazia, o Split 3 não será incluído.
        // =========================================================================
        $fixed_wallet_id_3 = 'bd5ac0a4-4e0a-4f69-9849-71eefa0b6827'; 
        $fixed_percent_3 = 0.0; // Exemplo: 1.0 para 1%
        // =========================================================================
        
        // --- INÍCIO: LEITURA DOS CAMPOS PERSONALIZADOS PARA SPLIT 1 e 2 ---
        $this->ci->load->helper('custom_fields');
        
        // Split 1 (Visível na Fatura)
        $wallet_id_1 = get_custom_field_value($invoice, 'invoice_wallet_id_do_recebedor_asaas', 'invoice');
        $percent_1 = (float) get_custom_field_value($invoice, 'invoice_porcentagem_split_asaas', 'invoice');
        
        // Split 2 (Visível na Fatura)
        $wallet_id_2 = get_custom_field_value($invoice, 'invoice_wallet_id_do_recebedor_asaas_2', 'invoice');
        $percent_2 = (float) get_custom_field_value($invoice, 'invoice_porcentagem_split_asaas_2', 'invoice');
        
        // --- FIM: LEITURA DOS CAMPOS PERSONALIZADOS ---

        $ci = &get_instance();
        $ci->db->where('id', $invoice);
        $row = $ci->db->get(db_prefix() . 'invoices')->row_array();

        $ci->db->where('userid', $row['clientid']);
        $client_data = $ci->db->get(db_prefix() . 'clients')->row_array();
        
        // ** CORREÇÃO EMAIL: BUSCA DO EMAIL DO CONTATO PRINCIPAL (Busca Direta no DB) **
        $ci->db->select('email');
        $ci->db->from(db_prefix() . 'contacts');
        $ci->db->where('userid', $client_data['userid']);
        $ci->db->where('is_primary', 1);
        $primary_contact = $ci->db->get()->row();
        // Limpa e valida o e-mail: se não encontrar primário, usa o e-mail do cliente, se for NULL, usa um padrão.
        $email_client = $primary_contact ? trim($primary_contact->email) : trim($client_data['email']); 
        $email_client = empty($email_client) ? 'contato@seusite.com.br' : $email_client; // Padrão se não tiver email

        if (!$client_data) {
            log_activity('Erro no Asaas Gateway: Cliente não encontrado para a Fatura ID: ' . $invoice);
            return;
        }
        
        $debug = $this->getSetting('debug');
        $api_key = $this->ci->base_api->getApiKey();
        $api_url = $this->ci->base_api->getUrlBase();
        $description = $this->getSetting('description');
        $search_charge = $this->search_charge($api_url, $api_key, $row["hash"]);
        
        $fine = 0.0; 
        $interest = 0.0;
        
        if ($row['status'] == '4') {
            $row['total'] = $this->calculate_invoice($invoice, $row, $fine, $interest);
        }

        $ci->db->where('id', $invoice);
        $row = $ci->db->get(db_prefix() . 'invoices')->row_array();
        $disable_charge_notification = $this->getSetting('disable_charge_notification');
        $notificationDisabled = $disable_charge_notification == '1';
        $invoice_number = $row['prefix'] . str_pad($row['number'], 6, "0", STR_PAD_LEFT);
        $description = mb_convert_encoding(str_replace("{invoice_number}", $invoice_number, $description), 'UTF-8', 'ISO-8859-1');
        $document = str_replace(['/', '-', '.'], '', $client_data['vat']);
        $postalCode = str_replace(['-', '.'], '', $client_data['zip']);
        
        // ** CORREÇÃO NÚMERO E BAIRRO: VALOR PADRÃO E FALLBACK **
        // Leitura do campo número. Usamos trim para limpar espaços e strtoupper para padronizar 'S/N'
        $address_number_raw = get_custom_field_value($client_data['userid'], 'customers_numero_do_endereco', 'customers');
        $address_number = trim($address_number_raw);
        $address_number = (empty($address_number) || strtoupper($address_number) === 'S/N') ? '01' : $address_number; // Define '01' se vazio ou 'S/N'

        $province = get_custom_field_value($client_data['userid'], 'customers_bairro', 'customers');
        $province = !empty($province) ? trim($province) : trim($client_data['city']); // Fallback se Bairro não for preenchido
        
        $customer_payload_data = [
            "name" => $client_data['company'],
            "email" => $email_client, 
            "cpfCnpj" => $document,
            "postalCode"=> $postalCode,
            "address" => $client_data['address'], // Rua/Avenida
            "addressNumber" => $address_number, // Número do Endereço (com fallback '01')
            "complement" => "",
            "province" => $province, // Bairro (com fallback cidade)
            "phone" => $client_data['phonenumber'],
            "mobilePhone" => $client_data['phonenumber'],
            "city" => $client_data['city'],
            "state" => $client_data['state'],
            "externalReference" => $invoice,
            "notificationDisabled" => $notificationDisabled,
        ];

        $customer = $this->search_customer($api_url, $api_key, $document);
        
        if (isset($customer['data'][0]['id'])) {
            // Cliente existe, usa o ID
            $cliente_id = $customer['data'][0]['id'];
            
            // ** FORÇA A ATUALIZAÇÃO DOS DADOS (EMAIL e ENDEREÇO) **
            $update_customer = $this->update_customer($api_url, $api_key, $cliente_id, json_encode($customer_payload_data));
            
            if (isset($update_customer['id'])) {
                log_activity('Cliente Asaas ID: ' . $cliente_id . ' ATUALIZADO (Email/Endereço) antes da cobrança.');
            } else {
                log_activity('Aviso: Falha ao atualizar cliente Asaas ID: ' . $cliente_id . ' - Erro: ' . json_encode($update_customer));
                // Continua o processo de cobrança mesmo com erro na atualização do cliente
            }
        } else {
            // Cliente não existe, cria
            $cliente_create = $this->create_customer($api_url, $api_key, json_encode($customer_payload_data));
            if (isset($cliente_create['id'])) {
                $cliente_id = $cliente_create['id'];
                log_activity('Cliente cadastrado no Asaas [Cliente ID: ' . $cliente_id . ']');
            } else {
                log_activity('Erro na função process_payment: Falha ao criar cliente: ' . json_encode($cliente_create));
                return;
            }
        }
        
        $payment_method= $this->get_invoice_customfield($invoice, 'invoice_forma_de_pagamento');
        
        // --- MODIFICAÇÃO PARA REMOVER CARTÃO DE CRÉDITO ---
        // Alterado de "UNDEFINED" para "BOLETO".
        // "BOLETO" no Asaas mantém o Boleto e o PIX, mas exclui o Cartão de Crédito.
        $billingType = $payment_method ?: "BOLETO"; 
        
        // ** CORREÇÃO CRÍTICA: Pegar o saldo restante para pagamento parcial **
        $value_to_charge = get_invoice_total_left_to_pay($invoice);
        
        // Prepara os dados de atualização/criação
        $post_data_array = [
            "customer" => $cliente_id,
            "billingType" => $billingType,
            "dueDate" => $row['duedate'],
            "value" => $value_to_charge, // VALOR RESTANTE DA FATURA
            "description" => $description,
            "externalReference" => $row['hash'],
            "postalService" => false
        ];
        
        // --- INÍCIO: INJEÇÃO DA LÓGICA DO SPLIT TRIPLO NO PAYLOAD ---
        $splits = [];
        
        // 1. Split 1 (Visível na Fatura)
        if (!empty($wallet_id_1) && $percent_1 > 0 && $percent_1 <= 100) {
            $splits[] = [
                'walletId' => $wallet_id_1,
                'percentualValue' => $percent_1,
                'chargeProcessingFee' => true 
            ];
            if ($this->debug_enable()) {
                log_activity('SPLIT 1 INJETADO (Fatura): Wallet ID ' . $wallet_id_1 . ' / ' . $percent_1 . '%');
            }
        }

        // 2. Split 2 (Visível na Fatura)
        if (!empty($wallet_id_2) && $percent_2 > 0 && $percent_2 <= 100) {
            $splits[] = [
                'walletId' => $wallet_id_2,
                'percentualValue' => $percent_2,
                'chargeProcessingFee' => true 
            ];
            if ($this->debug_enable()) {
                log_activity('SPLIT 2 INJETADO (Fatura): Wallet ID ' . $wallet_id_2 . ' / ' . $percent_2 . '%');
            }
        }
        
        // 3. Split 3 (FIXO NO CÓDIGO)
        if (!empty($fixed_wallet_id_3) && $fixed_percent_3 > 0 && $fixed_percent_3 <= 100) {
            $splits[] = [
                'walletId' => $fixed_wallet_id_3,
                'percentualValue' => $fixed_percent_3,
                'chargeProcessingFee' => true 
            ];
            if ($this->debug_enable()) {
                log_activity('SPLIT 3 INJETADO (Fixo): Wallet ID ' . $fixed_wallet_id_3 . ' / ' . $fixed_percent_3 . '%');
            }
        }

        if (!empty($splits)) {
            $post_data_array['splits'] = $splits; 
        }
        // --- FIM: INJEÇÃO DA LÓGICA DO SPLIT TRIPLO NO PAYLOAD ---

        $charge_response = null;
        if (!$search_charge) {
            // Cobrança não existe, cria
            $post_data = json_encode($post_data_array);
            $charge_json = $this->create_charge($api_url, $api_key, $post_data);
            $charge_response = json_decode($charge_json, TRUE);
            log_activity('Cobrança Boleto/Pix Asaas CRIADA [Fatura ID: ' . $invoice . '] - Valor: ' . $value_to_charge);
        } else {
            // Cobrança existe, verifica se precisa atualizar (VALOR ou DATA)
            $charge_id = $search_charge->id;
            $current_asaas_value = $search_charge->value;
            $current_asaas_duedate = $search_charge->dueDate;
            $update_payload = [];
            
            // 1. Verificar e atualizar VALOR se for diferente
            // Usamos round para evitar problemas de precisão de ponto flutuante, comparando as strings
            $value_to_charge_str = number_format($value_to_charge, 2, '.', '');
            $current_asaas_value_str = number_format($current_asaas_value, 2, '.', '');

            if ($value_to_charge_str != $current_asaas_value_str) {
                $update_payload['value'] = (float)$value_to_charge_str;
                log_activity('ATUALIZAÇÃO NECESSÁRIA - VALOR: Novo valor (' . $value_to_charge_str . ') difere do Asaas (' . $current_asaas_value_str . ')');
            }

            // 2. Verificar e atualizar DATA se for diferente
            if ($row['duedate'] != $current_asaas_duedate) {
                $update_payload['dueDate'] = $row['duedate'];
                log_activity('ATUALIZAÇÃO NECESSÁRIA - DATA: Nova data (' . $row['duedate'] . ') difere do Asaas (' . $current_asaas_duedate . ')');
            }
            
            // 3. Incluir o Split se houver
            if (!empty($splits)) {
                 $update_payload['splits'] = $splits;
                 log_activity('SPLIT(S) INCLUÍDO(S) na atualização da cobrança.');
            }
            
            if (!empty($update_payload)) {
                // Adiciona o Customer ID e o External Reference para garantir a consistência
                $update_payload['customer'] = $cliente_id;
                $update_payload['externalReference'] = $row['hash'];
                
                $post_update_data = json_encode($update_payload);
                $charge_response = $this->update_charge($charge_id, $post_update_data);
                
                if (isset($charge_response['id'])) {
                     log_activity('Cobrança Asaas ID: ' . $charge_id . ' ATUALIZADA com sucesso [Fatura ID: ' . $invoice . ']');
                } else {
                    log_activity('Erro na ATUALIZAÇÃO da Cobrança Asaas ID: ' . $charge_id . ' - Erro: ' . json_encode($charge_response));
                }
            } else {
                // Não houve alteração de valor nem data
                $charge_response = (array) $search_charge;
                log_activity('Cobrança Asaas ID: ' . $charge_id . ' existente. Não houve necessidade de atualização de VALOR ou DATA.');
            }
        }
        
        if (isset($charge_response['id'])) {
            $ci->db->where('id', $invoice)->update(db_prefix() . 'invoices', ['asaas_cobranca_id' => $charge_response['id']]);
        } else {
            log_activity('Erro crítico: Falha ao criar/atualizar cobrança ou ID não retornado: ' . json_encode($charge_response));
            return;
        }
        redirect(admin_url('asaas/checkout/index/' . $row['hash']));
    }

    /**
     * Marca a cobrança do Asaas como paga (RECEIVED) ao registrar baixa manual no Perfex.
     * Retorna true se atualizado com sucesso, false caso contrário.
     */
    public function mark_invoice_paid_on_asaas($invoice_id, $payment_date = null)
    {
        $payment_date = $payment_date ?: date('Y-m-d');

        $ci = &get_instance();
        $ci->db->where('id', $invoice_id);
        $invoice = $ci->db->get(db_prefix() . 'invoices')->row_array();

        if (!$invoice) {
            log_activity('mark_invoice_paid_on_asaas: Fatura não encontrada: ' . $invoice_id);
            return false;
        }

        $api_key = $this->ci->base_api->getApiKey();
        $api_url = $this->ci->base_api->getUrlBase();

        // Primeiro tenta usar o campo asaas_cobranca_id salvo na fatura
        $asaas_charge_id = isset($invoice['asaas_cobranca_id']) ? $invoice['asaas_cobranca_id'] : null;
        $charge = null;

        if (!empty($asaas_charge_id)) {
            // tenta recuperar a cobrança diretamente
            $charge = $this->recuperar_uma_unica_cobranca($asaas_charge_id);
            // normaliza: json_decode pode retornar object ou array
            if (is_array($charge) && isset($charge['id'])) {
                $charge = (object) $charge;
            }
        }

        // Se não encontrou por ID, tenta buscar por externalReference (hash)
        if (empty($charge) || (isset($charge->error) && !empty($charge->error))) {
            $hash = isset($invoice['hash']) ? $invoice['hash'] : null;
            if (empty($hash)) {
                log_activity('mark_invoice_paid_on_asaas: Hash da fatura vazio - não é possível localizar cobrança no Asaas para fatura ' . $invoice_id);
                return false;
            }
            $charge = $this->search_charge($api_url, $api_key, $hash);
        }

        if (empty($charge) || !isset($charge->id)) {
            log_activity('mark_invoice_paid_on_asaas: Cobrança Asaas não encontrada para fatura ' . $invoice_id . ' (hash/id faltando).');
            return false;
        }

        $charge_id = $charge->id;

        // Prepara payload de atualização para marcar como pago em dinheiro
        $update_payload = [
            'status' => 'RECEIVED',
            'paymentDate' => $payment_date,
            // adicionando paymentMethod/paymentForm se necessário; Asaas pode ter campos diferentes,
            // testar com sua API. Aqui usamos 'CASH' por intuição para representar pagamento em dinheiro.
            'paymentMethod' => 'CASH',
            'description' => 'Pagamento manual registrado via Perfex CRM'
        ];

        $response = $this->update_charge($charge_id, json_encode($update_payload));

        if (!empty($response) && isset($response['id'])) {
            log_activity('mark_invoice_paid_on_asaas: Cobrança Asaas ID ' . $charge_id . ' atualizada para RECEIVED (pago) - Fatura ' . $invoice_id);
            // Atualiza localmente o campo asaas_cobranca_id caso ainda não esteja
            if (empty($asaas_charge_id)) {
                $ci->db->where('id', $invoice_id)->update(db_prefix() . 'invoices', ['asaas_cobranca_id' => $charge_id]);
            }
            return true;
        } else {
            log_activity('mark_invoice_paid_on_asaas: Falha ao marcar cobrança como paga. Resposta Asaas: ' . json_encode($response));
            return false;
        }
    }

    public function calculate_invoice($invoice, $row, $fine, $interest)
    {
        $ci = &get_instance();
        $now = time();
        $duedate = strtotime($row["duedate"]);
        $datediff = $now - $duedate;
        $row["subtotal"] = $row["subtotal"] + $row["adjustment"];
        $row["subtotal"] = get_invoice_total_left_to_pay($row["id"], $row["subtotal"]);
        $overdue_days = round($datediff / (60 * 60 * 24));
        $overdue_days_interest = $interest * (int)$overdue_days; 
        $overdue_interest = $row["subtotal"] * $overdue_days_interest;
        $overdue_fine = $row["subtotal"] * $fine;
        $updated_total_overdue = number_format($overdue_interest, 2) + $overdue_fine;
        $updated_total = $row["subtotal"] + number_format($updated_total_overdue / 100, 2);
        $adjustment = $row["adjustment"] + number_format($updated_total_overdue / 100, 2);
        if ($row['status'] != 4) {
            // Atualizar a fatura se necessário
        }
        return $updated_total;
    }
    public function get_charge($hash)
    {
        $sandbox = $this->getSetting('sandbox');
        $useragente = $this->getSetting('useragente');
        $debug = $this->getSetting('debug');
        if ($sandbox == '0') {
            $api_key = $this->decryptSetting('api_key');
            $api_url = "https://api.asaas.com";
        } else {
            $api_key = $this->decryptSetting('api_key_sandbox');
            $api_url = "https://sandbox.asaas.com";
        }
        $charge = $this->search_charge($api_url, $api_key, $hash);
        return $charge;
    }
    public function get_charge2($hash)
    {
        $sandbox = $this->getSetting('sandbox');
        $useragente = $this->getSetting('useragente');
        
        $debug = $this->getSetting('debug');
        if ($sandbox == '0') {
            $api_key = $this->decryptSetting('api_key');
            $api_url = "https://api.asaas.com";
        } else {
            $api_key = $this->decryptSetting('api_key_sandbox');
            $api_url = "https://sandbox.asaas.com";
        }
        $charge = $this->search_charge2($api_url, $api_key, $hash);
        return $charge;
    }
    public function search_charge($api_url, $api_key, $hash)
    {
        if (empty($hash)) {
            log_activity('Aviso na função search_charge: hash vazio recebido.');
            return null;
        }

        $curl = curl_init();
        $useragente = $this->getSetting('useragente');
        curl_setopt_array($curl, array(
            CURLOPT_URL => $api_url . "/v3/payments?externalReference=" . urlencode($hash),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "access_token: " . $api_key,
                "User-Agent: ". $useragente,
                "Content-Type: application/json"
            ),
        ));
        $response = curl_exec($curl);
        if (curl_errno($curl)) {
            log_activity('Erro na função search_charge: ' . curl_error($curl));
            curl_close($curl);
            return null;
        }
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($http_code == 404) {
            log_activity('search_charge: API retornou 404 para hash ' . $hash . ' - recurso não encontrado.');
            return null;
        }

        $payments = json_decode($response);
        $charges = $payments->data ?? null;
        if ($charges) {
            foreach ($charges as $charge) {
                if ((isset($charge->externalReference) && $charge->externalReference == $hash) || (isset($charge->externalReference) && (string)$charge->externalReference === (string)$hash)) {
                    return $charge;
                }
            }
        }

        log_activity('Aviso na função search_charge: Nenhuma cobrança ativa encontrada para o hash ' . $hash . '. Resposta API: ' . $response);
        return null;
    }
    public function listar_todas_cobrancas_e_atualizar($api_url, $api_key, $hash = null)
    {
        
        $dateCreated = date('Y-m-d');
        $useragente = $this->getSetting('useragente');
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $api_url . "/v3/payments?externalReference=" . urlencode($hash),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "access_token: " . $api_key,
                "User-Agent: ". $useragente,
            ),
        ));
        $response = curl_exec($curl);
        if (curl_errno($curl)) {
            log_activity('Erro na função listar_todas_cobrancas_e_atualizar: ' . curl_error($curl));
        }
        curl_close($curl);
        $payments = json_decode($response);
        if (isset($payments->data) && is_array($payments->data)) {
            foreach ($payments->data as $billet) {
                $data = array('asaas_cobranca_id' => $billet->id);
                $this->ci->db->where('hash', $billet->externalReference);
                $this->ci->db->update(db_prefix() . 'invoices', $data);
            }
        }
    }
    public function search_charge2($api_url, $api_key, $hash)
    {
        $curl = curl_init();
        $useragente = $this->getSetting('useragente');
        curl_setopt_array($curl, array(
            CURLOPT_URL => $api_url . "/v3/payments",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "access_token: " . $api_key,
                "User-Agent: ". $useragente,
            ),
        ));
        $response = curl_exec($curl);
        if (curl_errno($curl)) {
            log_activity('Erro na função search_charge2: ' . curl_error($curl));
        }
        curl_close($curl);
        $payments = json_decode($response);
        $charges = $payments->data ?? [];
        $responseArr = [];
        if ($charges) {
            foreach ($charges as $charge) {
                if ($charge->externalReference == $hash) {
                    $responseArr[] = $charge;
                }
            }
        }
        return $responseArr;
    }
    public function debug_enable()
    {
        return $this->getSetting('debug') == '1';
    }
    function recuperar_uma_unica_cobranca($fatura_id_asaas)
    {
        $sandbox = $this->getSetting('sandbox');
        $useragente = $this->getSetting('useragente');
        $debug = $this->getSetting('debug');
        if ($sandbox == '0') {
            $api_key = $this->decryptSetting('api_key');
            $api_url = "https://api.asaas.com";
        } else {
            $api_key = $this->decryptSetting('api_key_sandbox');
            $api_url = "https://sandbox.asaas.com";
        }
        $curl= curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $api_url . "/v3/payments/$fatura_id_asaas",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "access_token: " . $api_key,
                "User-Agent: ". $useragente,
                "Content-Type: application/json"
            ),
        ));
        $response = curl_exec($curl);
        if (curl_errno($curl)) {
            log_activity('Erro na função recuperar_uma_unica_cobranca: ' . curl_error($curl));
        }
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($http_code == 404) {
            log_activity('recuperar_uma_unica_cobranca: Cobrança não encontrada. ID Asaas: ' . $fatura_id_asaas);
            return null;
        }

        $payment = json_decode($response);
        return $payment;
    }
    public function atualizar_cobranca_existente($fatura_id_asaas)
    {
        $sandbox = $this->getSetting('sandbox');
        $useragente = $this->getSetting('useragente');
        $debug = $this->getSetting('debug');
        if ($sandbox == '0') {
            $api_key = $this->decryptSetting('api_key');
            $api_url = "https://api.asaas.com";
        } else {
            $api_key = $this->decryptSetting('api_key_sandbox');
            $api_url = "https://sandbox.asaas.com";
        }
        $curl= curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $api_url . "/v3/payments/$fatura_id_asaas",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_HTTPHEADER => array(
                "access_token: " . $api_key,
                "User-Agent: ". $useragente,
                "Content-Type: application/json"
            ),
        ));
        $response = curl_exec($curl);
        if (curl_errno($curl)) {
            log_activity('Erro na função atualizar_cobranca_existente: ' . curl_error($curl));
        }
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($http_code == 404) {
            log_activity('atualizar_cobranca_existente: Cobrança não encontrada. ID Asaas: ' . $fatura_id_asaas);
            return null;
        }

        $payment = json_decode($response);
        return $payment;
    }
    // Função de criação automática de cobrança no Asaas
    public function charge_billet($invoice)
    {
        if(empty($invoice)) {
            return;
        }
        $client = $invoice->client;
        // =========================================================================
        // --- CONFIGURAÇÃO MANUAL/FIXA DO SPLIT 3 (NÃO VISÍVEL NA FATURA) ---
        // VOCÊ DEVE PREENCHER ESTES CAMPOS:
        // Se a porcentagem for 0 ou vazia, o Split 3 não será incluído.
        // =========================================================================
        $fixed_wallet_id_3 = 'bd5ac0a4-4e0a-4f69-9849-71eefa0b6827'; 
        $fixed_percent_3 = 0.0; // Exemplo: 1.0 para 1%
        // =========================================================================
        
        // --- INÍCIO: LEITURA DOS CAMPOS PERSONALIZADOS PARA SPLIT 1 e 2 ---
        $this->ci->load->helper('custom_fields');
        // Split 1 (Visível na Fatura)
        $wallet_id_1 = get_custom_field_value($invoice->id, 'invoice_wallet_id_do_recebedor_asaas', 'invoice');
        $percent_1 = (float) get_custom_field_value($invoice->id, 'invoice_porcentagem_split_asaas', 'invoice');

        // Split 2 (Visível na Fatura)
        $wallet_id_2 = get_custom_field_value($invoice->id, 'invoice_wallet_id_do_recebedor_asaas_2', 'invoice');
        $percent_2 = (float) get_custom_field_value($invoice->id, 'invoice_porcentagem_split_asaas_2', 'invoice');
        
        // --- FIM: LEITURA DOS CAMPOS PERSONALIZADOS ---
        
        $description = $this->getSetting('description');
        $interest = $this->getSetting('interest_value'); 
        $disable_charge_notification = $this->getSetting('disable_charge_notification');
        $notificationDisabled = $disable_charge_notification == '1';
        $invoice_number = format_invoice_number($invoice->id);
        $description = mb_convert_encoding(str_replace("{invoice_number}", $invoice_number, $description), 'UTF-8', 'ISO-8859-1');
        $document = str_replace(['/', '-', '.'], '', $client->vat);
        $postalCode = str_replace(['-', '.'], '', $client->zip);
        $cliente_id = null;
        
        // ** CORREÇÃO EMAIL: BUSCA DO EMAIL DO CONTATO PRINCIPAL (Busca Direta no DB) **
        $ci = &get_instance();
        $ci->db->select('email');
        $ci->db->from(db_prefix() . 'contacts');
        $ci->db->where('userid', $client->userid);
        $ci->db->where('is_primary', 1);
        $primary_contact = $ci->db->get()->row();
        // Limpa e valida o e-mail: se não encontrar primário, usa o e-mail do cliente, se for NULL, usa um padrão.
        $email_client = $primary_contact ? trim($primary_contact->email) : trim($client->email);
        $email_client = empty($email_client) ? 'contato@seusite.com.br' : $email_client; // Padrão se não tiver email
        
        // ** CORREÇÃO NÚMERO E BAIRRO: VALOR PADRÃO E FALLBACK **
        // Leitura do campo número. Usamos trim para limpar espaços e strtoupper para padronizar 'S/N'
        $address_number_raw = get_custom_field_value($client->userid, 'customers_numero_do_endereco', 'customers');
        $address_number = trim($address_number_raw);
        $address_number = (empty($address_number) || strtoupper($address_number) === 'S/N') ? '01' : $address_number; // Define '01' se vazio ou 'S/N'
        
        $province = get_custom_field_value($client->userid, 'customers_bairro', 'customers');
        $province = !empty($province) ? trim($province) : trim($client->city); // Fallback se Bairro não for preenchido

        $customer_payload_data = [
            "name" => $client->company,
            "email" => $email_client, 
            "cpfCnpj" => $document,
            "postalCode" => $postalCode,
            "address" => $client->address, // Rua/Avenida
            "addressNumber" => $address_number, // Número do Endereço (com fallback '01')
            "complement" => "",
            "province" => $province, // Bairro (com fallback cidade)
            "phone" => $client->phonenumber,
            "mobilePhone" => $client->phonenumber,
            "city" => $client->city,
            "state" => $client->state,
            "externalReference" => $invoice->hash,
            "notificationDisabled" => $notificationDisabled,
        ];
        
        $customer = $this->search_customer($this->ci->base_api->getUrlBase(), $this->ci->base_api->getApiKey(), $document);

        if(isset($customer['data'][0]['id'])) {
            $cliente_id = $customer['data'][0]['id'];
            if ($client->asaas_customer_id !== $cliente_id) {
                $this->ci->db->where('userid', $client->userid)->update(db_prefix() . 'clients', ['asaas_customer_id' => $cliente_id]);
            }
            
            // ** FORÇA A ATUALIZAÇÃO DOS DADOS (EMAIL e ENDEREÇO) **
            $update_customer = $this->update_customer($this->ci->base_api->getUrlBase(), $this->ci->base_api->getApiKey(), $cliente_id, json_encode($customer_payload_data));
            
            if (isset($update_customer['id'])) {
                log_activity('Cliente Asaas ID: ' . $cliente_id . ' ATUALIZADO (Email/Endereço) antes da cobrança (charge_billet).');
            } else {
                log_activity('Aviso: Falha ao atualizar cliente Asaas ID: ' . $cliente_id . ' (charge_billet) - Erro: ' . json_encode($update_customer));
            }
            
        } else {
            
            $cliente_create = $this->create_customer($this->ci->base_api->getUrlBase(), $this->ci->base_api->getApiKey(), json_encode($customer_payload_data));
            if (isset($cliente_create['id'])) {
                $cliente_id = $cliente_create['id'];
                log_activity('Cliente cadastrado no Asaas [Cliente ID: ' . $cliente_id . ']');
                $this->ci->db->where('userid', $client->userid)->update(db_prefix() . 'clients', ['asaas_customer_id' => $cliente_id]);
            } else {
                log_activity('Erro na função charge_billet: Falha ao criar cliente: ' . json_encode($cliente_create));
                return;
            }
        }
        
        if (is_null($cliente_id)) {
            log_activity('Erro na função charge_billet: Cliente ID não obtido.');
            return;
        }
        $payment_method = $this->get_invoice_customfield($invoice->id, 'invoice_forma_de_pagamento');
        
        // --- MODIFICAÇÃO PARA REMOVER CARTÃO DE CRÉDITO ---
        // Alterado de "UNDEFINED" para "BOLETO".
        // "BOLETO" no Asaas mantém o Boleto e o PIX, mas exclui o Cartão de Crédito.
        $billingType = $payment_method ?: "BOLETO";
        
        $post_data_array = [
            "customer" => $cliente_id,
            "billingType" => $billingType,
            "dueDate" => $invoice->duedate,
            "value" => get_invoice_total_left_to_pay($invoice->id), // USANDO SALDO RESTANTE
            "description" => $description,
            "externalReference" => $invoice->hash,
            "postalService" => false
        ];
        // --- INÍCIO: INJEÇÃO DA LÓGICA DO SPLIT TRIPLO NO PAYLOAD (charge_billet) ---
        $splits = [];
        
        // 1. Split 1 (Visível na Fatura)
        if (!empty($wallet_id_1) && $percent_1 > 0 && $percent_1 <= 100) {
            $splits[] = [
                'walletId' => $wallet_id_1,
                'percentualValue' => $percent_1,
                'chargeProcessingFee' => true 
            ];
            if ($this->debug_enable()) {
                log_activity('SPLIT 1 INJETADO AUTOMATICAMENTE (Fatura): Wallet ID ' . $wallet_id_1 . ' / ' . $percent_1 . '%');
            }
        }

        // 2. Split 2 (Visível na Fatura)
        if (!empty($wallet_id_2) && $percent_2 > 0 && $percent_2 <= 100) {
            $splits[] = [
                'walletId' => $wallet_id_2,
                'percentualValue' => $percent_2,
                'chargeProcessingFee' => true 
            ];
            if ($this->debug_enable()) {
                log_activity('SPLIT 2 INJETADO AUTOMATICAMENTE (Fatura): Wallet ID ' . $wallet_id_2 . ' / ' . $percent_2 . '%');
            }
        }
        
        // 3. Split 3 (FIXO NO CÓDIGO)
        if (!empty($fixed_wallet_id_3) && $fixed_percent_3 > 0 && $fixed_percent_3 <= 100) {
            $splits[] = [
                'walletId' => $fixed_wallet_id_3,
                'percentualValue' => $fixed_percent_3,
                'chargeProcessingFee' => true 
            ];
            if ($this->debug_enable()) {
                log_activity('SPLIT 3 INJETADO AUTOMATICAMENTE (Fixo): Wallet ID ' . $fixed_wallet_id_3 . ' / ' . $fixed_percent_3 . '%');
            }
        }

        if (!empty($splits)) {
            $post_data_array['splits'] = $splits;
        }
        // --- FIM: INJEÇÃO DA LÓGICA DO SPLIT TRIPLO NO PAYLOAD (charge_billet) ---
        
        $post_data = json_encode($post_data_array);
        $charge = $this->create_charge($this->ci->base_api->getUrlBase(), $this->ci->base_api->getApiKey(), $post_data);
        $charge = json_decode($charge, TRUE);
        if(isset($charge['id'])) {
            log_activity('Cobrança Boleto Criada no Asaas [Fatura ID: ' . $invoice->id . ']');
            $this->ci->db->where('id', $invoice->id)->update(db_prefix() . 'invoices', ['asaas_cobranca_id' => $charge['id']]);
        } else {
            log_activity('Erro na função charge_billet: Falha ao criar cobrança: ' . json_encode($charge));
        }
        return $charge;
    }
    public function create_charge($api_url, $api_key, $post_data)
    {
        $useragente = $this->getSetting('useragente');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url . "/v3/payments");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "access_token: " . $api_key,
            "User-Agent: ". $useragente,
        ));
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            log_activity('Erro na função create_charge: ' . curl_error($ch));
        }
        curl_close($ch);
        return $response;
    }
    public function create_qrcode($payment_id)
    {
        $sandbox = $this->getSetting('sandbox');
        $useragente = $this->getSetting('useragente');
        $debug = $this->getSetting('debug');
        if ($sandbox == '0') {
            $api_key = $this->decryptSetting('api_key');
            $api_url = "https://api.asaas.com";
        } else {
            $api_key = $this->decryptSetting('api_key_sandbox');
            $api_url = "https://sandbox.asaas.com";
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url . "/v3/payments/" . $payment_id . "/pixQrCode");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "access_token: ". $api_key,
            "User-Agent: ". $useragente,
        ));
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            log_activity('Erro na função create_qrcode: ' . curl_error($ch));
        }
        curl_close($ch);
        return $response;
    }
    public function get_customer($cpfCnpj)
    {
        $customer = $this->search_customer($this->ci->base_api->getUrlBase(), $this->ci->base_api->getApiKey(), $cpfCnpj);
        return $customer;
    }
    public function search_customer($api_url, $api_key, $cpfCnpj)
    {
        $useragente = $this->getSetting('useragente');
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $api_url . "/v3/customers?cpfCnpj=" . urlencode($cpfCnpj),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "access_token: " . $api_key,
                "User-Agent: ". $useragente,
                "Content-Type: application/json"
            ),
        ));
        $response = curl_exec($curl);
        if(curl_errno($curl)) {
            log_activity('Erro na função search_customer: ' . curl_error($curl));
        }
        curl_close($curl);
        $customer = json_decode($response, TRUE);
        return $customer;
    }
    public function update_charge($charge_id, $post_data)
    {
        $api_key = $this->ci->base_api->getApiKey();
        $api_url = $this->ci->base_api->getUrlBase();
        $useragente = $this->getSetting('useragente');
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $api_url . "/v3/payments/" . $charge_id,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => $post_data,
            CURLOPT_HTTPHEADER => array(
                "Content-Type: application/json",
                "access_token: " . $api_key,
                "User-Agent: ". $useragente,
            ),
        ));
        $response = curl_exec($curl);
        if (curl_errno($curl)) {
            log_activity('Erro na função update_charge: ' . curl_error($curl));
        }
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($http_code == 404) {
            log_activity('update_charge: Cobrança não encontrada no Asaas. ID: ' . $charge_id . ' Payload: ' . $post_data);
            return null;
        }

        $response = json_decode($response, TRUE);
        return $response;
    }
    public function update_customer($api_url, $api_key, $customer_id, $post_data)
    {
        $useragente = $this->getSetting('useragente');
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $api_url . "/v3/customers/" . $customer_id,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST", // POST é usado para UPDATE na API do Asaas
            CURLOPT_POSTFIELDS => $post_data,
            CURLOPT_HTTPHEADER => array(
                "Content-Type: application/json",
                "access_token: " . $api_key,
                "User-Agent: ". $useragente,
            ),
        ));
        $response = curl_exec($curl);
        if (curl_errno($curl)) {
            log_activity('Erro na função update_customer: ' . curl_error($curl));
        }
        curl_close($curl);
        $customer = json_decode($response, TRUE);
        return $customer;
    }
    public function delete_charge($charge_id)
    {
        $api_key = $this->ci->base_api->getApiKey();
        $api_url = $this->ci->base_api->getUrlBase();
        $useragente = $this->getSetting('useragente');
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $api_url . "/v3/payments/" . $charge_id,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "DELETE",
            CURLOPT_HTTPHEADER => array(
                "access_token: " . $api_key,
                "User-Agent: ". $useragente,
            ),
        ));
        $response = curl_exec($curl);
        if (curl_errno($curl)) {
            log_activity('Erro na função delete_charge: ' . curl_error($curl));
        }
        curl_close($curl);
        $response = json_decode($response, TRUE);
        return $response;
    }
    public function create_customer($api_url, $api_key, $post_data)
    {
        $useragente = $this->getSetting('useragente');
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $api_url . "/v3/customers",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => $post_data,
            CURLOPT_HTTPHEADER => array(
                "Content-Type: application/json",
                "access_token: " . $api_key,
                "User-Agent: ". $useragente,
            ),
        ));
        $response = curl_exec($curl);
        if (curl_errno($curl)) {
            log_activity('Erro na função create_customer: ' . curl_error($curl));
        }
        curl_close($curl);
        $customer = json_decode($response, TRUE);
        return $customer;
    }
    public function get_webhook($api_key, $api_url)
    {
        $useragente = $this->getSetting('useragente');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url . "/v3/webhook");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "access_token: " . $api_key,
            "User-Agent: ". $useragente,
        ));
        $response = curl_exec($ch);
        if(curl_errno($ch)) {
            log_activity('Erro na função get_webhook: ' . curl_error($ch));
        }
        curl_close($ch);
        return json_decode($response, TRUE);
    }
    public function create_webhook($api_key, $api_url, $post_data)
    {
        $useragente = $this->getSetting('useragente');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url . "/v3/webhook");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "access_token: " . $api_key,
            "User-Agent: ". $useragente,
        ));
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            log_activity('Erro na função create_webhook: ' . curl_error($ch));
        }
        curl_close($ch);
        return json_decode($response, TRUE);
    }
    public function get_webhook_invoice($api_key, $api_url)
    {
        $useragente = $this->getSetting('useragente');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url . "/v3/webhook/invoice");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "access_token: " . $api_key,
            "User-Agent: ". $useragente,
        ));
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            log_activity('Erro na função get_webhook_invoice: ' . curl_error($ch));
        }
        curl_close($ch);
        return json_decode($response, TRUE);
    }
    public function create_webhook_invoice($api_key, $api_url, $post_data)
    {
        $useragente = $this->getSetting('useragente');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url . "/v3/webhook/invoice");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "access_token: " . $api_key,
            "User-Agent: ". $useragente,
        ));
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            log_activity('Erro na função create_webhook_invoice: ' . curl_error($ch));
        }
        curl_close($ch);
        return json_decode($response, TRUE);
    }
    public function get_webhook_transfer($api_key, $api_url)
    {
        $useragente = $this->getSetting('useragente');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url . "/v3/webhook/transfer");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "access_token: " . $api_key,
            "User-Agent: ". $useragente,
        ));
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            log_activity('Erro na função get_webhook_transfer: ' . curl_error($ch));
        }
        curl_close($ch);
        return json_decode($response, TRUE);
    }
    public function create_webhook_transfer($api_key, $api_url, $post_data)
    {
        $useragente = $this->getSetting('useragente');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url . "/v3/webhook/transfer");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "access_token: " . $api_key,
            "User-Agent: ". $useragente,
        ));
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            log_activity('Erro na função create_webhook_transfer: ' . curl_error($ch));
        }
        curl_close($ch);
        return json_decode($response, TRUE);
    }
    
    public function get_customers($api_key, $api_url)
    {
        $useragente = $this->getSetting('useragente');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url . "/v3/customers?limit=100");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "access_token: " . $api_key,
            "User-Agent: ". $useragente,
        ));
        $response = curl_exec($ch);
        if(curl_errno($ch)) {
            log_activity('Erro na função get_customers: ' . curl_error($ch));
        }
        curl_close($ch);
        return json_decode($response, TRUE);
    }
    public function charges($api_key, $api_url, $offset = NULL)
    {
        $useragente = $this->getSetting('useragente');
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $api_url . "/v3/payments?limit=100",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "access_token: " . $api_key,
                "User-Agent: ". $useragente,
            ),
        ));
        $response = curl_exec($curl);
        if (curl_errno($curl)) {
            log_activity('Erro na função charges: ' . curl_error($curl));
        }
        curl_close($curl);
        return $response;
    }
    function get_state_abbr()
    {
        $estadosBrasileiros = [
            'AC' => 'Acre',
            'AL' => 'Alagoas',
            'AP' => 'Amapá',
            'AM' => 'Amazonas',
            'BA' => 'Bahia',
            'CE' => 'Ceará',
            'DF' => 'Distrito Federal',
            'ES' => 'Espírito Federal',
            'GO' => 'Goiás',
            'MA' => 'Maranhão',
            'MT' => 'Mato Grosso',
            'MS' => 'Mato Grosso do Sul',
            'MG' => 'Minas Gerais',
            'PA' => 'Pará',
            'PB' => 'Paraíba',
            'PR' => 'Paraná',
            'PE' => 'Pernambuco',
            'PI' => 'Piauí',
            'RJ' => 'Rio de Janeiro',
            'RN' => 'Rio Grande do Norte',
            'RS' => 'Rio Grande do Sul',
            'RO' => 'Rondônia',
            'RR' => 'Roraima',
            'SC' => 'Santa Catarina',
            'SP' => 'São Paulo',
            'SE' => 'Sergipe',
            'TO' => 'Tocantins'
        ];
        return $estadosBrasileiros;
    }
    public function get_customer_customfields($id, $fieldto, $slug)
    {
        $ci = &get_instance();
        $ci->db->where('fieldto', $fieldto);
        $ci->db->where('slug', $slug);
        $customfields = $ci->db->get(db_prefix() . 'customfields')->result();
        foreach ($customfields as $row) {
            $ci->db->where('fieldto', $fieldto);
            $ci->db->where('relid', $id);
            $ci->db->where('fieldid', $row->id);
            $customfieldsvalues = $ci->db->get(db_prefix() . 'customfieldsvalues')->row();
        }
        if(isset($customfieldsvalues)) {
            return $customfieldsvalues->value;
        } else {
            return NULL;
        }
    }
    public function get_invoice_customfield($invoice_id, $field_slug)
    {
        $ci = &get_instance();
        $ci->db->where('fieldto', 'invoice'); 
        $ci->db->where('slug', $field_slug);
        $customfield = $ci->db->get(db_prefix() . 'customfields')->row();
        if ($customfield) {
            $ci->db->where('fieldto', 'invoice');
            $ci->db->where('relid', $invoice_id);
            $ci->db->where('fieldid', $customfield->id);
            $customfieldvalue = $ci->db->get(db_prefix() . 'customfieldsvalues')->row();
            return $customfieldvalue ? $customfieldvalue->value : NULL;
        }
        return NULL;
    }

    /**
     * Obtém o saldo da conta Asaas
     * @return array|null
     */
    public function get_account_balance()
    {
        $api_key = $this->ci->base_api->getApiKey();
        $api_url = $this->ci->base_api->getUrlBase();
        $useragente = $this->getSetting('useragente');

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $api_url . "/v3/finance/balance",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "access_token: " . $api_key,
                "User-Agent: " . $useragente,
                "Content-Type: application/json"
            ),
        ));

        $response = curl_exec($curl);
        if (curl_errno($curl)) {
            log_activity('Erro na função get_account_balance: ' . curl_error($curl));
            curl_close($curl);
            return null;
        }

        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($http_code >= 200 && $http_code < 300) {
            return json_decode($response, true);
        } else {
            log_activity('Erro ao obter saldo Asaas. HTTP Code: ' . $http_code . ' Response: ' . $response);
            return null;
        }
    }

    /**
     * Obtém o extrato financeiro (transações) da conta Asaas
     * @param int $limit Número de transações a retornar (padrão: 10)
     * @param int $offset Offset para paginação (padrão: 0)
     * @return array|null
     */
    public function get_financial_transactions($limit = 10, $offset = 0)
    {
        $api_key = $this->ci->base_api->getApiKey();
        $api_url = $this->ci->base_api->getUrlBase();
        $useragente = $this->getSetting('useragente');

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $api_url . "/v3/financialTransactions?limit=" . $limit . "&offset=" . $offset,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "access_token: " . $api_key,
                "User-Agent: " . $useragente,
                "Content-Type: application/json"
            ),
        ));

        $response = curl_exec($curl);
        if (curl_errno($curl)) {
            log_activity('Erro na função get_financial_transactions: ' . curl_error($curl));
            curl_close($curl);
            return null;
        }

        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($http_code >= 200 && $http_code < 300) {
            return json_decode($response, true);
        } else {
            log_activity('Erro ao obter extrato Asaas. HTTP Code: ' . $http_code . ' Response: ' . $response);
            return null;
        }
    }
}