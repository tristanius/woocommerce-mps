<?php

/**
 * scheduled functions
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

//sync products
function sync_products()
{
    $mps = new Distributor_MPS();

    if (get_option("mps all products created")) {
        $mps->update_all_products();
    }

    mps_log("Tarea ejecutada: Actualización de productos MPS por hora", 0);
    unset($mps);
}

add_action('mps_cron_sync_hook', 'sync_products');

//get token and check connection
function get_check_token_connection()
{
    $mps = new Distributor_MPS();
    try {
        $mps->get_api_token();
    } catch (Exception $e) {
        mps_log("Error al obtener el token con CRON " . $e->getMessage(), 0);
    }

    unset($mps);

    mps_log("Tarea ejecutada: Actualización de token MPS api", 0);
}

add_action('mps_cron_check_conection_hook', 'get_check_token_connection');
