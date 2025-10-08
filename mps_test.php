<?php
function get_data_test($file_path)
{
    // Example: /webmaster/MPS/test_product.json
    $json_file = plugin_dir_path(__FILE__) . $file_path;

    if (file_exists($json_file)) {

        $contents = file_get_contents($json_file);
        $data = json_decode($contents, true);

        return $data["listaproductos"];
    } else {
        // Handle the error if the file does not exist
        echo "Archivo " . $file_path . " no existe.";
    }
}

//AJAX
add_action('wp_ajax_mps_test_create_products', 'mps_ajax_test_create_products');
add_action('wp_ajax_mps_test_create_order', 'mps_ajax_test_create_order');

function mps_ajax_test_create_products()
{
    check_ajax_referer('mps_ajax_admin', 'nonce');
    //wp_send_json_success('Productos encolados para creación.');

    ignore_user_abort(true);
    ob_start();
    header("Connection: close");
    header("Content-Length: " . ob_get_length());
    ob_end_flush();
    flush();

    $mps = new Distributor_MPS();

    try {
        mps_log("iniciando creación de productos test", 0);
        $mps->test_create_all_products();
        mps_log("Finalizo la creación de todos los productos test", 0);
    } catch (Exception $e) {
        mps_email_notification("Error realizando petición ajax (Crear todos los productos): " . $e->getMessage(), 2);
        mps_log("Error realizando petición ajax (Crear todos los productos): " . $e->getMessage(), 2);
    }
    unset($mps);
    wp_die();
}

function mps_ajax_test_create_order()
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
        mps_log("iniciando creación de pedido", 0);
        $prueba = [
            "ClienteEntrega"       => "79580718",
            "TelefonoEntrega"      => "9999999",
            "DireccionEntrega"     => "Calle 1",
            "StateId"              => "11",
            "CountyId"             => "001",
            "RecogerEnSitio"       => 0,
            "EntregaUsuarioFinal"  => 1,
            "dlvTerm"              => "",
            "dlvmode"              => "",
            "Observaciones"        => "Pedido Web Nro.",
            "listaPedidoDetalle"   => [
                [
                    "PartNum" => "Archer T2U",
                    "Cantidad" => 1,
                    "Marks" => "TPLINK",
                    "Bodega" => "BCOTA"
                ],
                [
                    "PartNum" => "Archer T2U",
                    "Cantidad" => 1,
                    "Marks" => "TPLINK",
                    "Bodega" => "BCOTA"
                ]
            ]
        ];
        $state = $mps->create_order($prueba);
        mps_log("pedido mps " . json_encode($state), 0);
    } catch (Exception $e) {
        mps_email_notification("Error realizando petición ajax (Crear todos los productos): " . $e->getMessage(), 2);
        mps_log("Error realizando petición ajax (Crear todos los productos): " . $e->getMessage(), 2);
    }
    unset($mps);
    wp_die();
}

// functions based in Distributor_MPS
function test_create_all_products()
{
    $products = get_data_test("/test_files/test_product.json");
    foreach ($products as $product) {
        try {
            $mps = new Distributor_MPS();
            $mps->create_product($product);
            wc_delete_product_transients();
        } catch (Exception $e) {
            mps_log("Error al crear producto: " . $e->getMessage(), 0);
        }
        unset($product);
    }

    mps_log("Función test_create_all_products finalizada", 0);
}

function test_get_product($sku_product)
{
    $products = get_data_test("/test_files/test_product.json");

    $sku_product = substr($sku_product, strlen($this->mps_prefix));

    $mps = new Distributor_MPS();
    $product = $mps->find_product_by_sku($products, $sku_product);

    mps_log("Función test_get_product", 0);

    return $product;
}
