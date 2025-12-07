<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Checkout extends ClientsController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('asaas_gateway');
        $this->load->helper('general');
        $this->load->model('invoices_model');
    }

    public function index($hash)
    {
        // Busca a fatura pelo hash
        $this->db->where('hash', $hash);
        $invoice = $this->db->get(db_prefix() . 'invoices')->row();

        if ($invoice) {
            // Puxa os detalhes da cobrança com base no hash da fatura
            $charge = $this->asaas_gateway->get_charge($hash);

            // Verifica se a cobrança tem um invoiceUrl
            if ($charge && !empty($charge->invoiceUrl)) {
                // Redireciona para a 'invoiceUrl'
                redirect($charge->invoiceUrl);
            } else {
                // Se não encontrar uma cobrança com 'invoiceUrl', cria uma nova cobrança
                $new_charge = $this->create_new_charge($invoice);

                if ($new_charge && !empty($new_charge->invoiceUrl)) {
                    // Redireciona para a nova invoiceUrl
                    redirect($new_charge->invoiceUrl);
                } else {
                    // Redireciona para uma página de erro se a nova cobrança não puder ser criada
                    set_alert('warning', 'Não foi possível criar uma nova cobrança. Por favor, tente novamente ou contate o suporte.');
                    redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash), 'refresh');
                }
            }
        } else {
            // Se a fatura não for encontrada pelo hash, redireciona para uma página de erro
            set_alert('warning', 'Fatura não encontrada.');
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash), 'refresh');
        }
    }

    private function create_new_charge($invoice)
    {
        // Busca os dados do cliente
        $this->db->where('userid', $invoice->clientid);
        $client = $this->db->get(db_prefix() . 'clients')->row();

        // Pega o billingType da fatura, armazenado em um custom field, por exemplo
        $billingType = $this->get_invoice_customfield($invoice->id, 'invoice_forma_de_pagamento');

        // Define o fuso horário de Brasília (UTC-3)
        $datetime = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        
        // Adiciona um dia à data atual
        $datetime->modify('+1 day');
        
        // Formata a data no formato correto (YYYY-MM-DD)
        $DateDue = $datetime->format('Y-m-d');

        // Prepara os dados para a nova cobrança
        $post_data = [
            "customer" => $client->asaas_customer_id, // Id do cliente no Asaas
            "billingType" => $billingType ?: "BOLETO", // Usa o billingType armazenado, ou "BOLETO" como fallback
            "dueDate" => $DateDue, // Define a data de vencimento como o próximo dia
            "value" => number_format($invoice->total, 2, '.', ''), // Valor da fatura
            "description" => "Pagamento da Fatura " . $invoice->prefix . str_pad($invoice->number, 6, "0", STR_PAD_LEFT),
            "externalReference" => $invoice->hash, // Mantém a referência original
            "postalService" => false
        ];

        // Envia o log para o console do navegador
        echo "<script>console.log('post_data: " . json_encode($post_data) . "');</script>";

        // Cria a nova cobrança através da API
        $response = $this->asaas_gateway->create_charge(json_encode($post_data));

        // Verifica o que foi retornado pela API no console
        echo "<script>console.log('API Response: " . json_encode($response) . "');</script>";

        // Retorna o resultado da nova cobrança
        return $response ? json_decode($response) : null;
    }
}
