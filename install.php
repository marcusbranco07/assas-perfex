<?php
$CI = &get_instance();
$CI->load->database();

try {
    if (!$CI->db->field_exists('asaas_customer_id', db_prefix() . 'clients')) {
        $CI->db->query('ALTER TABLE `' . db_prefix() . 'clients` ADD `asaas_customer_id` VARCHAR(255) NULL DEFAULT NULL;');
        log_activity('Campo `asaas_customer_id` adicionado na tabela `clients`.');
    } else {
        log_activity('Campo `asaas_customer_id` já existe na tabela `clients`.');
    }

    if (!$CI->db->field_exists('asaas_cobranca_id', db_prefix() . 'invoices')) {
        $CI->db->query('ALTER TABLE `' . db_prefix() . 'invoices` ADD `asaas_cobranca_id` VARCHAR(255) NULL DEFAULT NULL;');
        log_activity('Campo `asaas_cobranca_id` adicionado na tabela `invoices`.');
    } else {
        log_activity('Campo `asaas_cobranca_id` já existe na tabela `invoices`.');
    }
} catch (Exception $e) {
    log_activity('Erro ao adicionar campos nas tabelas: ' . $e->getMessage());
}
