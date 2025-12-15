<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="kt-container kt-grid__item kt-grid__item--fluid mt-3">
    <div class="col-lg-12">
        <div class="kt-portlet" id="kt_portlet">
            <div class="kt-portlet__body"><?php echo validation_errors('<div class="alert alert-danger text-center">', '</div>'); ?>
                <div class="mtop15 preview-top-wrapper">
                    <div class="top" data-sticky data-sticky-class="preview-sticky-header">
                        <?php if (is_invoice_overdue($invoice)) { ?>
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="text-center text-white danger-bg">
                                        <h4><?php echo _l('overdue_by_days', get_total_days_overdue($invoice->duedate)) ?></h4>
                                    </div>
                                </div>
                            </div>
                        <?php } ?>


                        <div class="col-md-12">
                            <div class="pull-left">
                                <h4 class="bold no-mtop invoice-html-number no-mbot float-left" style="margin-right: 20px">
                                    <?php echo format_invoice_number($invoice->id); ?>
                                </h4>
                                <h4 class="invoice-html-status float-left">
                                    <?php echo format_invoice_status($invoice->status, '', true); ?>
                                </h4>
                            </div>
                            <div class="visible-xs">
                                <div class="clearfix"></div>
                            </div>
                            <a href="<?= site_url('invoice/' . $invoice->id . '/' . $invoice->hash); ?>" class="btn btn-info pull-right mtop5 mright5 action-button go-to-portal">
                                Voltar a fatura
                            </a>
                            <!--
                            <?php if (is_client_logged_in() && has_contact_permission('invoices')) { ?>
                                <a href="<?php echo site_url('clients/invoices/'); ?>"
                                   class="btn btn-default pull-right mtop5 mright5 action-button go-to-portal"> <?php echo _l('client_go_to_dashboard'); ?> </a>
                            <?php } ?>
                            -->
                            <div class="clearfix"></div>
                        </div>

                        <style>
                            .payment-widget-container {
                                max-width: 900px;
                                margin: 40px auto;
                                padding: 20px;
                            }
                            
                            .payment-widget-title {
                                text-align: center;
                                color: #333;
                                font-size: 24px;
                                font-weight: 600;
                                margin-bottom: 40px;
                            }
                            
                            .payment-options-grid {
                                display: flex;
                                justify-content: center;
                                gap: 20px;
                                flex-wrap: wrap;
                            }
                            
                            .payment-option-card {
                                flex: 1;
                                min-width: 250px;
                                max-width: 280px;
                                background: #ffffff;
                                border: 2px solid #e0e0e0;
                                border-radius: 12px;
                                padding: 40px 20px;
                                text-align: center;
                                transition: all 0.3s ease;
                                text-decoration: none;
                                display: block;
                                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
                            }
                            
                            .payment-option-card:hover {
                                transform: translateY(-8px);
                                box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
                                border-color: #4CAF50;
                                text-decoration: none;
                            }
                            
                            .payment-option-icon {
                                font-size: 80px;
                                color: #4CAF50;
                                margin-bottom: 20px;
                                transition: all 0.3s ease;
                            }
                            
                            .payment-option-card:hover .payment-option-icon {
                                color: #45a049;
                                transform: scale(1.1);
                            }
                            
                            .payment-option-title {
                                font-size: 20px;
                                font-weight: 600;
                                color: #333;
                                margin: 0;
                            }
                            
                            .payment-option-description {
                                font-size: 14px;
                                color: #666;
                                margin-top: 8px;
                            }
                            
                            @media (max-width: 768px) {
                                .payment-options-grid {
                                    flex-direction: column;
                                    align-items: center;
                                }
                                
                                .payment-option-card {
                                    width: 100%;
                                    max-width: 100%;
                                }
                                
                                .payment-widget-title {
                                    font-size: 20px;
                                }
                            }
                        </style>

                        <div class="payment-widget-container">
                            <h2 class="payment-widget-title">Selecione uma das opções abaixo para realizar o pagamento</h2>
                            
                            <div class="payment-options-grid">
                                <?php if ($billet_only == 1) { ?>
                                    <a href="<?php echo admin_url('asaas/checkout/boleto/' . $hash) ?>" class="payment-option-card">
                                        <i class="fa fa-barcode payment-option-icon"></i>
                                        <p class="payment-option-title">Boleto Bancário</p>
                                        <p class="payment-option-description">Pagamento à vista com vencimento</p>
                                    </a>
                                <?php } ?>
                                
                                <?php if ($card_only == 1) { ?>
                                    <a href="<?php echo admin_url('asaas/checkout/cartao/' . $hash) ?>" class="payment-option-card">
                                        <i class="fa fa-credit-card payment-option-icon"></i>
                                        <p class="payment-option-title">Cartão de Crédito</p>
                                        <p class="payment-option-description">Parcelamento disponível</p>
                                    </a>
                                <?php } ?>
                                
                                <?php if ($pix_only == 1) { ?>
                                    <a href="<?php echo admin_url('asaas/checkout/qrcode/' . $hash) ?>" class="payment-option-card">
                                        <i class="fa fa-qrcode payment-option-icon"></i>
                                        <p class="payment-option-title">PIX</p>
                                        <p class="payment-option-description">Aprovação instantânea</p>
                                    </a>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
