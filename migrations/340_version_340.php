<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Version_340 extends App_module_migration
{
    public function up()
    {
        $CI = &get_instance();

        // Dados a serem inseridos
        $customfields = [
            [
                "fieldto" => "customers",
                "name" => "Numero do endereço",
                "slug" => "customers_numero_do_endere_o",
                "required" => "0",
                "type" => "input",
                "options" => "",
                "display_inline" => "0",
                "field_order" => "0",
                "active" => "1",
                "show_on_pdf" => "0",
                "show_on_ticket_form" => "0",
                "only_admin" => "0",
                "show_on_table" => "0",
                "show_on_client_portal" => "0",
                "disalow_client_to_edit" => "0",
                "bs_column" => "12",
                "default_value" => "",
                "perfex_saas_tenant_id" => "master",
            ],
            [
                "fieldto" => "customers",
                "name" => "Email do cliente",
                "slug" => "customers_email_do_cliente",
                "required" => "0",
                "type" => "input",
                "options" => "",
                "display_inline" => "0",
                "field_order" => "0",
                "active" => "1",
                "show_on_pdf" => "0",
                "show_on_ticket_form" => "0",
                "only_admin" => "0",
                "show_on_table" => "0",
                "show_on_client_portal" => "0",
                "disalow_client_to_edit" => "0",
                "bs_column" => "12",
                "default_value" => "",
                "perfex_saas_tenant_id" => "master",
            ],
            [
                "fieldto" => "invoice",
                "name" => "Forma de pagamento",
                "slug" => "invoice_forma_de_pagamento",
                "required" => "1",
                "type" => "select",
                "options" => "BOLETO, PIX, CREDIT_CARD,UNDEFINED",
                "display_inline" => "0",
                "field_order" => "0",
                "active" => "1",
                "show_on_pdf" => "0",
                "show_on_ticket_form" => "0",
                "only_admin" => "0",
                "show_on_table" => "1",
                "show_on_client_portal" => "0",
                "disalow_client_to_edit" => "0",
                "bs_column" => "6",
                "default_value" => "",
                "perfex_saas_tenant_id" => "master",
            ],
        ];

        // Inserir registros se não existirem
        foreach ($customfields as $field) {
            $slug = $field['slug'];

            // Verifica se o registro já existe no banco
            $row = $CI->db
                ->where("slug", $slug)
                ->get(db_prefix() . "customfields")
                ->row();

            // Insere o registro se não existir
            if (is_null($row)) {
                $CI->db->insert(db_prefix() . "customfields", $field);
            }
        }
    }
}
