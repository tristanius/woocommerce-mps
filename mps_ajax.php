<?php

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

//js ajax scripts enqueue
add_action('admin_enqueue_scripts', 'mps_ajax_admin');

function mps_ajax_admin()
{
    if (!is_admin()) return;

    wp_register_script(
        'mps_ajax_admin',
        plugin_dir_url(__FILE__) . 'js/mpsAdminAjax.js',
        ['jquery'],
        filemtime(plugin_dir_path(__FILE__) . 'js/mpsAdminAjax.js'),
        true
    );

    wp_localize_script(
        'mps_ajax_admin',
        'mps_ajax',
        [
            'url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mps_ajax_admin')
        ]
    );

    wp_enqueue_script('mps_ajax_admin');
}

//hooks functions used with ajax
add_action('wp_ajax_mps_create_products', 'mps_ajax_create_products');
add_action('wp_ajax_mps_sync_products', 'mps_ajax_sync_products');
add_action('wp_ajax_mps_get_token', 'mps_ajax_get_token');
add_action('wp_ajax_mps_create_order', 'mps_ajax_create_order');
add_action('wp_ajax_mps_start_create_report_prices', 'mps_ajax_start_create_report_prices');
add_action('wp_ajax_mps_update_categories_percentages', 'mps_ajax_update_categories_percentages');
add_action('wp_ajax_mps_update_uvts', 'mps_ajax_update_uvts');
add_action('wp_ajax_mps_update_iva', 'mps_ajax_update_iva');
add_action('wp_ajax_mps_update_min_price', 'mps_ajax_update_min_price');


function mps_ajax_create_products()
{
    check_ajax_referer('mps_ajax_admin', 'nonce');

    ignore_user_abort(true);
    ob_start();
    header("Connection: close");
    header("Content-Length: " . ob_get_length());
    ob_end_flush();
    flush();

    $mps = new Distributor_MPS();

    try {
        mps_log("iniciando creación de productos", 0);
        $mps->create_all_products();
        add_option("mps all products created", true);
        mps_log("Finalizo la creación de todos los productos", 0);
    } catch (Exception $e) {
        mps_email_notification("Error realizando petición ajax (Crear todos los productos): " . $e->getMessage(), 2);
        mps_log("Error realizando petición ajax (Crear todos los productos): " . $e->getMessage(), 2);
    }

    unset($mps);
    wp_die();
}

function mps_ajax_sync_products()
{
    check_ajax_referer('mps_ajax_admin', 'nonce');

    ignore_user_abort(true);
    ob_start();
    header("Connection: close");
    header("Content-Length: " . ob_get_length());
    ob_end_flush();
    flush();

    $mps = new Distributor_MPS();

    try {
        mps_log("iniciando actualización de productos", 0);
        $mps->update_all_products();
        mps_log("se han actualizados todos los productos", 0);
    } catch (Exception $e) {
        mps_email_notification("Error realizando petición ajax (Crear todos los productos): " . $e->getMessage(), 2);
        mps_log("Error realizando petición ajax (Crear todos los productos): " . $e->getMessage(), 2);
    }
    unset($mps);
    wp_die();
}

function mps_ajax_get_token()
{
    check_ajax_referer('mps_ajax_admin', 'nonce');

    try {
        $mps = new Distributor_MPS();
        $mps->get_api_token();
        $message = "Se ha actualizado el token";
        mps_log($message, 0);
        wp_send_json_success($message);
    } catch (Exception $e) {
        $message = "Error realizando petición ajax: obtener token mps " . $e->getMessage();
        mps_email_notification($message, 2);
        mps_log($message, 2);
        wp_send_json_error($message);
    }
    unset($mps);
}

function mps_ajax_create_order()
{
    check_ajax_referer('mps_ajax_admin', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('No tienes permiso.');
    }

    $order_id = intval($_POST['order_id']);
    $order = wc_get_order($order_id);

    $order_meta = get_post_meta($order_id, "mps_order", true);
    $mps = new Distributor_MPS();
    $response = $mps->create_order($order_id, $order_meta["mps_order"]);
    if ($response) {
        $message = "Se ha creado el pedido MPS #" . $response;
        $order->add_order_note($message);
        mps_log($message, 1);
        wp_send_json_success($message);
    } else {
        wp_send_json_error('No se ha podido crear el pedido MPS, por favor intentalo mas tarde.');
    }
}

function mps_ajax_start_create_report_prices()
{
    check_ajax_referer('mps_ajax_admin', 'nonce');
    ignore_user_abort(true);
    ob_start();
    header("Connection: close");
    header("Content-Length: " . ob_get_length());
    ob_end_flush();
    flush();

    // Agregar tarea a la cola (puedes usar un marcador o no usarlo)
    $mps = new Distributor_MPS();
    $mps->create_report_prices();

    wp_die();
}

function mps_ajax_update_categories_percentages()
{
    check_ajax_referer('mps_ajax_admin', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('No tienes permiso.');
    }

    $percentage_category = $_POST['percentage_category'];
    update_option('mps_percentage_category', $percentage_category);

    foreach ($percentage_category as $category_info) {
        publish_unpublish_category($category_info);
    }

    mps_log('Categoria actualizadas', 0);

    wp_send_json_success('Se ha actualizado los porcentajes');
}

function mps_ajax_update_uvts()
{
    check_ajax_referer('mps_ajax_admin', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('No tienes permiso.');
    }

    $uvts = $_POST['uvts'];
    update_option('uvts', $uvts);
    wp_send_json_success('Se ha actualizado la información: UVTS');
}

function mps_ajax_update_iva()
{
    check_ajax_referer('mps_ajax_admin', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('No tienes permiso.');
    }

    $iva = $_POST['iva'];
    update_option('iva', $iva);
    wp_send_json_success('Se ha actualizado la información: IVA');
}

function mps_ajax_update_min_price()
{
    check_ajax_referer('mps_ajax_admin', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('No tienes permiso.');
    }

    $min_price = $_POST['mps_min_price'];
    update_option('mps_min_price', $min_price);
    wp_send_json_success('Se ha actualizado la información: Precio minimo');
}
