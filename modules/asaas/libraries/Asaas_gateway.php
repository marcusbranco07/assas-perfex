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
            ),
            // CAMPOS NFS-e (ANTES SÓ TÍNHAMOS 2, AGORA COMPLETO):
            array(
                'name'  => 'municipalServiceId',
                'label' => 'Código de serviço (NFS-e / Asaas) - ID do serviço',
                'type'  => 'input',
            ),
            array(
                'name'  => 'municipalServiceCode',
                'label' => 'Código de serviço municipal (NFS-e / Prefeitura)',
                'type'  => 'input',
            ),
            array(
                'name'  => 'municipalServiceName',
                'label' => 'Descrição do serviço (NFS-e)',
                'type'  => 'input',
                'default_value' => 'Serviços contratados',
            ),
            array(
                'name'  => 'nfe_iss_percent',
                'label' => 'Percentual de ISS (NFS-e) - ex: 3.24',
                'type'  => 'input',
            ),
        ));
    }

    public function process_payment($data)
    {
        // ... (CONTEÚDO COMPLETO IGUAL AO QUE ENVIEI NA MENSAGEM ANTERIOR, COM TODAS AS FUNÇÕES E AJUSTES NFS-e, SPLIT, ETC.) ...
    }

    // Todo o restante do conteúdo do arquivo Asaas_gateway.php deve ser
    // exatamente o mesmo que forneci na mensagem anterior, incluindo:
    // - should_emit_nfe
    // - emitir_nfe_asaas (com municipalServiceId, municipalServiceCode, municipalServiceName, nfe_iss_percent)
    // - todas as funções auxiliares já ajustadas.

?>
