<?php
/*
 * Plugin Name:       MPS api
 * Description:       Handle MPS api.
 * Version:           2.1.0
 * Author:            Andres Bermudez
 * Author URI:        https://github.com/andresleonardobg
 * Text Domain:       mps-api
 * Requires Plugins:  WooCommerce
 */

if (! defined('ABSPATH')) {
    exit;
}

include_once plugin_dir_path(__FILE__) . "mps-api.php";
include_once plugin_dir_path(__FILE__) . "mps_wc.php";
include_once plugin_dir_path(__FILE__) . "mps_cron.php";
include_once plugin_dir_path(__FILE__) . "mps_ajax.php";

if (file_exists(plugin_dir_path(__FILE__) . "mps_test.php")) {
    include_once plugin_dir_path(__FILE__) . "mps_test.php";
}

/**
 * Constants
 */
define('MPS_LOG', plugin_dir_path(__FILE__) . 'mps.log');
define('MPS_PREFIX', 'KT01-');

/**
 * activation/deactivation plugin
 */
register_activation_hook(__FILE__, 'mps_activation');
register_deactivation_hook(__FILE__, 'mps_deactivation');

function mps_activation()
{
    //active cron hooks
    if (!wp_next_scheduled('mps_cron_sync_hook')) {
        wp_schedule_event(time(), 'hourly', 'mps_cron_sync_hook');
    }

    if (!wp_next_scheduled('mps_cron_check_conection_hook')) {
        wp_schedule_event(time(), 'twicedaily', 'mps_cron_check_conection_hook');
    }

/** UVT 2025 */

    add_option("uvts_value", 49799);
    add_option("iva", 19);
    add_option("mps all products created", false);
}

function mps_deactivation()
{
    //delete cron hooks
    $timestamp_sync = wp_next_scheduled('mps_cron_sync_hook');
    if ($timestamp_sync) {
        wp_unschedule_event($timestamp_sync, 'mps_cron_sync_hook');
    }

    $timestamp_connection = wp_next_scheduled('mps_cron_check_conection_hook');
    if ($timestamp_connection) {
        wp_unschedule_event($timestamp_connection, 'mps_cron_check_conection_hook');
    }

    delete_option("mps all products created");
}

/**
 * MPS admin menu
 */
add_action('admin_menu', 'mps_admin_menu');

function mps_admin_menu()
{
    if (current_user_can('manage_woocommerce')) {
        add_menu_page(
            'MPS',
            'MPS',
            'manage_woocommerce',
            'mps-api',
            'mps_get_view_admin',
            'dashicons-archive'
        );
        
        // ** NUEVA FUNCIÓN: Agregar el submenú para el listado de productos **
        add_submenu_page(
            'mps-api',
            'Productos MPS',
            'Productos MPS',
            'manage_woocommerce',
            'mps-products-list',
            'mps_get_products_list_view'
        );
    }
}

function mps_get_view_admin()
{
    include_once 'mps_view_admin.php';
}

// ** NUEVA FUNCIÓN: Controlador para la vista del listado de productos **
function mps_get_products_list_view()
{
    //include_once plugin_dir_path(__FILE__) . 'mps_view_products_list.php';
    include_once 'mps_view_products_list.php';
}

/**
 * logs the main plugin processes
 *
 * @param $message process to register
 *
 * @param int $type type of register: INFO(0), WARNING(1), ERROR(2)
 */
function mps_log($message, int $type)
{
    date_default_timezone_set('America/Bogota');

    $message_type = ["INFO", "WARNING", "ERROR",];
    $local_date = date('Y-m-d H:i:s');
    $separator = "------------------------";
    $final_message = $separator . PHP_EOL . $message_type[$type] . " [" . $local_date . "]" . PHP_EOL . $message . PHP_EOL . $separator . PHP_EOL;

    if (file_exists(MPS_LOG)) {
        $log = fopen(MPS_LOG, 'a') or exit("No se puede abrir el archivo de log");
        fwrite($log, $final_message);
        fclose($log);
    } else {
        if (false !== $fp = fopen(MPS_LOG, 'w')) {
            fwrite($fp, $final_message);
            fclose($fp);
        } else {
            echo "Error al crear el archivo de log.";
        }
    }
}

/**
 * Get and show log content
 * 
 * @return void
 */
function mps_get_content_log()
{
    if (file_exists(MPS_LOG)) {
        echo esc_html(file_get_contents(MPS_LOG));
    } else {
        echo 'El archivo de log no existe.';
    }
}

/**
 * Send email related to MPS plugin critical errors or new orders created
 * 
 * @param string $message_to_send
 * 
 * @param int type of message : INFO(0), WARNING(1), ERROR(2)
 * 
 * @return void
 */
function mps_email_notification(string $message_to_send, int $type)
{
    // $to = 'marketing@kennertech.com.co, soporte@kennertech.com.co';
    switch ($type) {
        case 0:
            $subject = 'Informaci贸n MPS plugin';
            break;
        case 1:
            $subject = 'Advertencia MPS plugin';
            break;
        case 2:
            $subject = 'Error MPS plugin';
            break;
    }
    $to = 'marketing@kennertech.com.co';
    $subject = 'MPS plugin Errores';
    $message = $message_to_send;
    $headers = array('Content-Type: text/html; charset=UTF-8');

    if (wp_mail($to, $subject, $message, $headers)) {
        mps_log('El correo ha sido enviado.', 0);
    } else {
        mps_log("Hubo un problema al enviar el correo.", 2);
    }
}

/**
 * Created csv file that shows the price adjustment process
 * 
 * @param array $info
 * 
 * @return void
 */
function mps_report_prices(array $info)
{
    $file = plugin_dir_path(__FILE__) . 'reporte_compras.csv';
    $method = file_exists($file) ? 'a' : 'w'; // 'a' para agregar, 'w' para crear
    $handler = fopen($file, $method);
    $headers = [
        "sku",
        "nombre del producto",
        "categoria",
        "valor mps",
        "porcentaje kenner",
        "valor del porcentaje kenner",
        "valor mps + %kenner",
        "aplica uvts",
        "excento de iva",
        "Clasificaci贸n tributaria",
        "valor de iva: 19%",
        "precio en pagina"
    ];

    array_unshift($info, $headers);

    if ($handler !== false) {
        foreach ($info as $key => $row) {
            fputcsv($handler, $row, ";");
        }
        fclose($handler);
    } else {
        mps_log("no se ha podido crear o modificar el reporte csv", 2);
    }

    unset($info, $file, $method, $handler);
}

function get_all_products_in_page()
{
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
    );

    $query = new WP_Query($args);
    $products_info = [];

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $product_id = get_the_ID();
            $product = wc_get_product($product_id);

            // Almacenar SKU en el array
            if ($product->get_sku()) {
                $products_info[] = [
                    'id' => $product_id,
                    'sku' => str_replace('KT01-', '', $product->get_sku()),
                ];
            }
        }
    }

    wp_reset_postdata();

    return $products_info;
}

function publish_unpublish_category($categorie_info)
{
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
        'tax_query' => array(
            array(
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => $categorie_info['id'],
            ),
        ),
    );

    $products = new WP_Query($args);

    if ($products->have_posts()) {
        foreach ($products->posts as $post) {
            if ($post->post_status === 'publish' && (int)$categorie_info['value'] === 0) {
                wp_update_post(
                    array(
                        'ID'          => $post->ID,
                        'post_status' => 'draft',
                    )
                );
            }

            if ($post->post_status === 'draft' && (int)$categorie_info['value'] !== 0) {
                wp_update_post(
                    array(
                        'ID'          => $post->ID,
                        'post_status' => 'publish',
                    )
                );
            }
        }
        wp_reset_postdata();
    } else {
        mps_log('No se encontraron productos en esta categor铆a.', 0);
    }
}

function delete_products($mps_products_info)
{
    $mps_products = $mps_products_info;
    $page_products = get_all_products_in_page();

    foreach ($page_products as $product) {
        $index = array_search($product['sku'], array_column($mps_products, 'Sku'));
        if (!$index) {
            $product_in_page = wc_get_product($product['id']);
            if ($product_in_page) {

                $update_product = array(
                    'ID'          => $product['id'],
                    'post_status' => 'draft', // Cambiar el estado a 'draft'
                );

                $result = wp_update_post($update_product);
                update_post_meta($product['id'], '_stock', 0);
                update_post_meta($product['id'], '_stock_status', 'outofstock');

                if ($result) {
                    mps_log('Producto ' . $product['sku'] . ' cambiado a borrador porque ya no se encuentra en la API.', 0);
                } else {
                    mps_log('Error al pasar el producto. ' . $product['sku'] . ' a borrador.', 0);
                }
            } else {
                mps_log('El producto ' . $product['sku'] . ' no existe.', 0);
            }
        }
    }
}
