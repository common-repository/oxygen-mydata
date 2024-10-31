/**
 * Oxygen Payment Js code for handling modal windows of payment
 *
 * @package Oxygen Payments
 * @version 1.0.0
 * @author Oxygen
 * @since 1.0.0
 *
 * This file handle oxygen payment window
 */


jQuery( document ).ready(

    function ( $ ) {


        $('#billing_vat_field span.woocommerce-input-wrapper').append('<div id="vat-check-button" ><div class="loader_vat"></div><img class="div_vat" src="' + window.location.origin + '/wp-content/plugins/oxygen-mydata/assets/icon-search.png" alt="Check VAT"/></div>');

        function check_if_is_europe_country(selected_country){

            var validEUCountries = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI'];

            if (validEUCountries.includes(selected_country) || selected_country === 'GR') {
                $('#vat-check-button').show();
            }else{
                $('#vat-check-button').hide();
            }
        }

        /* check vies */
        var selected_country = $('#billing_country option:selected').val();
        check_if_is_europe_country(selected_country);

        $( 'input#billing_invoice' ).on('change', function () {
            /* vies check vat */
            selected_country = $('#billing_country option:selected').val();
            check_if_is_europe_country(selected_country);
        });

        $( '#billing_country' ).on('change', function () {
            /* vies check vat */
            selected_country = $('#billing_country option:selected').val();
            check_if_is_europe_country(selected_country);
        });


        var vat_number = '';

        var height_billing_vat = $('#billing_vat').outerHeight();
        let btn_h = height_billing_vat - 10;
        $('#vat-check-button').css('height',btn_h);
        $('#vat-check-button').css('width',btn_h);

        $('input#billing_vat').on('keyup', function() {
            vat_number = $(this).val();  // Get the current value of the input field
            if(vat_number === ''){
                $('#billing_job').attr('value', '');
                $('#billing_tax_office').attr('value', '');
                $('#billing_company').attr('value', '');
            }
            $('#billing_vat').attr('value',vat_number);
        });

        function check_vat_ajax(requestData){

            if (vat_number !== '') {

                $('#billing_vat').removeClass('error_border');
                $('.woocommerce-notices-wrapper.vat_field').remove();


                $.ajax({
                    type: 'POST',
                    url: wc_checkout_params.ajax_url, // WooCommerce's default AJAX URL
                    data: requestData,
                    beforeSend: function() {
                        // setting a timeout
                        $('.loader_vat').addClass('loading_vat');
                        $('.div_vat').hide();
                    },
                    success: function (response) {

                        $('.loader_vat').removeClass('loading_vat');
                        $('.div_vat').show();

                        var data = response['data'];
                        let afm = '';
                        let company_name = '';
                        let doy = '';
                        let job = '';

                        var kad_data = data['firms'];

                        if(data !== undefined && data['code'] === undefined){

                            afm = data['vatNumber'];
                            if(afm !== '') {
                                company_name = data['legalName'];
                                doy = data['taxAuthorityName'];

                                if (kad_data !== undefined && kad_data.length > 0) {
                                    job = kad_data[0]['description'];
                                }

                                $('#billing_job').attr('value', job);
                                $('#billing_tax_office').attr('value', doy);
                                $('#billing_company').attr('value', company_name);
                            }

                        }else{

                            $('#billing_job').attr('value', '');
                            $('#billing_tax_office').attr('value', '');
                            $('#billing_company').attr('value', '');

                            if(data[0]['message'] === '' && data['code'] !== ''){
                                $('#billing_vat_field').after('<div class="woocommerce-notices-wrapper vat_field">\n' +
                                    '    <ul class="woocommerce-error"><div class="woocommerce-invalid woocommerce-invalid-required-field">Κάτι πήγε στραβά.</div></ul></div>');
                            }else if(data['code'] !== '' && data[0]['message'] !== ''){
                                $('#billing_vat_field').after('<div class="woocommerce-notices-wrapper vat_field">\n' +
                                    '    <ul class="woocommerce-error"><div class="woocommerce-invalid woocommerce-invalid-required-field" style="font-size:14px;">' + data[0]['message'] +'</div></ul></div>');
                            }

                        }
                    },
                    error: function (error) {
                        console.log(error);
                        $('.loader_vat').removeClass('loading_vat');
                        $('.div_vat').show();
                    }
                });
            }else{
                $('#billing_vat').addClass('error_border');

            }
        }




        $('#vat-check-button').on('click', function () {

            var requestData = {
                action: 'check_vat_action',
                vat_number: vat_number
            };

            if(selected_country === 'GR'){

                check_vat_ajax(requestData);
                console.log('simple vat');

            }else {

                requestData = {
                    action: 'check_vat_action',
                    vat_number: vat_number,
                    country_code: selected_country
                };
                check_vat_ajax(requestData);
                console.log('search in vies');

            }

        });
    }
);