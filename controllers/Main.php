<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Main extends ClientsController
{
    protected $apiKey;
    protected $apiUrl;

    public function __construct()
    {
        parent::__construct();
        $this->load->library('asaas_gateway');
        $this->load->library('base_api');
        $this->load->helper('general');
        $this->apiKey  = $this->base_api->getApiKey();
        $this->apiUrl  = $this->base_api->getUrlBase();
    }

    public function index()
    {
        $post_data = json_encode(["type" => "EVP"]);

        $minhas_chaves = $this->create_key($this->apiUrl, $this->apiKey, $post_data);

        var_dump($minhas_chaves);
    }

    public function create_key($api_url, $api_key, $post_data)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url . "/v3/pix/addressKeys");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "access_token: " . $api_key,
        ));
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code >= 200 && $http_code < 300) {
            log_activity('Chave PIX criada com sucesso: ' . $response);
            return json_decode($response);
        } else {
            log_activity('Erro ao criar chave PIX: ' . $response);
            return false;
        }
    }

    public function list_keys($api_url, $api_key)
    {
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $api_url . "/v3/pix/addressKeys",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "access_token: " . $api_key,
            ),
        ));
        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($http_code >= 200 && $http_code < 300) {
            log_activity('Listagem de chaves PIX obtida com sucesso: ' . $response);
            return json_decode($response);
        } else {
            log_activity('Erro ao listar chaves PIX: ' . $response);
            return false;
        }
    }

    public function get_key($api_url, $api_key, $id)
    {
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $api_url . "/v3/pix/addressKeys/" . $id,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "access_token: " . $api_key,
            ),
        ));
        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($http_code >= 200 && $http_code < 300) {
            log_activity('Chave PIX obtida com sucesso: ' . $response);
            return json_decode($response);
        } else {
            log_activity('Erro ao obter chave PIX: ' . $response);
            return false;
        }
    }

    public function delete_key($api_url, $api_key, $id)
    {
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $api_url . "/v3/pix/addressKeys/" . $id,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "DELETE",
            CURLOPT_HTTPHEADER => array(
                "access_token: " . $api_key,
            ),
        ));
        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($http_code >= 200 && $http_code < 300) {
            log_activity('Chave PIX deletada com sucesso: ' . $response);
            return json_decode($response);
        } else {
            log_activity('Erro ao deletar chave PIX: ' . $response);
            return false;
        }
    }
}