<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Teste extends AdminController
{

    public function __construct()
    {
        parent::__construct();
        $this->load->library("asaas/base_api");
    }
}
