<?php

/**
 * Ensures that the module init file can't be accessed directly, only within the application.
 */
defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Asaas - Módulo de Pagamento v 3.4.0
Description: Integração Asaas - V.3.4.0 - Melhorias Webhooks
Author: MNS -> Lucas Vasconcelos
Version: 3.4.0
Requires at least: 3.1.0*
Author URI: http://cmd.mns.marketing
*/

define('ASAAS_MODULE_NAME', 'asaas');

hooks()->add_action('admin_init', 'asaas_permissions');
hooks()->add_action('before_render_payment_gateway_settings', 'asaas_before_render_payment_gateway_settings');
hooks()->add_action('app_admin_footer', 'asaas_settings_tab_footer');
hooks()->add_action('after_invoice_added', 'asaas_after_invoice_added');
hooks()->add_action('invoice_updated', 'asaas_after_invoice_updated');
hooks()->add_action('before_invoice_deleted', 'asaas_before_invoice_deleted');
hooks()->add_filter('module_asaas_action_links', 'module_asaas_action_links');
hooks()->add_filter('get_dashboard_widgets', 'asaas_add_dashboard_widget');

$CI = &get_instance();

register_activation_hook(ASAAS_MODULE_NAME, 'asaas_module_activation_hook');

function asaas_module_activation_hook()
{
    require_once(__DIR__ . '/install.php');
}

function module_asaas_action_links($actions)
{
    $actions[] = '<a href="' .  admin_url("settings?group=payment_gateways#online_payments_asaas_tab")  . '">Configurações</a>';
    $actions[] = '<a href="https://app.mns.marketing/knowledge-base/article/instala-ao-do-asaas" target="_blank">Documentação</a>';
    $actions[] = '<a href="https://cmd.mns.marketing" target="_blank">Página do Módulo</a>';
    return $actions;
}

register_payment_gateway('asaas_gateway', ASAAS_MODULE_NAME);

/**
 * Registra as permissões do módulo Asaas
 */
function asaas_permissions() {
    $capabilities = [];
    $capabilities['capabilities'] = [
        'view' => _l('permission_view'),
    ];
    register_staff_capabilities('asaas_dashboard', $capabilities, _l('Asaas Dashboard Widget'));
}

function asaas_before_render_payment_gateway_settings($gateway)
{
    return $gateway;
}

function asaas_settings_tab_footer()
{
?>
    <script>
        $(document).ready(function() {
            $(".form-control datepicker").attr("required", "true");

            function validate_invoice_form(e) {
                e = void 0 === e ? "#invoice-form" : e,
                    appValidateForm($(e), {
                        clientid: {
                            required: {
                                depends: function() {
                                    return !$("select#clientid").hasClass("customer-removed")
                                }
                            }
                        },
                        date: "required",
                        currency: "required",
                        repeat_every_custom: {
                            min: 1
                        },
                        number: {
                            required: !0
                        }
                    }), $("body").find('input[name="number"]').rules("add", {
                        remote: {
                            url: admin_url + "invoices/validate_invoice_number",
                            type: "post",
                            data: {
                                number: function() {
                                    return $('input[name="number"]').val()
                                },
                                isedit: function() {
                                    return $('input[name="number"]').data("isedit")
                                },
                                original_number: function() {
                                    return $('input[name="number"]').data("original-number")
                                },
                                date: function() {
                                    return $('input[name="date"]').val()
                                }
                            }
                        },
                        messages: {
                            remote: app.lang.invoice_number_exists
                        }
                    })
            }
        });

        $("#online_payments_asaas_tab > div:nth-child(13) > div:nth-child(2) > label").html("Valor fixo");

        $("#online_payments_asaas_tab > div:nth-child(13) > div:nth-child(3) > label").html("Porcentagem");

        $("#y_opt_1_Tipo\\ de\\ desconto").change(function() {
            $("#online_payments_asaas_tab > div:nth-child(13) > label").empty();
            $("#online_payments_asaas_tab > div:nth-child(13) > label").html("Valor desconto ");
        });

        $("#y_opt_2_Tipo\\ de\\ desconto").change(function() {
            $("#online_payments_asaas_tab > div:nth-child(13) > label").empty();
            $("#online_payments_asaas_tab > div:nth-child(13) > label").html("Valor desconto (Informar porcentagem)");
        });
    </script>
<?php
}

/**
 * FUNÇÃO CORRIGIDA FINAL: Garante a criação do cliente Asaas e recarrega o objeto da fatura
 * para usar o ID correto, resolvendo o erro 'invalid_customer' e criando a cobrança imediatamente.
 */
function asaas_after_invoice_added($invoice_id)
{
    $CI = &get_instance();
    $CI->load->library('asaas_gateway');
    $CI->load->model('invoices_model');
    // Carrega a fatura inicial
    $invoice = $CI->invoices_model->get($invoice_id);

    // Configurações e API (necessário para criar o cliente)
    $sandbox = $CI->asaas_gateway->getSetting('sandbox');
    if ($sandbox == '0') {
        $api_key = $CI->asaas_gateway->decryptSetting('api_key');
        $api_url = "https://api.asaas.com";
    } else {
        $api_key = $CI->asaas_gateway->decryptSetting('api_key_sandbox');
        $api_url = "https://sandbox.asaas.com";
    }
    $disable_charge_notification = $CI->asaas_gateway->getSetting('disable_charge_notification');
    $notificationDisabled = $disable_charge_notification == '1';

    // Verifica se a fatura existe e não foi cancelada (status 6)
    if ($invoice && $invoice->status != 6) {
        // Verifica se a data de vencimento está definida
        if ($invoice->duedate) {
            $allowed_payment_modes = unserialize($invoice->allowed_payment_modes);

            // 1. Verifica se o Asaas está habilitado para esta fatura
            if (in_array(ASAAS_MODULE_NAME, $allowed_payment_modes)) {

                $client = $invoice->client;
                $clientid = $invoice->client->userid;
                $cliente_id = $client->asaas_customer_id; // Tenta obter o ID existente

                // 2. CRIAÇÃO DO CLIENTE ASAAS, SE AINDA NÃO EXISTIR
                if (!$cliente_id) {
                    $email_client = $CI->asaas_gateway->get_customer_customfields($client->userid, 'customers', 'customers_email_do_cliente');
                    $address_number = $CI->asaas_gateway->get_customer_customfields($client->userid, 'customers', 'customers_numero_do_endere_o');
                    $post_data = json_encode([
                        "name" => $client->company,
                        "email" => $email_client,
                        "cpfCnpj" => $client->vat,
                        "postalCode" => $client->zip,
                        "address" => $client->address,
                        "addressNumber" => $address_number,
                        "complement" => "",
                        "phone" => $client->phonenumber,
                        "mobilePhone" => $client->phonenumber,
                        "externalReference" => $invoice->hash,
                        "notificationDisabled" => $notificationDisabled,
                    ]);

                    $cliente_create = $CI->asaas_gateway->create_customer($api_url, $api_key, $post_data);
                    
                    if (isset($cliente_create['id'])) {
                        $cliente_id = $cliente_create['id'];
                        // Salva o novo ID do Asaas no registro do cliente do Perfex
                        $CI->db->where('userid', $clientid)->update(db_prefix() . 'clients', ['asaas_customer_id' => $cliente_id]);
                        log_activity('Cliente cadastrado no Asaas [Cliente ID: ' . $cliente_id . ']');

                        // CHAVE DA CORREÇÃO: Recarrega a fatura para garantir que o objeto $invoice agora contenha
                        // o asaas_customer_id atualizado no objeto $invoice->client
                        $invoice = $CI->invoices_model->get($invoice_id);

                    } else {
                        log_activity('Erro na função asaas_after_invoice_added: Falha ao criar cliente: ' . json_encode($cliente_create));
                        // Interrompe a criação da cobrança se a criação do cliente falhar
                        return $invoice_id; 
                    }
                }
                
                // 3. CRIAÇÃO DA COBRANÇA (usa o objeto $invoice recarregado ou já existente)
                $billet = $CI->asaas_gateway->charge_billet($invoice);
                
                if (isset($billet['id'])) {
                    $data = array(
                        'asaas_cobranca_id' => $billet['id'],
                    );
                    $CI->db->where('id', $invoice_id);
                    $CI->db->update(db_prefix() . 'invoices', $data);
                } else {
                    log_activity('Erro na função asaas_after_invoice_added: Falha ao criar cobrança: ' . json_encode($billet));
                }
            }
        }
    }
    return $invoice_id;
}


function asaas_after_invoice_updated($invoice)
{
    $invoice_id = $invoice['id'];
    $CI = &get_instance();
    $CI->load->library('asaas_gateway');
    $CI->load->model('invoices_model');
    $sandbox = $CI->asaas_gateway->getSetting('sandbox');
    $debug = $CI->asaas_gateway->getSetting('debug');

    if ($sandbox == '0') {
        $api_key = $CI->asaas_gateway->decryptSetting('api_key');
        $api_url = "https://api.asaas.com";
    } else {
        $api_key = $CI->asaas_gateway->decryptSetting('api_key_sandbox');
        $api_url = "https://sandbox.asaas.com";
    }

    $description      = $CI->asaas_gateway->getSetting('description');
    $billet_only      = $CI->asaas_gateway->getSetting('billet_only');
    $billet_check     = $CI->asaas_gateway->getSetting('billet_check');
    $update_charge    = $CI->asaas_gateway->getSetting('update_charge');

    $disable_charge_notification = $CI->asaas_gateway->getSetting('disable_charge_notification');
    $notificationDisabled = $disable_charge_notification == '1';

    if ($update_charge == 1) {
        $invoice = $CI->invoices_model->get($invoice_id);

        if ($invoice && $invoice->status != 6) {
            $sem_desconto = null;
            $invoice_number = $invoice->prefix . str_pad($invoice->number, 6, "0", STR_PAD_LEFT);
            $description = mb_convert_encoding(str_replace("{invoice_number}", $invoice_number, $description), 'UTF-8', 'ISO-8859-1');

            if ($invoice->duedate) {
                $client = $invoice->client;
                $email_client = $CI->asaas_gateway->get_customer_customfields($client->userid, 'customers', 'customers_email_do_cliente');
                $clientid = $invoice->client->userid;
                $document = str_replace(['/', '-', '.'], '', $client->vat);

                if (!$client->asaas_customer_id) {
                    $address_number = $CI->asaas_gateway->get_customer_customfields($client->userid, 'customers', 'customers_numero_do_endere_o');
                    $post_data = json_encode([
                        "name" => $client->company,
                        "email" => $email_client,
                        "cpfCnpj" => $client->vat,
                        "postalCode" => $client->zip,
                        "address" => $client->address,
                        "addressNumber" => $address_number,
                        "complement" => "",
                        "phone" => $client->phonenumber,
                        "mobilePhone" => $client->phonenumber,
                        "externalReference" => $invoice->hash,
                        "notificationDisabled" => $notificationDisabled,
                    ]);

                    $cliente_create = $CI->asaas_gateway->create_customer($api_url, $api_key, $post_data);
                    if (isset($cliente_create['id'])) {
                        $cliente_id = $cliente_create['id'];
                        $CI->db->where('userid', $clientid)->update(db_prefix() . 'clients', ['asaas_customer_id' => $cliente_id]);
                        log_activity('Cliente cadastrado no Asaas [Cliente ID: ' . $cliente_id . ']');
                    } else {
                        log_activity('Erro na função asaas_after_invoice_updated: Falha ao criar cliente: ' . json_encode($cliente_create));
                        return;
                    }
                } else {
                    $cliente_id = $client->asaas_customer_id;
                }

                $allowed_payment_modes = unserialize($invoice->allowed_payment_modes);
                if (in_array('asaas', $allowed_payment_modes)) {
                    $CI->asaas_gateway->listar_todas_cobrancas_e_atualizar($api_url, $api_key, $invoice->hash);
                    $invoice = $CI->invoices_model->get($invoice_id);
                    $charge = $CI->asaas_gateway->recuperar_uma_unica_cobranca($invoice->asaas_cobranca_id);

                    if ($charge) {
                        $payment_method = get_invoice_customfield($invoice->id, 'invoice_forma_de_pagamento');
                        $post_data = json_encode([
                            "customer" => $cliente_id,
                            "billingType" => "$payment_method" ?: "BOLETO",
                            "dueDate" => $invoice->duedate,
                            "value" => $invoice->total,
                            "description" => $description,
                            "externalReference" => $invoice->hash,
                            "postalService" => false
                        ]);
                        $update_charge = $CI->asaas_gateway->update_charge($invoice->asaas_cobranca_id, $post_data);
                    } else {
                        $billet = $CI->asaas_gateway->charge_billet($invoice);
                    }
                }
            }
        }
    }
}

function get_invoice_customfield($invoice_id, $field_slug)
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

function asaas_before_invoice_deleted($id)
{
    $CI = &get_instance();
    $CI->load->library('asaas_gateway');
    $CI->load->model('invoices_model');
    $delete_charge = $CI->asaas_gateway->getSetting('delete_charge');

    if ($delete_charge == 1) {
        $invoice = $CI->invoices_model->get($id);
        $charges = $CI->asaas_gateway->get_charge2($invoice->hash);

        if ($charges) {
            foreach ($charges as $charge) {
                $response = $CI->asaas_gateway->delete_charge($charge->id);
                if ($response && !isset($response['errors'])) {
                    log_activity('Cobrança removida Asaas [Fatura ID: ' . $charge->id . ']');
                } else {
                    log_activity('Erro na função asaas_before_invoice_deleted: Falha ao remover cobrança: ' . json_encode($response));
                }
            }
        }
    }
    return $id;
}

/**
 * Adiciona o widget do Asaas ao dashboard
 * @param  array $widgets
 * @return array
 */
function asaas_add_dashboard_widget($widgets) {
    // Verifica se o usuário tem permissão para visualizar o widget
    if (has_permission('asaas_dashboard', '', 'view')) {
        $widgets[] = [
            'path' => 'asaas/dashboard/asaas_saldo',
            'container' => 'left-8',
        ];
    }
    return $widgets;
}