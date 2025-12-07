<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Invoice_callback extends APP_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('asaas_gateway');
    }

    /**
     * Callback exclusivo para eventos de NOTA FISCAL (NFS-e) do Asaas.
     *
     * URL sugerida para configurar no Asaas:
     *   https://SEU_DOMINIO/admin/asaas/gateways/invoice_callback/index
     */
    public function index()
    {
        if (strcasecmp($_SERVER['REQUEST_METHOD'], 'POST') !== 0) {
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }

        $raw = trim(file_get_contents("php://input"));
        if (empty($raw)) {
            http_response_code(400);
            echo 'Payload vazio';
            return;
        }

        $content = json_decode($raw);

        if (json_last_error() !== JSON_ERROR_NONE || !is_object($content)) {
            log_activity('Asaas NFE CALLBACK: JSON inválido recebido: ' . $raw);
            http_response_code(400);
            echo 'JSON inválido';
            return;
        }

        $event = isset($content->event) ? $content->event : 'desconhecido';
        log_activity('Asaas NFE CALLBACK: Evento recebido: ' . $event . ' | Payload: ' . $raw);

        if (!isset($content->invoice) || !is_object($content->invoice)) {
            http_response_code(200);
            echo 'Evento sem objeto invoice. OK';
            return;
        }

        $invoiceObj = $content->invoice;

        $asaas_invoice_id  = isset($invoiceObj->id) ? $invoiceObj->id : null;
        $asaas_payment_id  = isset($invoiceObj->payment) ? $invoiceObj->payment : null;
        $asaas_status      = isset($invoiceObj->status) ? $invoiceObj->status : null;
        $asaas_value       = isset($invoiceObj->value) ? $invoiceObj->value : null;
        $externalReference = isset($invoiceObj->externalReference) ? $invoiceObj->externalReference : null;

        if (empty($asaas_invoice_id)) {
            log_activity('Asaas NFE CALLBACK: invoice.id ausente no payload de NFS-e.');
            http_response_code(200);
            echo 'invoice.id ausente. OK';
            return;
        }

        $perfex_invoice = null;

        if (!empty($asaas_payment_id)) {
            $this->db->where('transactionid', $asaas_payment_id);
            $payment_record = $this->db->get(db_prefix() . 'invoicepaymentrecords')->row();
            if ($payment_record) {
                $this->db->where('id', $payment_record->invoiceid);
                $perfex_invoice = $this->db->get(db_prefix() . 'invoices')->row();
            }
        }

        if (!$perfex_invoice && !empty($externalReference)) {
            $this->db->group_start();
            $this->db->where('hash', $externalReference);
            $this->db->or_where('id', $externalReference);
            $this->db->group_end();
            $perfex_invoice = $this->db->get(db_prefix() . 'invoices')->row();
        }

        if ($perfex_invoice) {
            log_activity('Asaas NFE CALLBACK: NFS-e ' . $asaas_invoice_id . ' (status: ' . $asaas_status . ') associada à fatura Perfex ID ' . $perfex_invoice->id . '.');
        } else {
            log_activity('Asaas NFE CALLBACK: NFS-e ' . $asaas_invoice_id . ' (status: ' . $asaas_status . ') recebida, mas nenhuma fatura Perfex foi localizada pelo payment/externalReference.');
        }

        http_response_code(200);
        echo 'Asaas NFE CALLBACK: OK';
    }
}

?>
